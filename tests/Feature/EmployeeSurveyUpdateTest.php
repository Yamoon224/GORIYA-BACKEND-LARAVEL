<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Modification d'une évaluation : titre/échéance/département restent
 * modifiables à tout moment, les questions ne le sont plus dès qu'une
 * réponse a été reçue.
 */
class EmployeeSurveyUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'Goriya Test SARL', 'sector' => 'Technologie', 'status' => 'ACTIVE', 'partnership_date' => '2026-01-01',
        ]);
    }

    private function enterprise(Company $company): User
    {
        return User::create([
            'name' => $company->name, 'email' => 'rh@example.ci', 'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE', 'status' => 'ACTIVE', 'company_id' => $company->id,
        ]);
    }

    public function test_title_and_targeting_are_editable_anytime(): void
    {
        $rh = $this->enterprise($this->company());
        $survey = $this->actingAs($rh, 'api')->postJson('/employee-surveys', [
            'title' => 'Satisfaction',
            'questions' => [['id' => 'q1', 'question' => 'Ça va ?', 'type' => 'RATING']],
        ])->json('id');

        $this->actingAs($rh, 'api')
            ->patchJson("/employee-surveys/{$survey}", ['title' => 'Satisfaction Q3', 'department' => 'Ventes', 'dueDate' => '2026-12-01'])
            ->assertOk()
            ->assertJsonPath('title', 'Satisfaction Q3')
            ->assertJsonPath('department', 'Ventes')
            ->assertJsonPath('dueDate', '2026-12-01')
            ->assertJsonPath('hasResponses', false);
    }

    public function test_questions_are_locked_once_a_response_exists(): void
    {
        $company = $this->company();
        $rh = $this->enterprise($company);
        $candidat = User::create([
            'name' => 'Marie Dubois', 'email' => 'marie@example.ci', 'password' => 'motdepasse-solide',
            'role' => 'USER', 'status' => 'ACTIVE',
        ]);
        $offre = \App\Models\JobOffer::create(['title' => 'Poste', 'type' => 'CDI', 'company_id' => $company->id, 'status' => 'ACTIVE']);
        $candidature = \App\Models\Candidature::create([
            'candidate_name' => $candidat->name, 'candidate_email' => $candidat->email,
            'status' => 'APPROUVEE', 'score' => 80, 'applied_date' => '2026-08-20',
            'user_id' => $candidat->id, 'job_offer_id' => $offre->id,
        ]);
        $this->actingAs($rh, 'api')->postJson('/employees', [
            'candidatureId' => $candidature->id, 'firstName' => 'Marie', 'lastName' => 'Dubois',
            'email' => 'marie@example.ci', 'jobTitle' => 'Poste', 'contractType' => 'CDI', 'hireDate' => '2026-09-10',
        ])->assertCreated();

        $survey = $this->actingAs($rh, 'api')->postJson('/employee-surveys', [
            'title' => 'Satisfaction',
            'questions' => [['id' => 'q1', 'question' => 'Ça va ?', 'type' => 'RATING']],
        ])->json('id');
        $this->actingAs($rh, 'api')->patchJson("/employee-surveys/{$survey}/status", ['status' => 'ACTIVE']);

        $this->actingAs($candidat, 'api')
            ->postJson("/employee-surveys/{$survey}/responses", ['answers' => [['questionId' => 'q1', 'value' => 5]]])
            ->assertOk();

        // Retirer sa réponse une seconde fois : refusé (contrainte unique).
        $this->actingAs($candidat, 'api')
            ->postJson("/employee-surveys/{$survey}/responses", ['answers' => [['questionId' => 'q1', 'value' => 3]]])
            ->assertStatus(409);

        // Les questions ne sont plus modifiables...
        $this->actingAs($rh, 'api')
            ->patchJson("/employee-surveys/{$survey}", ['questions' => [['id' => 'q1', 'question' => 'Changée ?', 'type' => 'TEXT']]])
            ->assertStatus(400);

        // ...mais le titre reste modifiable.
        $this->actingAs($rh, 'api')
            ->patchJson("/employee-surveys/{$survey}", ['title' => 'Nouveau titre'])
            ->assertOk()
            ->assertJsonPath('hasResponses', true);
    }
}
