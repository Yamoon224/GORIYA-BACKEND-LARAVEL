<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\Company;
use App\Models\EmployeeSurvey;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Évaluations (EmployeeSurvey) dans l'espace employé : ciblage par
 * département, échéance auto-clôturante, et réponse via la fiche Employee
 * (pas via User.company_id, toujours vide pour un compte USER).
 */
class EmployeeSurveyTargetingTest extends TestCase
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

    /** Embauche $email dans $company, département $department, et retourne le User employé. */
    private function hireEmployee(Company $company, User $rh, string $email, ?string $department): User
    {
        $candidat = User::create([
            'name' => 'Employé '.$email,
            'email' => $email,
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
        $offre = JobOffer::create([
            'title' => 'Poste', 'type' => 'CDI', 'company_id' => $company->id, 'status' => 'ACTIVE',
        ]);
        $candidature = Candidature::create([
            'candidate_name' => $candidat->name, 'candidate_email' => $candidat->email,
            'status' => 'APPROUVEE', 'score' => 80, 'applied_date' => '2026-08-20',
            'user_id' => $candidat->id, 'job_offer_id' => $offre->id,
        ]);

        $this->actingAs($rh, 'api')->postJson('/employees', [
            'candidatureId' => $candidature->id,
            'firstName' => 'X', 'lastName' => 'Y', 'email' => $email,
            'jobTitle' => 'Poste', 'department' => $department,
            'contractType' => 'CDI', 'hireDate' => '2026-09-10',
        ])->assertCreated();

        return $candidat;
    }

    public function test_a_department_targeted_evaluation_is_only_visible_to_that_department(): void
    {
        $company = $this->company();
        $rh = $this->enterprise($company);
        $dev = $this->hireEmployee($company, $rh, 'dev@example.ci', 'Ingénierie');
        $sales = $this->hireEmployee($company, $rh, 'sales@example.ci', 'Ventes');

        $survey = $this->actingAs($rh, 'api')->postJson('/employee-surveys', [
            'title' => 'Satisfaction Ingénierie',
            'questions' => [['id' => 'q1', 'question' => 'Ça va ?', 'type' => 'RATING']],
            'department' => 'Ingénierie',
        ])->json('id');
        $this->actingAs($rh, 'api')->patchJson("/employee-surveys/{$survey}/status", ['status' => 'ACTIVE'])->assertOk();

        $this->actingAs($dev, 'api')
            ->getJson('/me/employee/evaluations')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.department', 'Ingénierie');

        $this->actingAs($sales, 'api')->getJson('/me/employee/evaluations')->assertOk()->assertJsonCount(0);
    }

    public function test_a_company_wide_evaluation_is_visible_to_every_department(): void
    {
        $company = $this->company();
        $rh = $this->enterprise($company);
        $dev = $this->hireEmployee($company, $rh, 'dev2@example.ci', 'Ingénierie');

        $survey = $this->actingAs($rh, 'api')->postJson('/employee-surveys', [
            'title' => 'Satisfaction générale',
            'questions' => [['id' => 'q1', 'question' => 'Ça va ?', 'type' => 'RATING']],
        ])->json('id');
        $this->actingAs($rh, 'api')->patchJson("/employee-surveys/{$survey}/status", ['status' => 'ACTIVE']);

        $this->actingAs($dev, 'api')->getJson('/me/employee/evaluations')->assertOk()->assertJsonCount(1);
    }

    public function test_an_employee_submits_a_response_through_their_employee_record(): void
    {
        $company = $this->company();
        $rh = $this->enterprise($company);
        $dev = $this->hireEmployee($company, $rh, 'dev3@example.ci', 'Ingénierie');

        $survey = $this->actingAs($rh, 'api')->postJson('/employee-surveys', [
            'title' => 'Satisfaction',
            'questions' => [['id' => 'q1', 'question' => 'Ça va ?', 'type' => 'RATING']],
        ])->json('id');
        $this->actingAs($rh, 'api')->patchJson("/employee-surveys/{$survey}/status", ['status' => 'ACTIVE']);

        $this->actingAs($dev, 'api')
            ->postJson("/employee-surveys/{$survey}/responses", ['answers' => [['questionId' => 'q1', 'value' => 5]]])
            ->assertOk();
    }

    public function test_an_employee_cannot_respond_to_another_departments_evaluation(): void
    {
        $company = $this->company();
        $rh = $this->enterprise($company);
        $sales = $this->hireEmployee($company, $rh, 'sales2@example.ci', 'Ventes');

        $survey = $this->actingAs($rh, 'api')->postJson('/employee-surveys', [
            'title' => 'Satisfaction Ingénierie',
            'questions' => [['id' => 'q1', 'question' => 'Ça va ?', 'type' => 'RATING']],
            'department' => 'Ingénierie',
        ])->json('id');
        $this->actingAs($rh, 'api')->patchJson("/employee-surveys/{$survey}/status", ['status' => 'ACTIVE']);

        $this->actingAs($sales, 'api')
            ->postJson("/employee-surveys/{$survey}/responses", ['answers' => [['questionId' => 'q1', 'value' => 5]]])
            ->assertStatus(403);
    }

    public function test_an_outsider_cannot_respond(): void
    {
        $company = $this->company();
        $rh = $this->enterprise($company);
        $outsider = User::create([
            'name' => 'Personne extérieure', 'email' => 'outsider@example.ci',
            'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE',
        ]);

        $survey = $this->actingAs($rh, 'api')->postJson('/employee-surveys', [
            'title' => 'Satisfaction',
            'questions' => [['id' => 'q1', 'question' => 'Ça va ?', 'type' => 'RATING']],
        ])->json('id');
        $this->actingAs($rh, 'api')->patchJson("/employee-surveys/{$survey}/status", ['status' => 'ACTIVE']);

        $this->actingAs($outsider, 'api')
            ->postJson("/employee-surveys/{$survey}/responses", ['answers' => [['questionId' => 'q1', 'value' => 5]]])
            ->assertStatus(403);
    }

    public function test_expired_active_evaluations_are_closed_by_the_command(): void
    {
        $company = $this->company();
        $rh = $this->enterprise($company);

        $survey = $this->actingAs($rh, 'api')->postJson('/employee-surveys', [
            'title' => 'Satisfaction',
            'questions' => [['id' => 'q1', 'question' => 'Ça va ?', 'type' => 'RATING']],
            'dueDate' => '2026-09-01',
        ])->json('id');
        $this->actingAs($rh, 'api')->patchJson("/employee-surveys/{$survey}/status", ['status' => 'ACTIVE']);

        Carbon::setTestNow('2026-09-15');
        $this->artisan('surveys:close-expired')->assertExitCode(0);
        Carbon::setTestNow();

        $this->assertSame('CLOSED', EmployeeSurvey::find($survey)->status->value);
    }
}
