<?php

namespace Tests\Feature;

use App\Models\PromoCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPromoCampaignsTest extends TestCase
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

    private function nonAdmin(): User
    {
        return User::create([
            'name' => 'Candidat Test',
            'email' => 'candidat@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_admin_can_create_and_list_campaigns(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'api')->postJson('/admin/promo-campaigns', [
            'name' => 'Rentrée 2026',
            'discountType' => 'PERCENTAGE',
            'discountValue' => 15,
            'applicableUserTypes' => ['USER', 'ENTREPRISE'],
            'status' => 'ACTIVE',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Rentrée 2026')
            ->assertJsonPath('data.discountValue', 15);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/promo-campaigns/paginate')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_and_delete_a_campaign(): void
    {
        $admin = $this->admin();
        $campaign = PromoCampaign::create([
            'name' => 'Brouillon',
            'discount_type' => 'FIXED',
            'discount_value' => 1000,
            'applicable_user_types' => ['USER'],
            'status' => 'DRAFT',
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/promo-campaigns/{$campaign->id}", ['status' => 'ACTIVE'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE');

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/promo-campaigns/{$campaign->id}")
            ->assertOk();

        $this->assertDatabaseMissing('promo_campaigns', ['id' => $campaign->id]);
    }

    public function test_non_admin_cannot_manage_campaigns(): void
    {
        $user = $this->nonAdmin();

        $this->actingAs($user, 'api')
            ->postJson('/admin/promo-campaigns', [
                'name' => 'Interdit',
                'discountType' => 'PERCENTAGE',
                'discountValue' => 10,
                'applicableUserTypes' => ['USER'],
            ])
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->getJson('/admin/promo-campaigns/paginate')
            ->assertStatus(403);
    }
}
