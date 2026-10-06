<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Mission et valeurs » de la fiche entreprise (entreprise/app/(protected)/
 * profil/content.tsx) : jusqu'ici un champ texte libre non persisté — il
 * dupliquait `about` à l'affichage et ne sauvegardait jamais rien. Devient
 * une liste choisie parmi un référentiel fixe, stockée dans
 * `companies.company_values` (exposée comme `values` côté API).
 */
class CompanyValuesTest extends TestCase
{
    use RefreshDatabase;

    private function companyAndOwner(): array
    {
        $company = Company::create([
            'name' => 'Goriya Test SARL',
            'sector' => 'Technologie',
            'status' => 'ACTIVE',
            'partnership_date' => '2026-01-01',
        ]);

        $owner = User::create([
            'name' => $company->name,
            'email' => 'contact@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
            'company_id' => $company->id,
        ]);

        return [$company, $owner];
    }

    public function test_les_valeurs_choisies_sont_enregistrees_et_renvoyees(): void
    {
        [$company, $owner] = $this->companyAndOwner();

        $res = $this->actingAs($owner, 'api')
            ->patchJson("/companies/{$company->id}", [
                'values' => ['Innovation', 'Intégrité', "Esprit d'équipe"],
            ])
            ->assertOk()
            ->json();

        $this->assertSame(['Innovation', 'Intégrité', "Esprit d'équipe"], $res['values']);
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
        $this->assertSame(['Innovation', 'Intégrité', "Esprit d'équipe"], $company->fresh()->company_values);
    }

    public function test_les_liens_sociaux_restent_fonctionnels_apres_le_renommage_du_decodeur(): void
    {
        [$company, $owner] = $this->companyAndOwner();

        $res = $this->actingAs($owner, 'api')
            ->patchJson("/companies/{$company->id}", [
                'socialLinks' => ['https://linkedin.com/company/goriya', 'https://facebook.com/goriya'],
            ])
            ->assertOk()
            ->json();

        $this->assertSame(['https://linkedin.com/company/goriya', 'https://facebook.com/goriya'], $res['socialLinks']);
    }

    public function test_une_mise_a_jour_qui_ne_touche_pas_values_ne_l_ecrase_pas(): void
    {
        [$company, $owner] = $this->companyAndOwner();

        $this->actingAs($owner, 'api')
            ->patchJson("/companies/{$company->id}", ['values' => ['Innovation']])
            ->assertOk();

        $res = $this->actingAs($owner, 'api')
            ->patchJson("/companies/{$company->id}", ['about' => 'Nouvelle présentation'])
            ->assertOk()
            ->json();

        $this->assertSame(['Innovation'], $res['values']);
    }
}
