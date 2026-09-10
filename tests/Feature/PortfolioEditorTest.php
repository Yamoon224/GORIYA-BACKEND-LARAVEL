<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Éditeur de portfolio (standard /portfolio/creer) : photo, thème, statut et
 * détails structurés.
 */
class PortfolioEditorTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::slug($name).'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_le_portfolio_complet_est_cree_pour_le_membre_connecte(): void
    {
        Storage::fake('public');
        $awa = $this->member('Awa Kone');
        $autre = $this->member('Bob Traore');

        $photo = $this->actingAs($awa, 'api')
            ->post('/portfolios/photo', ['photo' => UploadedFile::fake()->create('moi.png', 200, 'image/png')], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data');
        $this->assertMatchesRegularExpression('#^/portfolios/[0-9a-f\-]{36}\.png$#', $photo['path']);
        Storage::disk('public')->assertExists(ltrim($photo['path'], '/'));

        $portfolio = $this->actingAs($awa, 'api')->postJson('/portfolios', [
            'title' => 'Développeuse React',
            'description' => 'Je construis des interfaces.',
            'skills' => ['React', 'TypeScript'],
            // Ignoré : un membre ne crée que pour lui-même.
            'userId' => $autre->id,
            'status' => 'DRAFT',
            'theme' => 'purple',
            'photo' => $photo['path'],
            'details' => [
                'fullName' => 'Awa Koné',
                'skillLevels' => ['React' => 90, 'TypeScript' => 75],
                'projects' => [['title' => 'Boutique en ligne', 'status' => 'EN_COURS', 'previewUrl' => 'https://boutique.example.ci']],
                'links' => ['github' => 'https://github.com/awa'],
            ],
        ])->assertOk()->json('data');

        $this->assertSame($awa->id, $portfolio['user']['id']);
        $this->assertSame('DRAFT', $portfolio['status']);
        $this->assertSame('purple', $portfolio['theme']);
        $this->assertSame(90, $portfolio['details']['skillLevels']['React']);
        $this->assertSame('Boutique en ligne', $portfolio['details']['projects'][0]['title']);
        $this->assertStringEndsWith($photo['path'], $portfolio['photo']);

        // `details` n'accepte que les clés connues de l'éditeur.
        $this->actingAs($awa, 'api')
            ->patchJson("/portfolios/{$portfolio['id']}", ['details' => ['script' => '<b>x</b>']])
            ->assertStatus(422);

        $this->actingAs($awa, 'api')
            ->patchJson("/portfolios/{$portfolio['id']}", ['status' => 'PUBLISHED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');

        // Un autre membre ne peut pas le modifier.
        $this->actingAs($autre, 'api')
            ->patchJson("/portfolios/{$portfolio['id']}", ['title' => 'Détourné'])
            ->assertStatus(403);
    }

    public function test_une_photo_qui_n_est_pas_une_image_est_refusee(): void
    {
        Storage::fake('public');
        $awa = $this->member('Awa Kone');

        $this->actingAs($awa, 'api')
            ->post('/portfolios/photo', ['photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }
}
