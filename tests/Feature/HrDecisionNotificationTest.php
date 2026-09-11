<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\Company;
use App\Models\JobOffer;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Décision de l'entreprise sur une demande de congé ou une demande RH ->
 * notification système pour l'employé (voir NotificationService::
 * notifyLeaveDecided / notifyHrRequestDecided).
 */
class HrDecisionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'Goriya Test SARL', 'sector' => 'Technologie',
            'status' => 'ACTIVE', 'partnership_date' => '2026-01-01',
        ]);
    }

    private function enterprise(Company $company): User
    {
        return User::create([
            'name' => $company->name, 'email' => 'rh@example.ci', 'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE', 'status' => 'ACTIVE', 'company_id' => $company->id,
        ]);
    }

    /** @return array{0: User, 1: string} RH, id employé */
    private function hiredEmployee(): array
    {
        $company = $this->company();
        $rh = $this->enterprise($company);
        $candidat = User::create([
            'name' => 'Marie Dubois', 'email' => 'marie@example.ci', 'password' => 'motdepasse-solide',
            'role' => 'USER', 'status' => 'ACTIVE',
        ]);
        $offre = JobOffer::create(['title' => 'Poste', 'type' => 'CDI', 'company_id' => $company->id, 'status' => 'ACTIVE']);
        $candidature = Candidature::create([
            'candidate_name' => $candidat->name, 'candidate_email' => $candidat->email,
            'status' => 'APPROUVEE', 'score' => 80, 'applied_date' => '2026-08-20',
            'user_id' => $candidat->id, 'job_offer_id' => $offre->id,
        ]);
        $employeeId = $this->actingAs($rh, 'api')->postJson('/employees', [
            'candidatureId' => $candidature->id, 'firstName' => 'Marie', 'lastName' => 'Dubois',
            'email' => 'marie@example.ci', 'jobTitle' => 'Poste', 'contractType' => 'CDI', 'hireDate' => '2026-09-10',
        ])->json('id');

        return [$rh, $employeeId];
    }

    public function test_approving_a_leave_notifies_the_employee(): void
    {
        [$rh, $employeeId] = $this->hiredEmployee();
        $candidat = User::where('email', 'marie@example.ci')->firstOrFail();

        $leave = $this->actingAs($rh, 'api')->postJson("/employees/{$employeeId}/leaves", [
            'type' => 'SICK', 'startDate' => '2026-09-14', 'endDate' => '2026-09-15',
        ])->json('id');

        $this->actingAs($rh, 'api')->patchJson("/employee-leaves/{$leave}/status", ['status' => 'APPROVED'])->assertOk();

        $this->assertTrue(
            Notification::where('user_id', $candidat->id)->where('title', 'Demande de congé mise à jour')->exists()
        );
    }

    public function test_approving_an_hr_request_notifies_the_employee(): void
    {
        [$rh, $employeeId] = $this->hiredEmployee();
        $candidat = User::where('email', 'marie@example.ci')->firstOrFail();

        $req = $this->actingAs($rh, 'api')->postJson("/employees/{$employeeId}/hr-requests", [
            'type' => 'WORK_CERTIFICATE', 'subject' => 'Attestation pour la banque',
        ])->json('id');

        $this->actingAs($rh, 'api')->patchJson("/hr-requests/{$req}/status", ['status' => 'APPROVED'])->assertOk();

        $this->assertTrue(
            Notification::where('user_id', $candidat->id)->where('title', 'Demande RH mise à jour')->exists()
        );
    }

    public function test_taking_a_request_in_progress_does_not_notify(): void
    {
        [$rh, $employeeId] = $this->hiredEmployee();
        $candidat = User::where('email', 'marie@example.ci')->firstOrFail();

        $req = $this->actingAs($rh, 'api')->postJson("/employees/{$employeeId}/hr-requests", [
            'type' => 'WORK_CERTIFICATE', 'subject' => 'Attestation pour la banque',
        ])->json('id');

        $this->actingAs($rh, 'api')->patchJson("/hr-requests/{$req}/status", ['status' => 'IN_PROGRESS'])->assertOk();

        $this->assertFalse(
            Notification::where('user_id', $candidat->id)->where('title', 'Demande RH mise à jour')->exists()
        );
    }
}
