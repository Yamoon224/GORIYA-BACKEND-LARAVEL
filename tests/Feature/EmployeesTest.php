<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Services RH, étape 1 : répertoire des employés, congés et demandes RH.
 *
 * Ce qui compte ici :
 *  - les données restent cloisonnées par entreprise, et réservées au compte
 *    entreprise (pas à un employé qui porterait le même company_id) ;
 *  - un employé naît d'une saisie manuelle ou d'une candidature acceptée, et
 *    une candidature ne s'embauche qu'une fois ;
 *  - les congés respectent jours ouvrés, chevauchements et solde.
 *
 * Horloge figée au jeudi 10 septembre 2026 : le lundi 14 ouvre une semaine de
 * cinq jours ouvrés, et le solde se calcule sur l'année 2026.
 */
class EmployeesTest extends TestCase
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

    private function company(string $name = 'Goriya Test SARL'): Company
    {
        return Company::create([
            'name' => $name,
            'sector' => 'Technologie',
            'status' => 'ACTIVE',
            'partnership_date' => '2026-01-01',
        ]);
    }

    private function enterprise(Company $company): User
    {
        return User::create([
            'name' => $company->name,
            'email' => 'rh-'.$company->id.'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
            'company_id' => $company->id,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function employeePayload(array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Aïcha',
            'lastName' => 'Koné',
            'email' => 'aicha.kone@example.ci',
            'jobTitle' => 'Product Designer',
            'department' => 'Design',
            'contractType' => 'CDI',
            'hireDate' => '2025-03-01',
            'salary' => 650000,
        ], $overrides);
    }

    /** @return array{0: User, 1: string} */
    private function enterpriseWithEmployee(array $overrides = []): array
    {
        $user = $this->enterprise($this->company());
        $id = $this->actingAs($user, 'api')
            ->postJson('/employees', $this->employeePayload($overrides))
            ->assertCreated()
            ->json('id');

        return [$user, $id];
    }

    // ── Répertoire ────────────────────────────────────────────────────────

    public function test_an_employee_is_added_manually(): void
    {
        $user = $this->enterprise($this->company());

        $this->actingAs($user, 'api')
            ->postJson('/employees', $this->employeePayload())
            ->assertCreated()
            ->assertJsonPath('source', 'MANUAL')
            ->assertJsonPath('matricule', 'EMP-0001')
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('annualLeaveDays', 26)
            ->assertJsonPath('fullName', 'Aïcha Koné')
            ->assertJsonPath('hireDate', '2025-03-01');

        $this->actingAs($user, 'api')
            ->postJson('/employees', $this->employeePayload(['email' => 'yao@example.ci', 'firstName' => 'Yao']))
            ->assertCreated()
            ->assertJsonPath('matricule', 'EMP-0002');
    }

    public function test_a_fixed_term_contract_needs_an_end_date(): void
    {
        $user = $this->enterprise($this->company());

        $this->actingAs($user, 'api')
            ->postJson('/employees', $this->employeePayload(['contractType' => 'CDD']))
            ->assertStatus(400)
            ->assertJsonPath('message', 'La date de fin est obligatoire pour un CDD.');
    }

    public function test_employees_are_isolated_per_company(): void
    {
        [, $id] = $this->enterpriseWithEmployee();
        $autre = $this->enterprise($this->company('Concurrent SARL'));

        $this->actingAs($autre, 'api')->getJson('/employees')->assertOk()->assertJsonCount(0);
        $this->actingAs($autre, 'api')->getJson("/employees/{$id}")->assertNotFound();
        $this->actingAs($autre, 'api')->deleteJson("/employees/{$id}")->assertNotFound();
        $this->assertSame(1, Employee::count());
    }

    public function test_a_user_account_sharing_the_company_cannot_read_the_directory(): void
    {
        [$rh] = $this->enterpriseWithEmployee();
        $salarie = User::create([
            'name' => 'Salarié curieux',
            'email' => 'curieux@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'company_id' => $rh->company_id,
        ]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($salarie, 'api')->getJson('/employees')->assertForbidden();
    }

    public function test_an_employee_is_updated_and_the_manager_is_checked(): void
    {
        [$user, $id] = $this->enterpriseWithEmployee();

        $this->actingAs($user, 'api')
            ->patchJson("/employees/{$id}", ['jobTitle' => 'Lead Designer', 'status' => 'PROBATION'])
            ->assertOk()
            ->assertJsonPath('jobTitle', 'Lead Designer')
            ->assertJsonPath('status', 'PROBATION')
            ->assertJsonPath('leaveBalance.remaining', 26);

        $this->actingAs($user, 'api')
            ->patchJson("/employees/{$id}", ['managerId' => $id])
            ->assertStatus(400);

        [, $etranger] = $this->enterpriseWithEmployee(['email' => 'autre@example.ci']);
        $this->actingAs($user, 'api')
            ->patchJson("/employees/{$id}", ['managerId' => $etranger])
            ->assertStatus(400);
    }

    public function test_deleting_an_employee_removes_leaves_and_requests(): void
    {
        [$user, $id] = $this->enterpriseWithEmployee();
        $this->actingAs($user, 'api')->postJson("/employees/{$id}/leaves", [
            'type' => 'SICK', 'startDate' => '2026-09-14', 'endDate' => '2026-09-15',
        ])->assertCreated();
        $this->actingAs($user, 'api')->postJson("/employees/{$id}/hr-requests", [
            'type' => 'WORK_CERTIFICATE', 'subject' => 'Attestation pour la banque',
        ])->assertCreated();

        $this->actingAs($user, 'api')->deleteJson("/employees/{$id}")->assertOk();

        $this->assertSame(0, Employee::count());
        $this->assertSame(0, EmployeeLeave::count());
    }

    // ── Embauche depuis une candidature ───────────────────────────────────

    /** @return array{0: User, 1: Candidature} */
    private function acceptedCandidature(string $status = 'APPROUVEE'): array
    {
        $company = $this->company();
        $rh = $this->enterprise($company);
        $candidat = User::create([
            'name' => 'Marie Dubois',
            'email' => 'marie.dubois@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'phone' => '+225 07 39 47 82',
            'location' => 'Abidjan, Côte d\'Ivoire',
        ]);
        $offre = JobOffer::create([
            'title' => 'Développeuse Full-Stack',
            'type' => 'CDI',
            'company_id' => $company->id,
            'status' => 'ACTIVE',
        ]);
        $candidature = Candidature::create([
            'candidate_name' => $candidat->name,
            'candidate_email' => $candidat->email,
            'status' => $status,
            'score' => 91,
            'applied_date' => '2026-08-20',
            'user_id' => $candidat->id,
            'job_offer_id' => $offre->id,
        ]);

        return [$rh, $candidature];
    }

    public function test_accepted_candidatures_are_offered_with_goriya_data_prefilled(): void
    {
        [$rh, $candidature] = $this->acceptedCandidature();

        $this->actingAs($rh, 'api')
            ->getJson('/employees/hireable-candidatures')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $candidature->id)
            ->assertJsonPath('0.jobOfferTitle', 'Développeuse Full-Stack')
            ->assertJsonPath('0.prefill.firstName', 'Marie')
            ->assertJsonPath('0.prefill.lastName', 'Dubois')
            ->assertJsonPath('0.prefill.phone', '+225 07 39 47 82')
            ->assertJsonPath('0.prefill.address', 'Abidjan, Côte d\'Ivoire')
            ->assertJsonPath('0.prefill.jobTitle', 'Développeuse Full-Stack')
            ->assertJsonPath('0.prefill.contractType', 'CDI');
    }

    public function test_a_candidature_is_hired_once(): void
    {
        [$rh, $candidature] = $this->acceptedCandidature();
        $payload = $this->employeePayload([
            'candidatureId' => $candidature->id,
            'firstName' => 'Marie',
            'lastName' => 'Dubois',
            'email' => 'marie.dubois@example.ci',
        ]);

        $this->actingAs($rh, 'api')
            ->postJson('/employees', $payload)
            ->assertCreated()
            ->assertJsonPath('source', 'CANDIDATURE')
            ->assertJsonPath('userId', $candidature->user_id)
            ->assertJsonPath('hiredFrom.jobOfferTitle', 'Développeuse Full-Stack');

        $this->actingAs($rh, 'api')
            ->getJson('/employees/hireable-candidatures')
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAs($rh, 'api')
            ->postJson('/employees', array_merge($payload, ['email' => 'doublon@example.ci']))
            ->assertStatus(400);
        $this->assertSame(1, Employee::count());
    }

    public function test_a_candidature_not_accepted_cannot_be_hired(): void
    {
        [$rh, $candidature] = $this->acceptedCandidature('EN_ATTENTE');

        $this->actingAs($rh, 'api')->getJson('/employees/hireable-candidatures')->assertJsonCount(0);
        $this->actingAs($rh, 'api')
            ->postJson('/employees', $this->employeePayload(['candidatureId' => $candidature->id]))
            ->assertStatus(400);
    }

    // ── Congés ────────────────────────────────────────────────────────────

    public function test_a_leave_counts_business_days_and_rejects_overlaps(): void
    {
        [$user, $id] = $this->enterpriseWithEmployee();

        // Lundi 14 → dimanche 20 : cinq jours ouvrés.
        $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/leaves", ['type' => 'PAID', 'startDate' => '2026-09-14', 'endDate' => '2026-09-20'])
            ->assertCreated()
            ->assertJsonPath('days', 5)
            ->assertJsonPath('status', 'PENDING');

        $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/leaves", ['type' => 'SICK', 'startDate' => '2026-09-18', 'endDate' => '2026-09-22'])
            ->assertStatus(400);

        // Un week-end seul ne contient aucun jour ouvré.
        $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/leaves", ['type' => 'PAID', 'startDate' => '2026-09-26', 'endDate' => '2026-09-27'])
            ->assertStatus(400);

        $this->actingAs($user, 'api')
            ->getJson("/employees/{$id}")
            ->assertJsonPath('pendingLeavesCount', 1)
            ->assertJsonPath('leaveBalance.pending', 5)
            ->assertJsonPath('leaveBalance.remaining', 26);
    }

    public function test_a_paid_leave_is_approved_within_the_balance_only(): void
    {
        [$user, $id] = $this->enterpriseWithEmployee(['annualLeaveDays' => 3]);

        $leave = $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/leaves", ['type' => 'PAID', 'startDate' => '2026-09-14', 'endDate' => '2026-09-18'])
            ->json('id');

        $this->actingAs($user, 'api')
            ->patchJson("/employee-leaves/{$leave}/status", ['status' => 'APPROVED'])
            ->assertStatus(400);

        // Un arrêt maladie ne puise pas dans le solde de congés payés.
        $maladie = $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/leaves", ['type' => 'SICK', 'startDate' => '2026-10-05', 'endDate' => '2026-10-09'])
            ->json('id');
        $this->actingAs($user, 'api')
            ->patchJson("/employee-leaves/{$maladie}/status", ['status' => 'APPROVED', 'comment' => 'Certificat reçu'])
            ->assertOk()
            ->assertJsonPath('status', 'APPROVED')
            ->assertJsonPath('decisionComment', 'Certificat reçu')
            ->assertJsonPath('decidedByName', $user->name);
    }

    public function test_approved_leave_updates_the_balance_and_can_only_be_deleted_not_cancelled(): void
    {
        [$user, $id] = $this->enterpriseWithEmployee();

        $leave = $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/leaves", ['type' => 'PAID', 'startDate' => '2026-09-14', 'endDate' => '2026-09-18'])
            ->json('id');
        $this->actingAs($user, 'api')
            ->patchJson("/employee-leaves/{$leave}/status", ['status' => 'APPROVED'])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->getJson("/employees/{$id}")
            ->assertJsonPath('leaveBalance.taken', 5)
            ->assertJsonPath('leaveBalance.remaining', 21);

        // Une décision finale (y compris l'annulation) ne se rouvre plus une fois approuvée.
        $this->actingAs($user, 'api')
            ->patchJson("/employee-leaves/{$leave}/status", ['status' => 'CANCELLED'])
            ->assertStatus(400);

        $this->actingAs($user, 'api')->deleteJson("/employee-leaves/{$leave}")->assertOk();
    }

    public function test_leaves_can_be_deleted_in_bulk(): void
    {
        [$user, $id] = $this->enterpriseWithEmployee();

        $pending = $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/leaves", ['type' => 'SICK', 'startDate' => '2026-09-14', 'endDate' => '2026-09-15'])
            ->json('id');
        $approved = $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/leaves", ['type' => 'PAID', 'startDate' => '2026-10-05', 'endDate' => '2026-10-06'])
            ->json('id');
        $this->actingAs($user, 'api')
            ->patchJson("/employee-leaves/{$approved}/status", ['status' => 'APPROVED'])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->postJson('/employee-leaves/bulk-delete', ['ids' => [$pending, $approved]])
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->actingAs($user, 'api')->getJson('/employee-leaves')->assertJsonCount(0);
    }

    public function test_an_employee_on_approved_leave_today_is_flagged(): void
    {
        [$user, $id] = $this->enterpriseWithEmployee();
        $leave = $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/leaves", ['type' => 'SICK', 'startDate' => '2026-09-09', 'endDate' => '2026-09-11'])
            ->json('id');

        $this->actingAs($user, 'api')->getJson('/employees')->assertJsonPath('0.onLeaveToday', false);

        $this->actingAs($user, 'api')->patchJson("/employee-leaves/{$leave}/status", ['status' => 'APPROVED']);
        $this->actingAs($user, 'api')->getJson('/employees')->assertJsonPath('0.onLeaveToday', true);
    }

    public function test_a_departed_employee_cannot_take_leave_but_can_request_a_certificate(): void
    {
        [$user, $id] = $this->enterpriseWithEmployee(['status' => 'TERMINATED']);

        $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/leaves", ['type' => 'PAID', 'startDate' => '2026-09-14', 'endDate' => '2026-09-15'])
            ->assertStatus(400);

        $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/hr-requests", ['type' => 'WORK_CERTIFICATE', 'subject' => 'Attestation de fin de contrat'])
            ->assertCreated();
    }

    // ── Demandes RH ───────────────────────────────────────────────────────

    public function test_an_hr_request_goes_through_its_workflow(): void
    {
        [$user, $id] = $this->enterpriseWithEmployee();

        $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/hr-requests", ['type' => 'SALARY_ADVANCE', 'subject' => 'Avance rentrée scolaire'])
            ->assertStatus(400)
            ->assertJsonPath('message', "Indiquez le montant de l'avance demandée.");

        $demande = $this->actingAs($user, 'api')
            ->postJson("/employees/{$id}/hr-requests", ['type' => 'SALARY_ADVANCE', 'subject' => 'Avance rentrée scolaire', 'amount' => 150000])
            ->assertCreated()
            ->assertJsonPath('status', 'PENDING')
            ->json('id');

        $this->actingAs($user, 'api')
            ->getJson("/employees/{$id}")
            ->assertJsonPath('pendingRequestsCount', 1);

        $this->actingAs($user, 'api')
            ->patchJson("/hr-requests/{$demande}/status", ['status' => 'IN_PROGRESS'])
            ->assertOk()
            ->assertJsonPath('decidedByName', null);

        $this->actingAs($user, 'api')
            ->patchJson("/hr-requests/{$demande}/status", ['status' => 'APPROVED', 'comment' => 'Versée sur la paie de septembre'])
            ->assertOk()
            ->assertJsonPath('status', 'APPROVED')
            ->assertJsonPath('decidedByName', $user->name);

        $this->actingAs($user, 'api')->deleteJson("/hr-requests/{$demande}")->assertStatus(400);
        $this->actingAs($user, 'api')
            ->getJson("/employees/{$id}/hr-requests")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.amount', 150000);
    }
}
