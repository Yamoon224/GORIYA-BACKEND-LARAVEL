<?php

namespace Tests\Feature;

use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Profil public façon LinkedIn : URL personnalisée ou uuid, et contenu selon
 * le lecteur (propriétaire / membre connecté / visiteur).
 */
class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $name, ?string $title = null): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::slug($name).'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'title' => $title,
        ]);
    }

    /** Les requêtes suivantes partent sans utilisateur authentifié. */
    private function anonymous(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_le_profil_s_ouvre_par_l_uuid_et_depend_du_lecteur(): void
    {
        $awa = $this->member('Awa Kone', 'Data analyst');
        $bob = $this->member('Bob Traore');

        $me = $this->actingAs($awa, 'api')->getJson('/profile/me')->assertSuccessful()->json();
        $this->assertNull($me['slug']);
        $this->assertSame($awa->id, $me['ref']);
        $this->assertSame('/p/'.$awa->id, $me['publicPath']);

        // Non public : invisible hors connexion…
        $this->anonymous();
        $this->getJson("/profiles/{$awa->id}")->assertNotFound();

        // …mais consultable par un membre connecté, sans les coordonnées.
        $vu = $this->actingAs($bob, 'api')->getJson("/profiles/{$awa->id}")->assertOk()->json();
        $this->assertSame('member', $vu['viewerMode']);
        $this->assertSame('Data analyst', $vu['title']);
        $this->assertNull($vu['email']);
        $this->assertFalse($vu['isFollowing']);

        $proprio = $this->actingAs($awa, 'api')->getJson("/profiles/{$awa->id}")->assertOk()->json();
        $this->assertSame('owner', $proprio['viewerMode']);
        $this->assertSame($awa->email, $proprio['email']);

        $this->actingAs($awa, 'api')
            ->patchJson('/profile/me', ['isPublic' => true, 'slug' => 'awa-kone'])
            ->assertOk()
            ->assertJsonPath('ref', 'awa-kone');

        $this->anonymous();
        $this->getJson('/profiles/awa-kone')
            ->assertOk()
            ->assertJsonPath('viewerMode', 'public')
            ->assertJsonPath('email', null);
        // L'uuid reste valable après le choix d'une URL : les anciens liens marchent.
        $this->getJson("/profiles/{$awa->id}")->assertOk()->assertJsonPath('ref', 'awa-kone');
    }

    public function test_l_url_personnalisee_est_unique_et_reinitialisable(): void
    {
        $awa = $this->member('Awa Kone');
        $bob = $this->member('Bob Traore');

        $this->actingAs($awa, 'api')->patchJson('/profile/me', ['slug' => 'talent'])->assertOk();
        $this->actingAs($bob, 'api')->patchJson('/profile/me', ['slug' => 'talent'])->assertStatus(400);
        $this->actingAs($bob, 'api')->patchJson('/profile/me', ['slug' => 'Mauvais Slug'])->assertStatus(400);
        $this->actingAs($bob, 'api')->patchJson('/profile/me', ['slug' => 'admin'])->assertStatus(400);

        $this->actingAs($awa, 'api')
            ->patchJson('/profile/me', ['slug' => null])
            ->assertOk()
            ->assertJsonPath('slug', null)
            ->assertJsonPath('ref', $awa->id);
    }

    public function test_les_brouillons_ne_sont_vus_que_du_proprietaire(): void
    {
        $awa = $this->member('Awa Kone');
        $bob = $this->member('Bob Traore');

        $publie = Portfolio::create([
            'title' => 'Tableaux de bord',
            'description' => 'Power BI',
            'skills' => ['Power BI', 'SQL'],
            'created_date' => now(),
            'user_id' => $awa->id,
            'status' => 'PUBLISHED',
        ]);
        Portfolio::create([
            'title' => 'Projet en préparation',
            'description' => '',
            'skills' => ['Python'],
            'created_date' => now(),
            'user_id' => $awa->id,
            'status' => 'DRAFT',
        ]);

        $vu = $this->actingAs($bob, 'api')->getJson("/profiles/{$awa->id}")->assertOk()->json();
        $this->assertCount(1, $vu['portfolios']);
        $this->assertSame(['Power BI', 'SQL'], $vu['skills']);
        $this->assertSame(1, $publie->fresh()->views);

        $proprio = $this->actingAs($awa, 'api')->getJson("/profiles/{$awa->id}")->assertOk()->json();
        $this->assertCount(2, $proprio['portfolios']);
        $this->assertSame(1, $publie->fresh()->views);
    }
}
