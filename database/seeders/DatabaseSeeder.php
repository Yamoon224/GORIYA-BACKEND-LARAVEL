<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Pas de WithoutModelEvents ici : HasUuid (voir app/Concerns/HasUuid.php)
     * génère les PK uuid via l'event Eloquent `creating`, que ce trait
     * désactiverait pour tout le run — cassant l'insertion de tous les
     * modèles créés via Eloquent (Test User, SubscriptionPlanSeeder, ...).
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(SubscriptionPlanSeeder::class);
        $this->call(GoriyaDemoSeeder::class);
        $this->call(ArticleSeeder::class);
    }
}
