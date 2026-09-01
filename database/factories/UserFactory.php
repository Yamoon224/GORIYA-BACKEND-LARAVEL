<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * `email_verified_at` a été ajouté à la table par la migration
     * 2026_07_09_100006 (vérification OTP). On le renseigne par défaut ici :
     * un compte USER non vérifié ne peut plus se connecter (voir
     * AuthService::login). Utiliser l'état `unverified()` pour l'inverse.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::USER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ];
    }

    /**
     * Compte dont l'email n'a pas encore été vérifié via OTP.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }
}
