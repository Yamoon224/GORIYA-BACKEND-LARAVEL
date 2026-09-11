<?php

namespace Tests\Feature;

use App\Mail\EmployeeHiredMail;
use App\Models\Candidature;
use App\Models\Company;
use App\Models\JobOffer;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Embauche → notifications, et espace employé (standard/app/(protected)/espace-employe) :
 * un compte USER accède à sa propre fiche et à ses congés/demandes RH quand il
 * a été (ou est) embauché sur Goriya — jamais à ceux d'un autre.
 */
class EmployeeSelfServiceTest extends TestCase
{
    use RefreshDatabase;

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

    /** @return array{0: User, 1: User, 2: Candidature} Entreprise, candidat, candidature acceptée. */
    private function acceptedCandidature(?string $email = null): array
    {
        $email ??= 'marie.dubois@example.ci';
        $company = $this->company('Goriya Test SARL '.$email);
        $rh = $this->enterprise($company);
        $candidat = User::create([
            'name' => 'Marie Dubois',
            'email' => $email,
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
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
            'status' => 'APPROUVEE',
            'score' => 91,
            'applied_date' => '2026-08-20',
            'user_id' => $candidat->id,
            'job_offer_id' => $offre->id,
        ]);

        return [$rh, $candidat, $candidature];
    }

    public function test_hiring_from_a_candidature_notifies_in_app_and_by_email(): void
    {
        Mail::fake();
        [$rh, $candidat, $candidature] = $this->acceptedCandidature();

        $this->actingAs($rh, 'api')->postJson('/employees', [
            'candidatureId' => $candidature->id,
            'firstName' => 'Marie',
            'lastName' => 'Dubois',
            'email' => 'marie.dubois@example.ci',
            'jobTitle' => 'Développeuse Full-Stack',
            'contractType' => 'CDI',
            'hireDate' => '2026-09-10',
        ])->assertCreated();

        $this->assertSame(1, Notification::where('user_id', $candidat->id)->where('type', 'SYSTEM')->count());
        Mail::assertSent(EmployeeHiredMail::class, fn ($mail) => $mail->hasTo('marie.dubois@example.ci'));
    }

    public function test_manual_entry_is_only_notified_by_email(): void
    {
        Mail::fake();
        $rh = $this->enterprise($this->company());

        $this->actingAs($rh, 'api')->postJson('/employees', [
            'firstName' => 'Yao',
            'lastName' => 'Koffi',
            'email' => 'yao.koffi@example.ci',
            'jobTitle' => 'Comptable',
            'contractType' => 'CDI',
            'hireDate' => '2026-09-10',
        ])->assertCreated();

        $this->assertSame(0, Notification::count());
        Mail::assertSent(EmployeeHiredMail::class, fn ($mail) => $mail->hasTo('yao.koffi@example.ci'));
    }

    public function test_someone_never_employed_gets_a_null_employee_and_404_on_subresources(): void
    {
        $user = User::create([
            'name' => 'Jamais embauché',
            'email' => 'jamais@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($user, 'api')->getJson('/me/employee')->assertOk()->assertSee('null', false);
        $this->actingAs($user, 'api')->getJson('/me/employee/leaves')->assertNotFound();
        $this->actingAs($user, 'api')->postJson('/me/employee/hr-requests', ['type' => 'WORK_CERTIFICATE', 'subject' => 'Attestation'])->assertNotFound();
    }

    public function test_an_employee_manages_their_own_leaves_and_hr_requests(): void
    {
        Mail::fake();
        [$rh, $candidat, $candidature] = $this->acceptedCandidature();
        $this->actingAs($rh, 'api')->postJson('/employees', [
            'candidatureId' => $candidature->id,
            'firstName' => 'Marie',
            'lastName' => 'Dubois',
            'email' => 'marie.dubois@example.ci',
            'jobTitle' => 'Développeuse Full-Stack',
            'contractType' => 'CDI',
            'hireDate' => '2026-09-10',
        ])->assertCreated();

        $this->actingAs($candidat, 'api')
            ->getJson('/me/employee')
            ->assertOk()
            ->assertJsonPath('fullName', 'Marie Dubois')
            ->assertJsonMissingPath('salary')
            ->assertJsonMissingPath('notes');

        $leaveId = $this->actingAs($candidat, 'api')
            ->postJson('/me/employee/leaves', ['type' => 'PAID', 'startDate' => '2026-09-14', 'endDate' => '2026-09-15'])
            ->assertCreated()
            ->json('id');

        $this->actingAs($candidat, 'api')->getJson('/me/employee/leaves')->assertOk()->assertJsonCount(1);
        $this->actingAs($candidat, 'api')->deleteJson("/me/employee/leaves/{$leaveId}")->assertOk();

        $this->actingAs($candidat, 'api')
            ->postJson('/me/employee/hr-requests', ['type' => 'WORK_CERTIFICATE', 'subject' => 'Attestation pour un pret'])
            ->assertCreated();
        $this->actingAs($candidat, 'api')->getJson('/me/employee/hr-requests')->assertOk()->assertJsonCount(1);
    }

    public function test_a_terminated_employee_can_read_history_but_not_submit_new_requests(): void
    {
        [$rh, $candidat, $candidature] = $this->acceptedCandidature();
        $employeeId = $this->actingAs($rh, 'api')->postJson('/employees', [
            'candidatureId' => $candidature->id,
            'firstName' => 'Marie',
            'lastName' => 'Dubois',
            'email' => 'marie.dubois@example.ci',
            'jobTitle' => 'Développeuse Full-Stack',
            'contractType' => 'CDI',
            'hireDate' => '2026-09-10',
        ])->json('id');
        $this->actingAs($rh, 'api')->patchJson("/employees/{$employeeId}", ['status' => 'TERMINATED'])->assertOk();

        $this->actingAs($candidat, 'api')->getJson('/me/employee')->assertOk()->assertJsonPath('status', 'TERMINATED');
        $this->actingAs($candidat, 'api')
            ->postJson('/me/employee/leaves', ['type' => 'PAID', 'startDate' => '2026-09-14', 'endDate' => '2026-09-15'])
            ->assertStatus(400);
        $this->actingAs($candidat, 'api')
            ->postJson('/me/employee/hr-requests', ['type' => 'WORK_CERTIFICATE', 'subject' => 'Attestation de fin de contrat'])
            ->assertStatus(400);
    }

    public function test_an_employee_cannot_touch_another_employees_leave(): void
    {
        [$rh, $candidat, $candidature] = $this->acceptedCandidature();
        $this->actingAs($rh, 'api')->postJson('/employees', [
            'candidatureId' => $candidature->id,
            'firstName' => 'Marie',
            'lastName' => 'Dubois',
            'email' => 'marie.dubois@example.ci',
            'jobTitle' => 'Développeuse Full-Stack',
            'contractType' => 'CDI',
            'hireDate' => '2026-09-10',
        ]);
        $leaveId = $this->actingAs($candidat, 'api')
            ->postJson('/me/employee/leaves', ['type' => 'PAID', 'startDate' => '2026-09-14', 'endDate' => '2026-09-15'])
            ->json('id');

        // Un autre employé, embauché lui aussi depuis une candidature (donc
        // avec sa propre fiche/`user_id`) — même entreprise ou non, peu importe.
        [$autreRh, $autre, $autreCandidature] = $this->acceptedCandidature('autre.employe@example.ci');
        $this->actingAs($autreRh, 'api')->postJson('/employees', [
            'candidatureId' => $autreCandidature->id,
            'firstName' => 'Paul', 'lastName' => 'Koffi', 'jobTitle' => 'Dev', 'contractType' => 'CDI', 'hireDate' => '2026-09-10',
        ])->assertCreated();

        $this->actingAs($autre, 'api')->deleteJson("/me/employee/leaves/{$leaveId}")->assertNotFound();
    }
}
