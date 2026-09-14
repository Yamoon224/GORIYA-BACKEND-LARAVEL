<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\CalendarEvent;
use App\Models\Company;
use App\Models\CvAnalysis;
use App\Models\InterviewSession;
use App\Models\JobOffer;
use App\Models\MatchingResult;
use App\Models\ScoringResult;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Supprime TOUTES les données générées par GoriyaDemoSeeder — companies,
 * job_offers, users, et tout ce qui en découle (portfolios, candidatures,
 * scoring_results, matching_results, interview_sessions, cv_analysis,
 * calendar_events) — sauf les comptes ADMIN, jamais touchés quel que soit
 * leur nombre.
 *
 * Le seeder ne pose aucun marqueur "is_demo" sur ses lignes : on les repère
 * donc par la signature de données qu'IvorianData leur donne et qu'aucun
 * flux réel de l'app ne produit :
 *  - companies : email "contact@{slug}.ci" + website "https://www.{slug}.ci"
 *    + cover_image "https://picsum.photos/seed/{slug}/..." — les 3 à la
 *    fois (une vraie entreprise créée depuis l'app ne tombera pas dessus
 *    par hasard).
 *  - users : avatar "https://i.pravatar.cc/150?u={id}" — pravatar.cc n'est
 *    utilisé nulle part ailleurs dans l'app (la vraie génération d'avatar
 *    stocke un fichier via UserService::uploadAvatar, jamais une URL
 *    pravatar), et jamais role ADMIN.
 *  - job_offers : rattachées (company_id) à une company repérée ci-dessus.
 *  - scoring_results/matching_results/interview_sessions : candidate_email
 *    dans l'ensemble des emails repérés ci-dessus (ces 3 tables n'ont pas
 *    de FK vers users/candidatures, juste une copie de l'email — voir
 *    GoriyaDemoSeeder::seedScoringResults/seedMatchingResults/
 *    seedInterviewSessions).
 *  - cv_analysis : filename "{nom complet}_cv.pdf" pour un des users
 *    repérés (la vraie génération stocke toujours un UUID, jamais
 *    "{Nom}_cv.pdf" — voir CvAnalysisService::storeFile).
 *  - calendar_events : participants (JSON) contenant un des emails repérés.
 *  - portfolios/candidatures/candidature_answers/job_offer_questions/
 *    company_follows : jamais interrogées directement — nettoyées par la
 *    cascade DB (cascadeOnDelete) quand on supprime les users/job_offers
 *    seed correspondants.
 */
class PurgeDemoDataCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'demo:purge
        {--dry-run : Affiche seulement le nombre de lignes concernées, sans rien supprimer}
        {--force : Ne pas demander de confirmation (nécessaire en production)}';

    protected $description = "Supprime toutes les données issues de GoriyaDemoSeeder (companies, job_offers, users et dérivés), sauf les comptes ADMIN";

    public function handle(): int
    {
        $companyIds = $this->seededCompaniesQuery()->pluck('id');
        $jobIds = JobOffer::query()->whereIn('company_id', $companyIds)->pluck('id');

        $seededUsers = $this->seededUsersQuery()->get(['id', 'name', 'email']);
        $userIds = $seededUsers->pluck('id');
        $emails = $seededUsers->pluck('email');
        $cvFilenames = $seededUsers->map(fn (User $u) => "{$u->name}_cv.pdf");

        $calendarEventIds = $this->calendarEventIdsForEmails($emails);

        if ($companyIds->isEmpty() && $userIds->isEmpty() && $jobIds->isEmpty()) {
            $this->info('Aucune donnée de seed détectée.');

            return self::SUCCESS;
        }

        $this->table(['Table', 'Lignes concernées'], [
            ['companies', $companyIds->count()],
            ['job_offers', $jobIds->count()],
            ['users (hors ADMIN)', $userIds->count()],
            ['scoring_results', ScoringResult::query()->whereIn('candidate_email', $emails)->count()],
            ['matching_results', MatchingResult::query()->whereIn('candidate_email', $emails)->count()],
            ['interview_sessions', InterviewSession::query()->whereIn('candidate_email', $emails)->count()],
            ['cv_analysis', CvAnalysis::query()->whereIn('filename', $cvFilenames)->count()],
            ['calendar_events', $calendarEventIds->count()],
            ['portfolios / candidatures / ...', '(cascade DB via users/job_offers)'],
        ]);

        if ($this->option('dry-run')) {
            $this->comment("Dry-run : rien n'a été supprimé.");

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->deleteEach(ScoringResult::query()->whereIn('candidate_email', $emails), 'scoring_results');
        $this->deleteEach(MatchingResult::query()->whereIn('candidate_email', $emails), 'matching_results');
        $this->deleteEach(InterviewSession::query()->whereIn('candidate_email', $emails), 'interview_sessions');
        $this->deleteEach(CvAnalysis::query()->whereIn('filename', $cvFilenames), 'cv_analysis');
        $this->deleteEach(CalendarEvent::query()->whereIn('id', $calendarEventIds), 'calendar_events');

        // job_offers avant companies : job_offers.company_id est nullOnDelete
        // côté DB (migration 2026_07_02_150000) — supprimer les companies en
        // premier laisserait les offres seed orphelines (company_id = null)
        // au lieu de les effacer. La cascade DB nettoie ensuite candidatures/
        // job_offer_questions/candidature_answers rattachées à ces offres.
        $this->deleteEach(JobOffer::query()->whereIn('company_id', $companyIds), 'job_offers');

        // Cascade DB : portfolios, candidatures restantes (via user_id) et
        // company_follows rattachés à ces comptes.
        $this->deleteEach(User::query()->whereIn('id', $userIds), 'users');

        $this->deleteEach($this->seededCompaniesQuery(), 'companies');

        return self::SUCCESS;
    }

    private function deleteEach(Builder $query, string $label): void
    {
        $count = 0;
        $query->each(function ($model) use (&$count) {
            $model->delete();
            $count++;
        });
        $this->info("{$count} ligne(s) supprimée(s) dans {$label}.");
    }

    private function seededCompaniesQuery(): Builder
    {
        return Company::query()
            ->where('email', 'like', 'contact@%.ci')
            ->where('website', 'like', 'https://www.%.ci')
            ->where('cover_image', 'like', 'https://picsum.photos/seed/%');
    }

    private function seededUsersQuery(): Builder
    {
        return User::query()
            ->where('avatar', 'like', 'https://i.pravatar.cc/150?u=%')
            ->where('role', '!=', UserRole::ADMIN->value);
    }

    /** @param  Collection<int, string>  $emails */
    private function calendarEventIdsForEmails(Collection $emails): Collection
    {
        if ($emails->isEmpty()) {
            return collect();
        }

        // participants est un JSON array d'emails, sans FK — on charge et on
        // filtre côté PHP plutôt que d'empiler un whereJsonContains par email
        // (la table reste petite, capée à 500 lignes par le seeder).
        return CalendarEvent::query()->get(['id', 'participants'])
            ->filter(fn (CalendarEvent $event) => collect($event->participants ?? [])->intersect($emails)->isNotEmpty())
            ->pluck('id');
    }
}
