<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Services RH, étape 3 : contrats de travail.
 *
 * Ce qui compte :
 *  - chaque employé naît avec son contrat initial ;
 *  - un seul contrat en vigueur à la fois, et la fiche employé le reflète ;
 *  - un contrat mis en vigueur ne se réécrit plus (avenant) ni ne se supprime ;
 *  - le contrat signé reste privé à l'entreprise.
 *
 * Horloge figée au jeudi 10 septembre 2026, disque privé simulé.
 */
class EmployeeContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-10 09:00:00');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enterprise(string $name = 'Goriya Test SARL'): User
    {
        $company = Company::create([
            'name' => $name,
            'sector' => 'Technologie',
            'status' => 'ACTIVE',
            'partnership_date' => '2026-01-01',
            'headquarters' => 'Abidjan',
        ]);

        return User::create([
            'name' => $name,
            'email' => 'rh-'.$company->id.'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
            'company_id' => $company->id,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function employee(User $rh, array $overrides = []): string
    {
        return $this->actingAs($rh, 'api')
            ->postJson('/employees', array_merge([
                'firstName' => 'Aïcha',
                'lastName' => 'Koné',
                'jobTitle' => 'Comptable',
                'contractType' => 'CDI',
                'hireDate' => '2026-01-05',
                'salary' => 500000,
            ], $overrides))
            ->assertCreated()
            ->json('id');
    }

    /** @return list<array<string, mixed>> */
    private function contracts(User $rh, string $employeeId): array
    {
        return $this->actingAs($rh, 'api')->getJson("/employees/{$employeeId}/contracts")->assertOk()->json();
    }

    public function test_creating_an_employee_opens_the_initial_contract(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh, ['contractType' => 'CDD', 'contractEndDate' => '2026-12-31']);

        $this->actingAs($rh, 'api')
            ->getJson("/employees/{$id}/contracts")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.reference', 'CTR-0001')
            ->assertJsonPath('0.kind', 'INITIAL')
            ->assertJsonPath('0.status', 'ACTIVE')
            ->assertJsonPath('0.type', 'CDD')
            ->assertJsonPath('0.jobTitle', 'Comptable')
            ->assertJsonPath('0.startDate', '2026-01-05')
            ->assertJsonPath('0.endDate', '2026-12-31')
            ->assertJsonPath('0.salary', 500000);

        $this->actingAs($rh, 'api')
            ->getJson("/employees/{$id}")
            ->assertJsonPath('activeContract.reference', 'CTR-0001')
            ->assertJsonPath('activeContract.signed', false);

        // Un employé saisi comme déjà parti n'a pas de contrat « en vigueur ».
        $parti = $this->employee($rh, ['firstName' => 'Ancien', 'status' => 'TERMINATED']);
        $this->actingAs($rh, 'api')->getJson("/employees/{$parti}")->assertJsonPath('activeContract', null);
        $this->actingAs($rh, 'api')->getJson("/employees/{$parti}/contracts")->assertJsonPath('0.status', 'ENDED');
    }

    public function test_contract_terms_must_be_coherent(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh);
        $post = fn (array $body) => $this->actingAs($rh, 'api')->postJson("/employees/{$id}/contracts", $body);
        $base = ['jobTitle' => 'Comptable senior', 'startDate' => '2026-10-01'];

        $post($base + ['type' => 'CDD'])->assertStatus(400);
        $post($base + ['type' => 'CDI', 'endDate' => '2026-09-01'])->assertStatus(400);
        $post($base + ['type' => 'CDD', 'endDate' => '2026-12-31', 'trialEndDate' => '2027-01-15'])->assertStatus(400);
        $post($base + ['type' => 'CDI', 'kind' => 'AMENDMENT'])->assertStatus(400);
    }

    public function test_a_renewal_put_in_force_closes_the_previous_contract_and_updates_the_employee(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh, ['contractType' => 'CDD', 'contractEndDate' => '2026-12-31']);
        $initial = $this->contracts($rh, $id)[0]['id'];

        $this->actingAs($rh, 'api')
            ->postJson("/employees/{$id}/contracts", [
                'kind' => 'RENEWAL',
                'parentId' => $initial,
                'type' => 'CDD',
                'jobTitle' => 'Comptable',
                'startDate' => '2027-01-01',
                'endDate' => '2027-06-30',
                'salary' => 700000,
                'status' => 'ACTIVE',
            ])
            ->assertCreated()
            ->assertJsonPath('reference', 'CTR-0002')
            ->assertJsonPath('kind', 'RENEWAL')
            ->assertJsonPath('parent.reference', 'CTR-0001')
            ->assertJsonPath('status', 'ACTIVE');

        $contracts = collect($this->contracts($rh, $id))->keyBy('reference');
        $this->assertSame('ENDED', $contracts['CTR-0001']['status']);
        $this->assertSame('2026-12-31', $contracts['CTR-0001']['endDate']);

        $this->actingAs($rh, 'api')
            ->getJson("/employees/{$id}")
            ->assertJsonPath('contractEndDate', '2027-06-30')
            ->assertJsonPath('salary', 700000)
            ->assertJsonPath('activeContract.reference', 'CTR-0002');
    }

    public function test_a_contract_in_force_is_changed_by_amendment_only(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh);
        $initial = $this->contracts($rh, $id)[0]['id'];

        $this->actingAs($rh, 'api')->patchJson("/employee-contracts/{$initial}", ['salary' => 1])->assertStatus(400);
        $this->actingAs($rh, 'api')
            ->patchJson("/employee-contracts/{$initial}", ['notes' => 'Original au coffre', 'signedAt' => '2026-01-05'])
            ->assertOk()
            ->assertJsonPath('notes', 'Original au coffre')
            ->assertJsonPath('signedAt', '2026-01-05');
        $this->actingAs($rh, 'api')->getJson("/employees/{$id}")->assertJsonPath('activeContract.signed', true);

        $draft = $this->actingAs($rh, 'api')
            ->postJson("/employees/{$id}/contracts", ['type' => 'CDI', 'jobTitle' => 'Chef comptable', 'startDate' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('status', 'DRAFT')
            ->json('id');

        $this->actingAs($rh, 'api')->patchJson("/employee-contracts/{$draft}", ['salary' => 900000])->assertOk()->assertJsonPath('salary', 900000);
        $this->actingAs($rh, 'api')->patchJson("/employee-contracts/{$draft}", ['status' => 'ACTIVE'])->assertStatus(400);
        $this->actingAs($rh, 'api')->deleteJson("/employee-contracts/{$initial}")->assertStatus(400);
        $this->actingAs($rh, 'api')->deleteJson("/employee-contracts/{$draft}")->assertOk();
    }

    public function test_putting_a_draft_in_force_locks_the_employee_contract_fields(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh);
        $draft = $this->actingAs($rh, 'api')
            ->postJson("/employees/{$id}/contracts", ['type' => 'CDI', 'jobTitle' => 'Chef comptable', 'startDate' => '2026-10-01', 'salary' => 900000])
            ->json('id');

        $this->actingAs($rh, 'api')
            ->patchJson("/employee-contracts/{$draft}/status", ['status' => 'ACTIVE'])
            ->assertOk()
            ->assertJsonPath('status', 'ACTIVE');

        $contracts = collect($this->contracts($rh, $id))->keyBy('reference');
        $this->assertSame('ENDED', $contracts['CTR-0001']['status']);
        // CDI sans fin prévue : clos la veille du nouveau contrat.
        $this->assertSame('2026-09-30', $contracts['CTR-0001']['endDate']);

        $this->actingAs($rh, 'api')
            ->getJson("/employees/{$id}")
            ->assertJsonPath('jobTitle', 'Chef comptable')
            ->assertJsonPath('salary', 900000)
            ->assertJsonPath('activeContract.reference', 'CTR-0002');

        $this->actingAs($rh, 'api')->patchJson("/employees/{$id}", ['salary' => 1])->assertStatus(400);
        // Le formulaire complet renvoie les mêmes valeurs : accepté.
        $this->actingAs($rh, 'api')
            ->patchJson("/employees/{$id}", ['salary' => 900000, 'contractType' => 'CDI', 'jobTitle' => 'Chef comptable senior'])
            ->assertOk()
            ->assertJsonPath('jobTitle', 'Chef comptable senior');
    }

    public function test_a_contract_is_ended_or_terminated(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh, ['contractType' => 'CDD', 'contractEndDate' => '2026-12-31']);
        $contract = $this->contracts($rh, $id)[0]['id'];
        $status = fn (array $body) => $this->actingAs($rh, 'api')->patchJson("/employee-contracts/{$contract}/status", $body);

        $status(['status' => 'ENDED', 'endDate' => '2025-12-01'])->assertStatus(400);
        $status(['status' => 'TERMINATED'])->assertStatus(400);
        $status([
            'status' => 'TERMINATED',
            'terminationDate' => '2026-09-30',
            'terminationReason' => 'Démission',
            'markEmployeeDeparted' => true,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'TERMINATED')
            ->assertJsonPath('terminationDate', '2026-09-30')
            ->assertJsonPath('endDate', '2026-12-31');

        $this->actingAs($rh, 'api')
            ->getJson("/employees/{$id}")
            ->assertJsonPath('status', 'TERMINATED')
            ->assertJsonPath('contractEndDate', '2026-09-30')
            ->assertJsonPath('activeContract', null);
        $status(['status' => 'ACTIVE'])->assertStatus(400);

        // Arrivée à terme d'un CDD échu : la fin prévue est retenue.
        $autre = $this->employee($rh, ['firstName' => 'Yao', 'contractType' => 'CDD', 'contractEndDate' => '2026-08-31']);
        $cdd = $this->contracts($rh, $autre)[0]['id'];
        $this->actingAs($rh, 'api')
            ->patchJson("/employee-contracts/{$cdd}/status", ['status' => 'ENDED'])
            ->assertOk()
            ->assertJsonPath('status', 'ENDED')
            ->assertJsonPath('endDate', '2026-08-31');
    }

    public function test_the_signed_contract_is_attached_downloaded_and_removed(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh);
        $contract = $this->contracts($rh, $id)[0]['id'];

        $this->actingAs($rh, 'api')
            ->postJson("/employee-contracts/{$contract}/document", ['document' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
            ->assertStatus(400);

        $this->actingAs($rh, 'api')
            ->postJson("/employee-contracts/{$contract}/document", ['document' => UploadedFile::fake()->create('Contrat signé.pdf', 200, 'application/pdf')])
            ->assertOk()
            ->assertJsonPath('document.name', 'contrat-signe.pdf')
            // Joindre un contrat signé renseigne la date de signature.
            ->assertJsonPath('signedAt', '2026-09-10');
        $this->assertCount(1, Storage::disk('local')->allFiles('employee-contracts'));

        $this->actingAs($rh, 'api')
            ->get("/employee-contracts/{$contract}/document")
            ->assertOk()
            ->assertDownload('contrat-signe.pdf');

        // Une autre entreprise ne l'atteint pas.
        $autre = $this->enterprise('Concurrent SARL');
        $this->app['auth']->forgetGuards();
        $this->actingAs($autre, 'api')->getJson("/employee-contracts/{$contract}/document")->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->actingAs($rh, 'api')
            ->deleteJson("/employee-contracts/{$contract}/document")
            ->assertOk()
            ->assertJsonPath('document', null);
        $this->assertCount(0, Storage::disk('local')->allFiles('employee-contracts'));

        // Supprimer l'employé efface aussi les fichiers de ses contrats.
        $this->actingAs($rh, 'api')->postJson("/employee-contracts/{$contract}/document", ['document' => UploadedFile::fake()->create('contrat.pdf', 50, 'application/pdf')]);
        $this->actingAs($rh, 'api')->deleteJson("/employees/{$id}")->assertOk();
        $this->assertCount(0, Storage::disk('local')->allFiles('employee-contracts'));
    }

    public function test_a_contract_draft_document_is_generated(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh, ['contractType' => 'CDD', 'contractEndDate' => '2026-12-31', 'address' => 'Cocody, Abidjan']);
        $contract = $this->contracts($rh, $id)[0]['id'];

        $this->actingAs($rh, 'api')
            ->get("/employee-contracts/{$contract}/draft")
            ->assertOk()
            ->assertDownload('projet-CTR-0001.docx');
    }

    public function test_company_contracts_are_listed_and_isolated(): void
    {
        $rh = $this->enterprise();
        $this->employee($rh);
        $this->employee($rh, ['firstName' => 'Yao', 'lastName' => "N'Guessan"]);

        $autre = $this->enterprise('Concurrent SARL');
        $this->employee($autre, ['firstName' => 'Fatou']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($rh, 'api')->getJson('/employee-contracts')->assertOk()->assertJsonCount(2);
        $this->actingAs($rh, 'api')->getJson('/employee-contracts?status=ACTIVE')->assertJsonCount(2);
        $this->actingAs($rh, 'api')->getJson('/employee-contracts?status=DRAFT')->assertJsonCount(0);
        $this->actingAs($rh, 'api')
            ->getJson('/employee-contracts?search=CTR-0002')
            ->assertJsonCount(1)
            ->assertJsonPath('0.employee.fullName', "Yao N'Guessan");
        $this->actingAs($rh, 'api')->getJson('/employee-contracts?status=INCONNU')->assertStatus(400);

        $salarie = User::create([
            'name' => 'Salarié curieux',
            'email' => 'curieux@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'company_id' => $rh->company_id,
        ]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($salarie, 'api')->getJson('/employee-contracts')->assertForbidden();
    }
}
