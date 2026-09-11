<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Rattrapage ponctuel (et sûr à relancer) : certains employés — ajoutés
 * manuellement, ou embauchés avant que le lien candidature -> user_id ne soit
 * posé de façon fiable — ont une fiche Employee sans `user_id`, alors que la
 * personne a bien un compte Goriya sous la même adresse email. Sans ce lien,
 * l'espace employé (standard/app/(protected)/espace-employe) leur reste
 * invisible : EmployeeService::findByUser() ne trouve rien.
 *
 * Ne touche que les fiches sans user_id, et ne relie qu'à un compte USER
 * (jamais ENTREPRISE/ADMIN) — l'email doit correspondre exactement.
 */
class LinkEmployeesToExistingAccountsCommand extends Command
{
    protected $signature = 'employees:link-existing-accounts {--dry-run : Affiche ce qui serait lié sans rien écrire}';

    protected $description = "Relie les fiches employé sans compte à un compte Goriya existant (email identique), pour activer leur espace employé";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Employee::query()
            ->whereNull('user_id')
            ->whereNotNull('email')
            ->get(['id', 'email', 'first_name', 'last_name', 'company_id']);

        $linked = 0;

        foreach ($candidates as $employee) {
            $user = User::where('role', UserRole::USER)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($employee->email)])
                ->first();

            if (! $user) {
                continue;
            }

            $this->line("{$employee->first_name} {$employee->last_name} <{$employee->email}> -> user {$user->id}");

            if (! $dryRun) {
                $employee->update(['user_id' => $user->id]);
            }

            $linked++;
        }

        $this->info($dryRun
            ? "{$linked} fiche(s) seraient liée(s) (--dry-run, rien n'a été écrit)."
            : "{$linked} fiche(s) liée(s) à un compte Goriya existant.");

        return self::SUCCESS;
    }
}
