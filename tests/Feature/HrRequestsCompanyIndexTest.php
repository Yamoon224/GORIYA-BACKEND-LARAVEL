<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Page Demandes RH (services-rh) : jusqu'ici une demande n'était visible que
 * depuis la fiche de son employé — aucune vue d'ensemble côté entreprise.
 */
class HrRequestsCompanyIndexTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name = 'Goriya Test SARL'): Company
    {
        return Company::create([
            'name' => $name, 'sector' => 'Technologie', 'status' => 'ACTIVE', 'partnership_date' => '2026-01-01',
        ]);
    }

    private function enterprise(Company $company): User
    {
        return User::create([
            'name' => $company->name, 'email' => 'rh-'.$company->id.'@example.ci', 'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE', 'status' => 'ACTIVE', 'company_id' => $company->id,
        ]);
    }

    public function test_company_index_lists_requests_across_all_employees(): void
    {
        $company = $this->company();
        $rh = $this->enterprise($company);

        $e1 = $this->actingAs($rh, 'api')->postJson('/employees', [
            'firstName' => 'Aïcha', 'lastName' => 'Koné', 'jobTitle' => 'Designer', 'department' => 'Design',
            'contractType' => 'CDI', 'hireDate' => '2026-01-10',
        ])->json('id');
        $e2 = $this->actingAs($rh, 'api')->postJson('/employees', [
            'firstName' => 'Yao', 'lastName' => 'Koffi', 'jobTitle' => 'Comptable', 'department' => 'Finance',
            'contractType' => 'CDI', 'hireDate' => '2026-01-10',
        ])->json('id');

        $this->actingAs($rh, 'api')->postJson("/employees/{$e1}/hr-requests", [
            'type' => 'WORK_CERTIFICATE', 'subject' => 'Attestation pour la banque',
        ])->assertCreated();
        $this->actingAs($rh, 'api')->postJson("/employees/{$e2}/hr-requests", [
            'type' => 'SALARY_ADVANCE', 'subject' => 'Avance', 'amount' => 50000,
        ])->assertCreated();

        $response = $this->actingAs($rh, 'api')->getJson('/hr-requests')->assertOk();
        $response->assertJsonCount(2);
        $this->assertNotNull($response->json('0.employee.fullName'));

        // Filtre par département : n'affiche que les demandes de ce département.
        $this->actingAs($rh, 'api')
            ->getJson('/hr-requests?department=Finance')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.employee.department', 'Finance');
    }

    public function test_company_index_is_isolated_per_company(): void
    {
        $companyA = $this->company('A');
        $rhA = $this->enterprise($companyA);
        $employeeA = $this->actingAs($rhA, 'api')->postJson('/employees', [
            'firstName' => 'A', 'lastName' => 'A', 'jobTitle' => 'X', 'contractType' => 'CDI', 'hireDate' => '2026-01-10',
        ])->json('id');
        $this->actingAs($rhA, 'api')->postJson("/employees/{$employeeA}/hr-requests", [
            'type' => 'WORK_CERTIFICATE', 'subject' => 'Attestation',
        ]);

        $rhB = $this->enterprise($this->company('B'));
        $this->actingAs($rhB, 'api')->getJson('/hr-requests')->assertOk()->assertJsonCount(0);
    }
}
