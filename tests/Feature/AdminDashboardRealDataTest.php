<?php

namespace Tests\Feature;

use App\Models\CvAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardRealDataTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Goriya', 'email' => 'admin@goriya.net',
            'password' => 'motdepasse-solide', 'role' => 'ADMIN', 'status' => 'ACTIVE',
        ]);
    }

    public function test_anonymous_cv_analysis_is_logged_and_counted_on_the_dashboard(): void
    {
        $this->postJson('/anonymous-usage/consume', [
            'deviceId' => 'device-1', 'featureKey' => 'cv_analysis',
            'filename' => 'cv-awa.pdf', 'score' => 62,
        ])->assertOk()->assertJsonPath('allowed', true);

        $this->assertDatabaseHas('cv_analysis', ['filename' => 'cv-awa.pdf', 'analysis_score' => 62, 'status' => 'COMPLETED']);

        $this->actingAs($this->admin(), 'api')
            ->getJson('/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.analyzedCVs', 1)
            ->assertJsonPath('data.monthly.cvAnalyzed', 1)
            ->assertJsonPath('data.aiTools.0.key', 'cvAnalysis')
            ->assertJsonPath('data.aiTools.0.value', 1);
    }

    public function test_refused_or_unrelated_consumption_is_not_logged(): void
    {
        // Autre fonctionnalité : rien à journaliser.
        $this->postJson('/anonymous-usage/consume', ['deviceId' => 'device-2', 'featureKey' => 'cv_creation'])->assertOk();

        // Quota gratuit épuisé : la dernière tentative est refusée.
        $allowed = 0;
        for ($i = 0; $i < 6; $i++) {
            $allowed += $this->postJson('/anonymous-usage/consume', ['deviceId' => 'device-3', 'featureKey' => 'cv_analysis'])
                ->json('allowed') ? 1 : 0;
        }

        $this->assertLessThan(6, $allowed);
        $this->assertSame($allowed, CvAnalysis::count());
    }

    public function test_monthly_figures_only_count_the_current_month(): void
    {
        $admin = $this->admin();

        $old = User::create([
            'name' => 'Ancien', 'email' => 'ancien@goriya-test.ci',
            'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE',
        ]);
        $old->forceFill(['created_at' => now()->subMonths(3)])->saveQuietly();

        User::create([
            'name' => 'Nouveau', 'email' => 'nouveau@goriya-test.ci',
            'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.activeStudents', 2)
            ->assertJsonPath('data.monthly.newCandidates', 1);
    }
}
