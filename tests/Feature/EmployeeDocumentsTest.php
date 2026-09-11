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
 * Services RH, étape 6 : documents RH.
 *
 * Ce qui compte :
 *  - les documents restent privés à l'entreprise, fichiers compris ;
 *  - un même endroit réunit fichiers déposés, attestations générées, contrats
 *    signés et bulletins validés ;
 *  - une attestation rédigée pour une demande RH approuve cette demande ;
 *  - aucun fichier ne survit à la suppression de son document ou de l'employé.
 *
 * Horloge figée au jeudi 10 septembre 2026, disque privé simulé.
 */
class EmployeeDocumentsTest extends TestCase
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
            'name' => "Awa Koné ({$name})",
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
                'gender' => 'F',
                'jobTitle' => 'Comptable',
                'contractType' => 'CDI',
                'hireDate' => '2026-01-05',
                'salary' => 500000,
            ], $overrides))
            ->assertCreated()
            ->json('id');
    }

    /** @param  array<string, mixed>  $fields */
    private function upload(User $rh, array $fields, string $name = 'CNI Aïcha.pdf', string $mime = 'application/pdf')
    {
        return $this->actingAs($rh, 'api')->post('/hr-documents', array_merge([
            'category' => 'IDENTITY',
            'file' => UploadedFile::fake()->create($name, 120, $mime),
        ], $fields), ['Accept' => 'application/json']);
    }

    public function test_an_employee_document_is_uploaded_listed_and_downloaded(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh);

        $document = $this->upload($rh, ['employeeId' => $id, 'issuedAt' => '2021-10-01', 'expiresAt' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('title', 'CNI Aïcha')
            ->assertJsonPath('fileName', 'cni-aicha.pdf')
            ->assertJsonPath('source', 'UPLOAD')
            ->assertJsonPath('employee.id', $id)
            ->assertJsonPath('expiresAt', '2026-10-01')
            ->assertJsonPath('uploadedByName', $rh->name)
            ->json('id');
        $this->assertCount(1, Storage::disk('local')->allFiles('hr-documents'));

        $this->upload($rh, ['employeeId' => $id], 'programme.exe', 'application/octet-stream')->assertStatus(400);
        $this->upload($rh, ['employeeId' => $id, 'issuedAt' => '2026-09-01', 'expiresAt' => '2026-08-01'])->assertStatus(400);
        $this->assertCount(1, Storage::disk('local')->allFiles('hr-documents'));

        $this->actingAs($rh, 'api')->getJson("/hr-documents?employeeId={$id}")->assertOk()->assertJsonCount(1);
        $this->actingAs($rh, 'api')->get("/hr-documents/{$document}/download")->assertOk()->assertDownload('cni-aicha.pdf');
        // Expire le 1er octobre : dans les échéances à 30 jours, pas à 7.
        $this->actingAs($rh, 'api')->getJson('/hr-documents?expiringWithin=30')->assertJsonCount(1);
        $this->actingAs($rh, 'api')->getJson('/hr-documents?expiringWithin=7')->assertJsonCount(0);

        $concurrent = $this->enterprise('Concurrent SARL');
        $this->app['auth']->forgetGuards();
        $this->actingAs($concurrent, 'api')->getJson("/hr-documents/{$document}/download")->assertNotFound();
        $this->actingAs($concurrent, 'api')->getJson("/hr-documents?employeeId={$id}")->assertNotFound();
        $this->actingAs($concurrent, 'api')->getJson('/hr-documents')->assertOk()->assertJsonCount(0);

        $salarie = User::create([
            'name' => 'Salarié curieux',
            'email' => 'curieux@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'company_id' => $rh->company_id,
        ]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($salarie, 'api')->getJson('/hr-documents')->assertForbidden();
    }

    public function test_company_documents_sit_beside_employee_files(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh);

        $this->upload($rh, ['category' => 'POLICY', 'title' => 'Règlement intérieur 2026'], 'reglement.pdf')
            ->assertCreated()
            ->assertJsonPath('employee', null);
        $this->upload($rh, ['employeeId' => $id, 'category' => 'MEDICAL', 'title' => "Visite médicale d'embauche"], 'aptitude.png', 'image/png')
            ->assertCreated();

        $this->actingAs($rh, 'api')->getJson('/hr-documents?scope=company')->assertJsonCount(1)->assertJsonPath('0.category', 'POLICY');
        $this->actingAs($rh, 'api')->getJson('/hr-documents?scope=employees&includeLinked=0')->assertJsonCount(1)->assertJsonPath('0.category', 'MEDICAL');
        $this->actingAs($rh, 'api')->getJson('/hr-documents?category=POLICY,MEDICAL')->assertJsonCount(2);
        $this->actingAs($rh, 'api')->getJson('/hr-documents?search=glement')->assertJsonCount(1);
        $this->actingAs($rh, 'api')->getJson('/hr-documents?search=kon')->assertJsonCount(1)->assertJsonPath('0.employee.fullName', 'Aïcha Koné');
        $this->actingAs($rh, 'api')->getJson('/hr-documents?category=INCONNUE')->assertStatus(400);
    }

    public function test_metadata_changes_and_files_leave_with_their_document(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh);
        $document = $this->upload($rh, ['employeeId' => $id, 'issuedAt' => '2026-01-05'])->json('id');

        $this->actingAs($rh, 'api')
            ->patchJson("/hr-documents/{$document}", ['title' => "Passeport d'Aïcha", 'expiresAt' => '2031-01-05'])
            ->assertOk()
            ->assertJsonPath('title', "Passeport d'Aïcha")
            ->assertJsonPath('expiresAt', '2031-01-05');
        $this->actingAs($rh, 'api')->patchJson("/hr-documents/{$document}", ['expiresAt' => '2025-12-31'])->assertStatus(400);
        $this->actingAs($rh, 'api')->patchJson("/hr-documents/{$document}", ['title' => ' '])->assertStatus(400);
        // Rangé au niveau de l'entreprise, le document quitte le dossier de l'employé.
        $this->actingAs($rh, 'api')->patchJson("/hr-documents/{$document}", ['employeeId' => null])->assertOk()->assertJsonPath('employee', null);

        $this->actingAs($rh, 'api')->deleteJson("/hr-documents/{$document}")->assertOk();
        $this->assertCount(0, Storage::disk('local')->allFiles('hr-documents'));

        $this->upload($rh, ['employeeId' => $id])->assertCreated();
        $this->actingAs($rh, 'api')->deleteJson("/employees/{$id}")->assertOk();
        $this->assertCount(0, Storage::disk('local')->allFiles('hr-documents'));
    }

    public function test_certificates_are_generated_and_answer_hr_requests(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh, ['birthDate' => '1994-03-12']);

        $request = $this->actingAs($rh, 'api')
            ->postJson("/employees/{$id}/hr-requests", ['type' => 'WORK_CERTIFICATE', 'subject' => 'Attestation pour la banque'])
            ->assertCreated()
            ->json('id');

        $this->actingAs($rh, 'api')
            ->postJson("/employees/{$id}/documents/generate", ['template' => 'SALARY_CERTIFICATE', 'hrRequestId' => $request])
            ->assertStatus(400);
        // Encore en poste : pas de certificat de travail.
        $this->actingAs($rh, 'api')->postJson("/employees/{$id}/documents/generate", ['template' => 'EMPLOYMENT_CERTIFICATE'])->assertStatus(400);

        $document = $this->actingAs($rh, 'api')
            ->postJson("/employees/{$id}/documents/generate", ['template' => 'WORK_CERTIFICATE', 'hrRequestId' => $request, 'city' => 'Abidjan'])
            ->assertCreated()
            ->assertJsonPath('source', 'GENERATED')
            ->assertJsonPath('category', 'CERTIFICATE')
            ->assertJsonPath('template', 'WORK_CERTIFICATE')
            ->assertJsonPath('title', 'Attestation de travail du 10 septembre 2026')
            ->assertJsonPath('fileName', 'attestation-travail-emp-0001-2026-09-10.docx')
            ->assertJsonPath('hrRequestId', $request)
            ->json('id');

        $this->actingAs($rh, 'api')
            ->getJson("/employees/{$id}/hr-requests")
            ->assertJsonPath('0.status', 'APPROVED')
            ->assertJsonPath('0.document.id', $document);
        $this->actingAs($rh, 'api')->get("/hr-documents/{$document}/download")->assertOk()->assertDownload('attestation-travail-emp-0001-2026-09-10.docx');

        $this->actingAs($rh, 'api')->postJson("/employees/{$id}/documents/generate", ['template' => 'SALARY_CERTIFICATE'])->assertCreated();
        $this->actingAs($rh, 'api')->patchJson("/hr-documents/{$document}", ['employeeId' => null])->assertStatus(400);

        $parti = $this->employee($rh, ['firstName' => 'Yao', 'gender' => 'M', 'contractType' => 'CDD', 'hireDate' => '2025-09-01', 'contractEndDate' => '2026-08-31', 'status' => 'TERMINATED']);
        $this->actingAs($rh, 'api')
            ->postJson("/employees/{$parti}/documents/generate", ['template' => 'EMPLOYMENT_CERTIFICATE'])
            ->assertCreated()
            ->assertJsonPath('fileName', 'certificat-travail-emp-0002-2026-09-10.docx');
    }

    public function test_signed_contracts_and_validated_payslips_join_the_list_read_only(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh);

        $contract = $this->actingAs($rh, 'api')->getJson("/employees/{$id}/contracts")->json('0.id');
        $this->actingAs($rh, 'api')
            ->postJson("/employee-contracts/{$contract}/document", ['document' => UploadedFile::fake()->create('contrat.pdf', 80, 'application/pdf')])
            ->assertOk();

        $run = $this->actingAs($rh, 'api')->postJson('/payroll/runs', ['year' => 2026, 'month' => 9])->assertCreated()->json('id');
        $this->actingAs($rh, 'api')->getJson('/hr-documents?category=PAYROLL')->assertJsonCount(0);
        $this->actingAs($rh, 'api')->postJson("/payroll/runs/{$run}/validate")->assertOk();

        $documents = collect($this->actingAs($rh, 'api')->getJson("/hr-documents?employeeId={$id}")->assertOk()->assertJsonCount(2)->json())->keyBy('source');
        $this->assertSame('Contrat signé CTR-0001', $documents['CONTRACT']['title']);
        $this->assertSame("/employee-contracts/{$contract}/document", $documents['CONTRACT']['downloadPath']);
        $this->assertFalse($documents['CONTRACT']['editable']);
        $this->assertSame('Bulletin de paie — septembre 2026', $documents['PAYSLIP']['title']);
        $this->assertStringStartsWith('/payslips/', $documents['PAYSLIP']['downloadPath']);

        $this->actingAs($rh, 'api')->getJson('/hr-documents?category=PAYROLL')->assertJsonCount(1);
        $this->actingAs($rh, 'api')->getJson('/hr-documents?includeLinked=0')->assertJsonCount(0);
        $this->actingAs($rh, 'api')->getJson('/hr-documents?scope=company')->assertJsonCount(0);
    }
}
