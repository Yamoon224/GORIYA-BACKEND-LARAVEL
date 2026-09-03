<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Inscription entreprise (POST /companies).
 *
 * Couvre la régression qui rendait l'inscription opaque : un email déjà pris
 * n'était détecté qu'à l'INSERT, et HandlesUniqueViolations ne reconnaissait
 * que le SQLSTATE PostgreSQL (23505) — sur MySQL (SGBD de production) la
 * QueryException était donc relancée telle quelle et l'utilisateur recevait un
 * 500 sans message exploitable.
 */
class CompanySignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'companyName' => 'Goriya Test SARL',
            'sector' => 'Technologie',
            'about' => 'Une entreprise de test.',
            'creationDate' => '2020-01-15',
            'companySize' => '11-50 employés',
            'country' => "Côte d'Ivoire",
            'headquarters' => 'Abidjan',
            'location' => 'Abidjan, CI',
            'phone' => '+225 07 00 00 00 00',
            'email' => 'contact@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'partnershipDate' => '2026-09-03',
            'status' => 'ACTIVE',
        ], $overrides);
    }

    public function test_it_creates_the_company_and_its_enterprise_user(): void
    {
        $response = $this->postJson('/companies', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('requiresOtp', true);

        $this->assertDatabaseHas('companies', ['email' => 'contact@goriya-test.ci']);

        $user = User::where('email', 'contact@goriya-test.ci')->firstOrFail();
        $this->assertSame('ENTREPRISE', $user->role->value);
        $this->assertNotNull($user->company_id);
        // Aucun token n'est délivré avant la vérification du code OTP.
        $response->assertJsonMissingPath('accessToken');
    }

    public function test_it_rejects_an_email_already_used_with_a_readable_message(): void
    {
        $this->postJson('/companies', $this->payload())->assertCreated();

        $response = $this->postJson('/companies', $this->payload([
            'companyName' => 'Une autre entreprise',
        ]));

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Cette adresse email est déjà utilisée par un compte existant.');

        // Le premier compte n'a pas été dupliqué, et la seconde company n'a pas
        // été laissée derrière par une transaction à moitié appliquée.
        $this->assertSame(1, User::where('email', 'contact@goriya-test.ci')->count());
        $this->assertSame(1, Company::count());
    }

    public function test_it_rejects_a_malformed_email(): void
    {
        $this->postJson('/companies', $this->payload(['email' => 'pas-un-email']))
            ->assertStatus(400)
            ->assertJsonPath('message', "L'email professionnel n'est pas une adresse valide.");

        $this->assertSame(0, Company::count());
    }

    public function test_it_rejects_a_too_short_password(): void
    {
        $this->postJson('/companies', $this->payload(['password' => 'court']))
            ->assertStatus(400)
            ->assertJsonPath('message', 'Le mot de passe doit contenir au moins 8 caractères.');

        $this->assertSame(0, Company::count());
    }

    public function test_it_names_the_missing_required_field(): void
    {
        $this->postJson('/companies', $this->payload(['companyName' => '']))
            ->assertStatus(400)
            ->assertJsonPath('message', "Le nom de l'entreprise est obligatoire.");
    }

    /**
     * La règle `unique` attrape le cas courant, mais deux inscriptions
     * simultanées peuvent encore se croiser entre la validation et l'INSERT.
     * Ce chemin-là passe par HandlesUniqueViolations, et c'est exactement celui
     * qui renvoyait un 500 en production : on l'exerce sans validation.
     */
    public function test_a_concurrent_duplicate_is_translated_instead_of_crashing(): void
    {
        $service = app(\App\Services\CompanyService::class);
        $data = $this->payload();

        $service->create($data);

        try {
            $service->create($data);
            $this->fail('Une seconde création avec le même email aurait dû être refusée.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('Cette adresse email est déjà utilisée', $e->getMessage());
        }
    }
}
