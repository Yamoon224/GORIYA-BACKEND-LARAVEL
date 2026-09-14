<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Provisionne les 3 comptes "sous-admin" (rôle ADMIN, mot de passe partagé
 * fourni par l'équipe) demandés pour l'environnement de prod. Idempotent
 * (updateOrCreate par email) : relancer la commande met juste à jour le mot
 * de passe/le rôle des 3 comptes au lieu d'échouer ou de les dupliquer.
 *
 * email_verified_at posé directement : OtpLoginGate ne bloque de toute façon
 * que le rôle USER (voir AuthService::login), mais rester cohérent évite un
 * état "email non vérifié" qui ne correspond à rien ici.
 */
class CreateSubAdminsCommand extends Command
{
    protected $signature = 'admins:create-subadmins';

    protected $description = 'Crée (ou met à jour) les 3 comptes sous-admin sous-admin{1,2,3}@goriya.net';

    /** @var list<array{name: string, email: string}> */
    private const ACCOUNTS = [
        ['name' => 'Sous-admin 1', 'email' => 'sous-admin1@goriya.net'],
        ['name' => 'Sous-admin 2', 'email' => 'sous-admin2@goriya.net'],
        ['name' => 'Sous-admin 3', 'email' => 'sous-admin3@goriya.net'],
    ];

    private const SHARED_PASSWORD = 'Goriya@225';

    public function handle(): int
    {
        foreach (self::ACCOUNTS as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make(self::SHARED_PASSWORD),
                    'role' => UserRole::ADMIN->value,
                    'status' => UserStatus::ACTIVE->value,
                    'email_verified_at' => now(),
                    'registration_date' => now(),
                ]
            );

            $state = $user->wasRecentlyCreated ? 'créé' : 'mis à jour';
            $this->info("✅ {$user->email} ({$state})");
        }

        $this->newLine();
        $this->warn('Mot de passe partagé : '.self::SHARED_PASSWORD.' — à faire changer à chaque sous-admin dès sa première connexion.');

        return self::SUCCESS;
    }
}
