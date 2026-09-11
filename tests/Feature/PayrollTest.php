<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Services RH, étape 4 : paie.
 *
 * Les montants attendus découlent des paramètres par défaut (CNPS, CMU, FDFP,
 * ITS). Ces tests vérifient le moteur de calcul et le cycle de vie des
 * périodes — pas la conformité légale des taux, qui reste à valider par un
 * comptable et que l'entreprise peut modifier.
 *
 * Horloge figée au jeudi 10 septembre 2026. Septembre 2026 compte 22 jours
 * ouvrés, octobre 2026 aussi.
 */
class PayrollTest extends TestCase
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

    /** @return array<string, mixed> */
    private function run(User $rh, int $year = 2026, int $month = 9): array
    {
        return $this->actingAs($rh, 'api')
            ->postJson('/payroll/runs', ['year' => $year, 'month' => $month])
            ->assertCreated()
            ->json();
    }

    public function test_a_full_month_payslip_is_computed_with_the_default_settings(): void
    {
        $rh = $this->enterprise();
        $this->employee($rh);

        $this->actingAs($rh, 'api')
            ->getJson('/payroll/settings')
            ->assertOk()
            ->assertJsonPath('preset', 'CI')
            ->assertJsonCount(6, 'contributions');

        $run = $this->run($rh);
        $this->assertSame('DRAFT', $run['status']);
        $this->assertSame(1, $run['employeesCount']);

        $payslip = $run['payslips'][0];
        $this->assertSame(22, $payslip['businessDays']);
        $this->assertSame(22, $payslip['workedDays']);
        $this->assertSame(500000, $payslip['gross']);
        // Retraite 6,3 % (31 500) + CMU (1 000).
        $this->assertSame(32500, $payslip['employeeContributions']);
        // Retraite 38 500 + PF 4 025 + maternité 525 + AT 1 400 + CMU 1 000 + FDFP 8 000.
        $this->assertSame(53450, $payslip['employerContributions']);
        // Tranches : 165 000 × 16 % + 260 000 × 21 %.
        $this->assertSame(81000, $payslip['tax']);
        $this->assertSame(386500, $payslip['net']);
        $this->assertSame(553450, $payslip['employerCost']);

        $retraite = collect($payslip['lines'])->firstWhere('code', 'CNPS_RETRAITE');
        $this->assertSame(31500, $retraite['employeeAmount']);
        $this->assertSame(38500, $retraite['employerAmount']);

        $this->assertSame(386500, $run['totals']['net']);
    }

    public function test_mid_month_hiring_and_unpaid_leave_reduce_the_gross(): void
    {
        $rh = $this->enterprise();
        // Salaire journalier : 440 000 / 22 = 20 000.
        $id = $this->employee($rh, ['hireDate' => '2026-09-14', 'salary' => 440000]);
        $leave = $this->actingAs($rh, 'api')
            ->postJson("/employees/{$id}/leaves", ['type' => 'UNPAID', 'startDate' => '2026-09-21', 'endDate' => '2026-09-22'])
            ->json('id');
        $this->actingAs($rh, 'api')->patchJson("/employee-leaves/{$leave}/status", ['status' => 'APPROVED'])->assertOk();

        $payslip = $this->run($rh)['payslips'][0];

        // 13 jours ouvrés couverts (du 14 au 30) dont 2 d'absence non rémunérée.
        $this->assertSame(11, $payslip['workedDays']);
        $this->assertSame(2, $payslip['unpaidLeaveDays']);
        // 440 000 − 9 j × 20 000 − 2 j × 20 000.
        $this->assertSame(220000, $payslip['gross']);
    }

    public function test_departures_are_paid_pro_rata_and_ended_contracts_are_left_out(): void
    {
        $rh = $this->enterprise();
        $this->employee($rh, ['contractType' => 'CDD', 'contractEndDate' => '2026-09-18', 'salary' => 440000]);
        $this->employee($rh, ['firstName' => 'Yao', 'lastName' => "N'Guessan", 'contractType' => 'CDD', 'contractEndDate' => '2026-08-31']);

        $run = $this->run($rh);

        $this->assertSame(1, $run['employeesCount']);
        // Du 1er au 18 : 14 jours ouvrés, 8 non couverts.
        $this->assertSame(280000, $run['payslips'][0]['gross']);
    }

    public function test_an_approved_salary_advance_is_deducted_once(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh);
        $advance = $this->actingAs($rh, 'api')
            ->postJson("/employees/{$id}/hr-requests", ['type' => 'SALARY_ADVANCE', 'subject' => 'Rentrée scolaire', 'amount' => 150000])
            ->json('id');
        $this->actingAs($rh, 'api')->patchJson("/hr-requests/{$advance}/status", ['status' => 'APPROVED'])->assertOk();

        $september = $this->run($rh);
        $this->assertSame(150000, $september['payslips'][0]['advances']);
        $this->assertSame(236500, $september['payslips'][0]['net']);

        $this->actingAs($rh, 'api')->postJson("/payroll/runs/{$september['id']}/validate")->assertOk()->assertJsonPath('status', 'VALIDATED');
        $this->assertNotNull($this->actingAs($rh, 'api')->getJson("/employees/{$id}/hr-requests")->json('0.payslipId'));

        Carbon::setTestNow('2026-10-05 09:00:00');
        $october = $this->run($rh, 2026, 10);
        $this->assertSame(0, $october['payslips'][0]['advances']);
        $this->assertSame(386500, $october['payslips'][0]['net']);
    }

    public function test_periods_follow_the_calendar_and_lock_once_validated(): void
    {
        $rh = $this->enterprise();
        $this->employee($rh);
        $run = $this->run($rh);
        $payslip = $run['payslips'][0]['id'];

        $this->actingAs($rh, 'api')->postJson('/payroll/runs', ['year' => 2026, 'month' => 9])->assertStatus(400);
        $this->actingAs($rh, 'api')->postJson('/payroll/runs', ['year' => 2026, 'month' => 10])->assertStatus(400);

        // Prime non imposable et non soumise aux cotisations : le net augmente du montant.
        $this->actingAs($rh, 'api')
            ->patchJson("/payslips/{$payslip}", [
                'bonuses' => [['label' => 'Prime de transport', 'amount' => 30000, 'taxable' => false, 'subjectToContributions' => false]],
                'deductions' => [],
            ])
            ->assertOk()
            ->assertJsonPath('gross', 530000)
            ->assertJsonPath('employeeContributions', 32500)
            ->assertJsonPath('tax', 81000)
            ->assertJsonPath('net', 416500);

        // Les primes saisies survivent au recalcul.
        $this->actingAs($rh, 'api')->postJson("/payroll/runs/{$run['id']}/recompute")->assertOk()->assertJsonPath('totals.net', 416500);

        $this->actingAs($rh, 'api')->postJson("/payroll/runs/{$run['id']}/validate")->assertOk();
        $this->actingAs($rh, 'api')->patchJson("/payslips/{$payslip}", ['bonuses' => [], 'deductions' => []])->assertStatus(400);
        $this->actingAs($rh, 'api')->postJson("/payroll/runs/{$run['id']}/recompute")->assertStatus(400);
        $this->actingAs($rh, 'api')->deleteJson("/payroll/runs/{$run['id']}")->assertStatus(400);
        // Période antérieure à une paie existante.
        $this->actingAs($rh, 'api')->postJson('/payroll/runs', ['year' => 2026, 'month' => 8])->assertStatus(400);
        // Au-delà du mois prochain.
        $this->actingAs($rh, 'api')->postJson('/payroll/runs', ['year' => 2026, 'month' => 11])->assertStatus(400);

        $this->actingAs($rh, 'api')->postJson("/payroll/runs/{$run['id']}/reopen")->assertOk()->assertJsonPath('status', 'DRAFT');
        $this->actingAs($rh, 'api')->postJson("/payroll/runs/{$run['id']}/validate")->assertOk();
        $this->actingAs($rh, 'api')
            ->postJson("/payroll/runs/{$run['id']}/pay", ['paymentDate' => '2026-09-30', 'paymentReference' => 'VIR-0926'])
            ->assertOk()
            ->assertJsonPath('status', 'PAID')
            ->assertJsonPath('paymentDate', '2026-09-30');
        $this->actingAs($rh, 'api')->postJson("/payroll/runs/{$run['id']}/reopen")->assertStatus(400);
    }

    public function test_a_payslip_without_salary_blocks_validation_until_an_amendment_sets_it(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh, ['salary' => null]);
        $run = $this->run($rh);

        $this->assertSame('MISSING_SALARY', $run['payslips'][0]['warnings'][0]['code']);
        $this->actingAs($rh, 'api')->postJson("/payroll/runs/{$run['id']}/validate")->assertStatus(400);

        $initial = $this->actingAs($rh, 'api')->getJson("/employees/{$id}/contracts")->json('0.id');
        $this->actingAs($rh, 'api')->postJson("/employees/{$id}/contracts", [
            'kind' => 'AMENDMENT',
            'parentId' => $initial,
            'type' => 'CDI',
            'jobTitle' => 'Comptable',
            'startDate' => '2026-09-01',
            'salary' => 500000,
            'status' => 'ACTIVE',
        ])->assertCreated();

        $this->actingAs($rh, 'api')->postJson("/payroll/runs/{$run['id']}/validate")
            ->assertOk()
            ->assertJsonPath('status', 'VALIDATED')
            ->assertJsonPath('totals.net', 386500);
    }

    public function test_custom_settings_are_validated_and_applied(): void
    {
        $rh = $this->enterprise();
        $this->employee($rh);
        $put = fn (array $body) => $this->actingAs($rh, 'api')->putJson('/payroll/settings', $body);
        $valid = [
            'contributions' => [['code' => 'CNPS', 'label' => 'Sécurité sociale', 'employeeRate' => 5, 'employerRate' => 10, 'ceiling' => null]],
            'taxBrackets' => [['upTo' => 100000, 'rate' => 0], ['upTo' => null, 'rate' => 10]],
            'taxLabel' => 'IRPP',
            'taxableAbatementPercent' => 20,
            'deductEmployeeContributions' => true,
        ];

        $put(array_merge($valid, ['taxBrackets' => [['upTo' => 200000, 'rate' => 0], ['upTo' => 100000, 'rate' => 10], ['upTo' => null, 'rate' => 20]]]))->assertStatus(400);
        $put(array_merge($valid, ['taxBrackets' => [['upTo' => null, 'rate' => 0], ['upTo' => 100000, 'rate' => 10]]]))->assertStatus(400);
        $put(array_merge($valid, ['taxBrackets' => [['upTo' => null, 'rate' => 150]]]))->assertStatus(400);
        $put($valid)->assertOk()->assertJsonPath('preset', 'CUSTOM')->assertJsonPath('taxLabel', 'IRPP');

        $payslip = $this->run($rh)['payslips'][0];
        $this->assertSame(25000, $payslip['employeeContributions']);
        $this->assertSame(50000, $payslip['employerContributions']);
        // (500 000 − 25 000) × 80 % = 380 000 ; (380 000 − 100 000) × 10 %.
        $this->assertSame(380000, $payslip['taxable']);
        $this->assertSame(28000, $payslip['tax']);
        $this->assertSame(447000, $payslip['net']);

        $this->actingAs($rh, 'api')->postJson('/payroll/settings/reset')->assertOk()->assertJsonPath('preset', 'CI');
    }

    public function test_the_payroll_journal_and_payslip_are_exported(): void
    {
        $rh = $this->enterprise();
        $this->employee($rh);
        $run = $this->run($rh);

        $export = $this->actingAs($rh, 'api')->get("/payroll/runs/{$run['id']}/export");
        $export->assertOk()->assertDownload('journal-paie-2026-09.csv');
        $this->assertStringContainsString('Net à payer', $export->getContent());
        $this->assertStringContainsString('386500', $export->getContent());

        $this->actingAs($rh, 'api')
            ->get("/payslips/{$run['payslips'][0]['id']}/document")
            ->assertOk()
            ->assertDownload('bulletin-2026-09-EMP-0001.docx');
    }

    public function test_payroll_is_isolated_and_reserved_to_the_enterprise_account(): void
    {
        $rh = $this->enterprise();
        $id = $this->employee($rh);
        $run = $this->run($rh);

        $this->actingAs($rh, 'api')->getJson("/employees/{$id}/payslips")->assertOk()->assertJsonCount(1)->assertJsonPath('0.run.month', 9);

        $autre = $this->enterprise('Concurrent SARL');
        $this->app['auth']->forgetGuards();
        $this->actingAs($autre, 'api')->getJson("/payroll/runs/{$run['id']}")->assertNotFound();
        $this->actingAs($autre, 'api')->getJson("/payslips/{$run['payslips'][0]['id']}")->assertNotFound();
        $this->actingAs($autre, 'api')->getJson('/payroll/runs')->assertOk()->assertJsonCount(0);

        $salarie = User::create([
            'name' => 'Salarié curieux',
            'email' => 'curieux@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'company_id' => $rh->company_id,
        ]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($salarie, 'api')->getJson('/payroll/runs')->assertForbidden();
    }
}
