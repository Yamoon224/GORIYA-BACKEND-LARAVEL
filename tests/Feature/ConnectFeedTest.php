<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fil GORIYA Connect façon LinkedIn : pièces jointes, j'aime, commentaires,
 * republications.
 */
class ConnectFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

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

    /** @return array<string, mixed> */
    private function publish(User $author, array $payload): array
    {
        return $this->actingAs($author, 'api')
            ->post('/posts', $payload, ['Accept' => 'application/json'])
            ->assertOk()
            ->json();
    }

    public function test_un_post_avec_images_est_visible_des_autres_membres(): void
    {
        $alice = $this->member('Alice Kouame');
        $bob = $this->member('Bob Traore');

        $post = $this->publish($alice, [
            'content' => 'Mon premier projet',
            'attachments' => [
                UploadedFile::fake()->create('maquette.jpg', 120, 'image/jpeg'),
                UploadedFile::fake()->create('ecran.png', 80, 'image/png'),
            ],
        ]);

        $this->assertCount(2, $post['attachments']);
        $this->assertSame('IMAGE', $post['attachments'][0]['type']);
        $this->assertSame('maquette.jpg', $post['attachments'][0]['name']);
        Storage::disk('public')->assertExists('posts/'.basename($post['attachments'][0]['url']));

        // Bob ne suit pas Alice : un post hors communauté lui est tout de même
        // proposé, comme un post LinkedIn « Tout le monde ».
        $feed = $this->actingAs($bob, 'api')->getJson('/posts/feed')->assertOk()->json('data');

        $this->assertSame($post['id'], $feed[0]['id']);
        $this->assertSame(0, $feed[0]['likesCount']);
        $this->assertSame(0, $feed[0]['commentsCount']);
        $this->assertFalse($feed[0]['likedByMe']);
    }

    public function test_un_pdf_se_publie_seul_et_un_post_vide_est_refuse(): void
    {
        $alice = $this->member('Alice Kouame');

        $this->actingAs($alice, 'api')
            ->post('/posts', [
                'content' => 'Mon CV et une photo',
                'attachments' => [
                    UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
                    UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
                ],
            ], ['Accept' => 'application/json'])
            ->assertStatus(400);

        $this->actingAs($alice, 'api')
            ->post('/posts', ['content' => '   '], ['Accept' => 'application/json'])
            ->assertStatus(400);

        $post = $this->publish($alice, [
            'attachments' => [UploadedFile::fake()->create('rapport.pdf', 300, 'application/pdf')],
        ]);

        $this->assertNull($post['content']);
        $this->assertSame('DOCUMENT', $post['attachments'][0]['type']);
    }

    public function test_like_commentaire_et_republication(): void
    {
        $alice = $this->member('Alice Kouame');
        $bob = $this->member('Bob Traore');

        $post = $this->publish($alice, ['content' => 'Nous recrutons !']);

        $this->actingAs($bob, 'api')->postJson("/posts/{$post['id']}/like")
            ->assertOk()
            ->assertJson(['liked' => true, 'likesCount' => 1]);

        $comment = $this->actingAs($bob, 'api')
            ->postJson("/posts/{$post['id']}/comments", ['content' => 'Bravo, je postule.'])
            ->assertCreated()
            ->json();
        $this->assertSame('Bob Traore', $comment['author']['name']);

        $this->actingAs($alice, 'api')->getJson("/posts/{$post['id']}/comments")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $repost = $this->actingAs($bob, 'api')->postJson("/posts/{$post['id']}/repost")
            ->assertOk()
            ->json();
        $this->assertSame($post['id'], $repost['repostOf']['id']);
        $this->assertSame('Nous recrutons !', $repost['repostOf']['content']);
        // Compteurs de l'original, pour agir dessus depuis la republication.
        $this->assertSame(1, $repost['repostOf']['likesCount']);
        $this->assertFalse($repost['repostOf']['likedByMe'] === false && $repost['repostOf']['likesCount'] === 0);

        // Une seconde republication « nue » du même post est refusée…
        $this->actingAs($bob, 'api')->postJson("/posts/{$post['id']}/repost")->assertStatus(400);
        // …mais republier la republication vise le post d'origine.
        $this->actingAs($alice, 'api')->postJson("/posts/{$repost['id']}/repost", ['content' => 'Partagez autour de vous'])
            ->assertOk()
            ->assertJsonPath('repostOf.id', $post['id']);

        $vuParAlice = $this->actingAs($alice, 'api')->getJson("/posts/{$post['id']}")->assertOk()->json();
        $this->assertSame(1, $vuParAlice['likesCount']);
        $this->assertSame(1, $vuParAlice['commentsCount']);
        $this->assertSame(2, $vuParAlice['repostsCount']);
        $this->assertTrue($vuParAlice['repostedByMe']);

        // L'auteur du post peut retirer un commentaire reçu.
        $this->actingAs($alice, 'api')->deleteJson("/posts/{$post['id']}/comments/{$comment['id']}")->assertOk();
        $this->actingAs($bob, 'api')->getJson("/posts/{$post['id']}/comments")->assertJsonPath('meta.total', 0);
    }

    public function test_seul_l_auteur_supprime_son_post_et_ses_fichiers(): void
    {
        $alice = $this->member('Alice Kouame');
        $bob = $this->member('Bob Traore');

        $post = $this->publish($alice, [
            'content' => 'Photo',
            'attachments' => [UploadedFile::fake()->create('photo.webp', 50, 'image/webp')],
        ]);
        $fichier = 'posts/'.basename($post['attachments'][0]['url']);

        $this->actingAs($bob, 'api')->deleteJson("/posts/{$post['id']}")->assertStatus(403);
        Storage::disk('public')->assertExists($fichier);

        $this->actingAs($alice, 'api')->deleteJson("/posts/{$post['id']}")->assertOk();
        Storage::disk('public')->assertMissing($fichier);
    }
}
