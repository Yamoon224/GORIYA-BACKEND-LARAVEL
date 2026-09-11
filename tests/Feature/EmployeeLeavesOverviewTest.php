<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Services RH, étape 2 : la page Congés lit les congés et les soldes de toute
 * l'entreprise d'un coup, au lieu d'employé par employé.
 *
 * Horloge figée au jeudi 10 septembre 2026, comme EmployeesTest.
 */
class EmployeeLeavesOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-10 09:00:00');
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
    private function employee(User $rh, string $firstName, string $lastName, array $overrides = []): string
    {
        return $this->actingAs($rh, 'api')
            ->postJson('/employees', array_merge([
                'firstName' => $firstName,
                'lastName' => $lastName,
                'jobTitle' => 'Chargé de mission',
                'department' => 'Finance',
                'contractType' => 'CDI',
                'hireDate' => '2025-01-06',
            ], $overrides))
            ->assertCreated()
            ->json('id');
    }

    private function leave(User $rh, string $employeeId, string $type, string $start, string $end, ?string $decision = null): string
    {
        $id = $this->actingAs($rh, 'api')
            ->postJson("/employees/{$employeeId}/leaves", ['type' => $type, 'startDate' => $start, 'endDate' => $end])
            ->assertCreated()
            ->json('id');

        if ($decision) {
            $this->actingAs($rh, 'api')
                ->patchJson("/employee-leaves/{$id}/status", ['status' => $decision])
                ->assertOk();
        }

        return $id;
    }

    public function test_company_leaves_are_listed_with_their_employee(): void
    {
        $rh = $this->enterprise();
        $aicha = $this->employee($rh, 'Aïcha', 'Koné');
        $yao = $this->employee($rh, 'Yao', "N'Guessan");
        $this->leave($rh, $aicha, 'PAID', '2026-09-14', '2026-09-15');
        $this->leave($rh, $yao, 'SICK', '2026-09-01', '2026-09-02');

        $autre = $this->enterprise('Concurrent SARL');
        $this->leave($autre, $this->employee($autre, 'Fatou', 'Diabaté'), 'PAID', '2026-09-14', '2026-09-15');

        $this->actingAs($rh, 'api')
            ->getJson('/employee-leaves')
            ->assertOk()
            ->assertJsonCount(2)
            // Du plus récent au plus ancien.
            ->assertJsonPath('0.employee.id', $aicha)
            ->assertJsonPath('0.employee.fullName', 'Aïcha Koné')
            ->assertJsonPath('0.employee.department', 'Finance')
            ->assertJsonPath('1.employee.fullName', "Yao N'Guessan");
    }

    public function test_leaves_are_filtered_by_status_type_employee_and_period(): void
    {
        $rh = $this->enterprise();
        $aicha = $this->employee($rh, 'Aïcha', 'Koné');
        $yao = $this->employee($rh, 'Yao', "N'Guessan", ['department' => 'Logistique']);
        $this->leave($rh, $aicha, 'PAID', '2026-09-14', '2026-09-18', 'APPROVED');
        $this->leave($rh, $aicha, 'SICK', '2026-10-05', '2026-10-06');
        $this->leave($rh, $yao, 'PAID', '2026-09-21', '2026-09-22');

        $get = fn (string $query) => $this->actingAs($rh, 'api')->getJson("/employee-leaves?{$query}")->assertOk();

        $get('status=PENDING')->assertJsonCount(2);
        $get('status=PENDING,APPROVED')->assertJsonCount(3);
        $get('status[]=APPROVED')->assertJsonCount(1);
        $get('type=SICK')->assertJsonCount(1)->assertJsonPath('0.type', 'SICK');
        $get("employeeId={$yao}")->assertJsonCount(1);
        $get('department=Logistique')->assertJsonCount(1);
        $get('search=guessan')->assertJsonCount(1);
        $get('from=2026-10-01&to=2026-10-31')->assertJsonCount(1);
        // Une période retient les congés qui la chevauchent, même commencés avant.
        $get('from=2026-09-16&to=2026-09-21')->assertJsonCount(2);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $rh = $this->enterprise();

        $this->actingAs($rh, 'api')->getJson('/employee-leaves?status=INCONNU')->assertStatus(400);
        $this->actingAs($rh, 'api')->getJson('/employee-leaves?from=2026-10-10&to=2026-10-01')->assertStatus(400);
        $this->actingAs($rh, 'api')->getJson('/employee-leaves/balances?year=1990')->assertStatus(400);
    }

    public function test_balances_cover_every_present_employee(): void
    {
        $rh = $this->enterprise();
        $aicha = $this->employee($rh, 'Aïcha', 'Koné');
        $this->employee($rh, 'Yao', "N'Guessan", ['annualLeaveDays' => 20]);
        $this->employee($rh, 'Ancien', 'Salarié', ['status' => 'TERMINATED']);

        $this->leave($rh, $aicha, 'PAID', '2026-09-14', '2026-09-18', 'APPROVED'); // 5 jours pris
        $this->leave($rh, $aicha, 'PAID', '2026-09-21', '2026-09-22');             // 2 jours en attente
        $this->leave($rh, $aicha, 'SICK', '2026-10-05', '2026-10-07', 'APPROVED'); // hors solde
        $this->leave($rh, $aicha, 'PAID', '2025-12-15', '2025-12-19', 'APPROVED'); // année précédente

        $this->actingAs($rh, 'api')
            ->getJson('/employee-leaves/balances')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.employee.fullName', 'Aïcha Koné')
            ->assertJsonPath('0.year', 2026)
            ->assertJsonPath('0.entitlement', 26)
            ->assertJsonPath('0.taken', 5)
            ->assertJsonPath('0.pending', 2)
            ->assertJsonPath('0.remaining', 21)
            ->assertJsonPath('1.employee.fullName', "Yao N'Guessan")
            ->assertJsonPath('1.entitlement', 20)
            ->assertJsonPath('1.remaining', 20);

        $this->actingAs($rh, 'api')
            ->getJson('/employee-leaves/balances?year=2025')
            ->assertOk()
            ->assertJsonPath('0.year', 2025)
            ->assertJsonPath('0.taken', 5)
            ->assertJsonPath('0.pending', 0);

        // Même formule que la fiche détail.
        $this->actingAs($rh, 'api')
            ->getJson("/employees/{$aicha}")
            ->assertJsonPath('leaveBalance.remaining', 21);
    }

    public function test_the_overview_is_reserved_to_the_enterprise_account(): void
    {
        $rh = $this->enterprise();
        $salarie = User::create([
            'name' => 'Salarié curieux',
            'email' => 'curieux@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'company_id' => $rh->company_id,
        ]);

        $this->actingAs($salarie, 'api')->getJson('/employee-leaves')->assertForbidden();
        $this->actingAs($salarie, 'api')->getJson('/employee-leaves/balances')->assertForbidden();
    }
}
