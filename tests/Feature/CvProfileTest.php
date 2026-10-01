<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CvProfileTest extends TestCase
{
    use RefreshDatabase;

    private function candidate(string $email = 'candidat@goriya-test.ci'): User
    {
        return User::create([
            'name' => 'Candidat', 'email' => $email,
            'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE',
        ]);
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/me/cv-profile')->assertUnauthorized();
        $this->putJson('/me/cv-profile', ['profile' => []])->assertUnauthorized();
    }

    public function test_profile_is_null_until_saved(): void
    {
        $this->actingAs($this->candidate(), 'api')
            ->getJson('/me/cv-profile')
            ->assertOk()
            ->assertJsonPath('profile', null);

        $this->assertDatabaseCount('cv_profiles', 0);
    }

    public function test_candidate_saves_and_reads_back_the_profile(): void
    {
        $user = $this->candidate();

        $this->actingAs($user, 'api')->putJson('/me/cv-profile', ['profile' => [
            'fullName' => ' Dibi Estelle ',
            'birthDate' => '1999-08-09',
            'gender' => 'Femme',
            'unknown' => 'ignoré',
            'skills' => ['Figma', '', 'Adobe Creative Suite'],
            'experiences' => [[
                'position' => 'Designer Graphiste',
                'company' => 'LUNION-LAB',
                'missions' => ['Création d\'infographies', 'Conception de maquettes'],
                'salary' => 'ignoré',
            ]],
            'educations' => [['title' => 'Licence 3 en Communication', 'school' => 'UFHB']],
        ]])
            ->assertCreated()
            ->assertJsonPath('profile.fullName', 'Dibi Estelle')
            ->assertJsonPath('profile.skills', ['Figma', 'Adobe Creative Suite'])
            ->assertJsonPath('profile.experiences.0.missions.1', 'Conception de maquettes')
            ->assertJsonPath('profile.experiences.0.skills', [])
            ->assertJsonMissingPath('profile.unknown')
            ->assertJsonMissingPath('profile.experiences.0.salary');

        // Un second enregistrement remplace le premier : une seule ligne par utilisateur.
        $this->actingAs($user, 'api')
            ->putJson('/me/cv-profile', ['profile' => ['fullName' => 'Estelle Dibi']])
            ->assertOk();

        $this->assertDatabaseCount('cv_profiles', 1);

        $this->actingAs($user, 'api')
            ->getJson('/me/cv-profile')
            ->assertOk()
            ->assertJsonPath('profile.fullName', 'Estelle Dibi');
    }

    public function test_profiles_are_isolated_per_user(): void
    {
        $this->actingAs($this->candidate(), 'api')
            ->putJson('/me/cv-profile', ['profile' => ['fullName' => 'Premier']])
            ->assertCreated();

        $this->actingAs($this->candidate('autre@goriya-test.ci'), 'api')
            ->getJson('/me/cv-profile')
            ->assertOk()
            ->assertJsonPath('profile', null);
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $this->actingAs($this->candidate(), 'api')
            ->putJson('/me/cv-profile', ['profile' => ['birthDate' => '09/08/1999', 'gender' => 'Autre']])
            ->assertStatus(400);
    }
}
