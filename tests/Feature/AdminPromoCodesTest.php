<?php

namespace Tests\Feature;

use App\Models\PromoCampaign;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPromoCodesTest extends TestCase
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

    private function campaign(): PromoCampaign
    {
        return PromoCampaign::create([
            'name' => 'Rentrée 2026',
            'discount_type' => 'PERCENTAGE',
            'discount_value' => 15,
            'applicable_user_types' => ['USER'],
            'status' => 'ACTIVE',
        ]);
    }

    public function test_admin_can_generate_and_create_a_code(): void
    {
        $admin = $this->admin();
        $campaign = $this->campaign();

        $generated = $this->actingAs($admin, 'api')
            ->postJson('/admin/promo-codes/generate')
            ->assertOk()
            ->json('data.code');

        $this->assertNotEmpty($generated);

        $this->actingAs($admin, 'api')->postJson('/admin/promo-codes', [
            'campaignId' => $campaign->id,
            'code' => 'welcome10',
            'maxUses' => 100,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'WELCOME10');
    }

    public function test_admin_can_update_and_delete_a_code(): void
    {
        $admin = $this->admin();
        $campaign = $this->campaign();
        $code = PromoCode::create(['campaign_id' => $campaign->id, 'code' => 'TOEDIT']);

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/promo-codes/{$code->id}", ['isActive' => false])
            ->assertOk()
            ->assertJsonPath('data.isActive', false);

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/promo-codes/{$code->id}")
            ->assertOk();

        $this->assertDatabaseMissing('promo_codes', ['id' => $code->id]);
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        $admin = $this->admin();
        $campaign = $this->campaign();
        PromoCode::create(['campaign_id' => $campaign->id, 'code' => 'DUPLICATE']);

        // Erreur de FormRequest (règle "unique") : convertie en 400 par le
        // handler global, cf. bootstrap/app.php (parité avec le backend Node).
        $this->actingAs($admin, 'api')->postJson('/admin/promo-codes', [
            'campaignId' => $campaign->id,
            'code' => 'DUPLICATE',
        ])->assertStatus(400);
    }

    public function test_non_admin_cannot_manage_codes(): void
    {
        $user = User::create([
            'name' => 'Candidat Test',
            'email' => 'candidat@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/admin/promo-codes/paginate')
            ->assertStatus(403);
    }
}
