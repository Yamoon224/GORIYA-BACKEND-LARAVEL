<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Provisionne les 3 comptes "sous-admin" (rôle ADMIN) demandés pour
 * l'environnement de prod. Idempotent (updateOrCreate par email) : relancer
 * la commande met juste à jour le mot de passe/le rôle des 3 comptes au lieu
 * d'échouer ou de les dupliquer.
 *
 * Le mot de passe initial n'est jamais en dur dans le code : il vient de
 * --password ou de la variable d'environnement SUBADMIN_INITIAL_PASSWORD,
 * pour ne pas laisser un secret réel en clair dans l'historique git. Il
 * n'est pas non plus réaffiché en sortie (terminal/logs de déploiement).
 *
 * email_verified_at posé directement : OtpLoginGate ne bloque de toute façon
 * que le rôle USER (voir AuthService::login), mais rester cohérent évite un
 * état "email non vérifié" qui ne correspond à rien ici.
 */
class CreateSubAdminsCommand extends Command
{
    protected $signature = 'admins:create-subadmins
        {--password= : Mot de passe initial commun aux 3 comptes (sinon lu depuis SUBADMIN_INITIAL_PASSWORD)}';

    protected $description = 'Crée (ou met à jour) les 3 comptes sous-admin sous-admin{1,2,3}@goriya.net';

    /** @var list<array{name: string, email: string}> */
    private const ACCOUNTS = [
        ['name' => 'Sous-admin 1', 'email' => 'sous-admin1@goriya.net'],
        ['name' => 'Sous-admin 2', 'email' => 'sous-admin2@goriya.net'],
        ['name' => 'Sous-admin 3', 'email' => 'sous-admin3@goriya.net'],
    ];

    public function handle(): int
    {
        $password = $this->option('password') ?: env('SUBADMIN_INITIAL_PASSWORD');

        if (! $password) {
            $this->error('Aucun mot de passe fourni : relance avec --password=... ou définis SUBADMIN_INITIAL_PASSWORD dans l\'environnement.');

            return self::FAILURE;
        }

        foreach (self::ACCOUNTS as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make($password),
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
        $this->warn('Mot de passe initial appliqué aux 3 comptes (non ré-affiché ici) — à faire changer par chaque sous-admin dès sa première connexion.');

        return self::SUCCESS;
    }
}
