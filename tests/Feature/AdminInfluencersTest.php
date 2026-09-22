<?php

namespace Tests\Feature;

use App\Models\Influencer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInfluencersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Goriya',
            'email' => 'admin@goriya.net',
            'password' => 'motdepasse-solide',
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_admin_can_create_update_and_delete_an_influencer(): void
    {
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'api')->postJson('/admin/influencers', [
            'name' => 'Aïcha Promo',
            'email' => 'aicha@example.com',
            'defaultCommissionRate' => 12,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Aïcha Promo')
            ->json('data.id');

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/influencers/{$created}", ['defaultCommissionRate' => 20])
            ->assertOk()
            ->assertJsonPath('data.defaultCommissionRate', 20);

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/influencers/{$created}")
            ->assertOk();

        $this->assertDatabaseMissing('influencers', ['id' => $created]);
    }

    public function test_balance_endpoint_reports_zero_for_a_fresh_influencer(): void
    {
        $admin = $this->admin();
        $influencer = Influencer::create(['name' => 'Nouveau Partenaire']);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/influencers/{$influencer->id}/balance")
            ->assertOk()
            ->assertJsonPath('data.unpaidBalance', 0);
    }

    public function test_non_admin_cannot_manage_influencers(): void
    {
        $user = User::create([
            'name' => 'Candidat Test',
            'email' => 'candidat@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/admin/influencers/paginate')
            ->assertStatus(403);
    }
}
