<?php

namespace Database\Seeders;

use App\Enums\CandidatureStatus;
use App\Enums\CompanyStatus;
use App\Enums\CVStatus;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\InterviewStatus;
use App\Enums\JobExperienceType;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\MatchingStatus;
use App\Enums\ScoringStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Carbon\Carbon;
use Database\Seeders\Support\IvorianData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Portage du MainSeeder NestJS (backend/src/database/seeders/main.seeder.ts) :
 * chaque entité vise ~500 enregistrements, reliés entre eux (companies ->
 * users/jobs, jobs + users -> candidatures, candidatures ->
 * scoring/matching/interviews/events). Inserts en masse (DB::table()->insert
 * par lots) plutôt que via Eloquent un par un, pour rester rapide à cette
 * échelle.
 */
class GoriyaDemoSeeder extends Seeder
{
    private const TARGET_COMPANIES = 500;
    private const TARGET_USERS = 500;
    private const TARGET_JOBS = 500;
    private const TARGET_PORTFOLIOS = 500;
    private const TARGET_CANDIDATURES = 500;
    private const CHUNK = 200;

    public function run(): void
    {
        $companies = $this->seedCompanies();
        $users = $this->seedUsers($companies);
        $jobs = $this->seedJobOffers($companies);

        [$candidatureRows, $jobApplicantCounts] = $this->buildCandidatures($users, $jobs);
        $this->applyApplicantCounts($jobs, $jobApplicantCounts);

        $this->insertChunked('job_offers', $jobs);
        $this->command?->info('✅ JobOffers seeded ('.count($jobs).')');

        $this->seedPortfolios($users);

        $this->insertChunked('candidatures', $candidatureRows);
        $this->command?->info('✅ Candidatures seeded ('.count($candidatureRows).')');

        $this->seedScoringResults($candidatureRows);
        $this->seedMatchingResults($candidatureRows);
        $this->seedInterviewSessions($candidatureRows);
        $this->seedCvAnalysis($users);
        $this->seedCalendarEvents($candidatureRows);
    }

    /** @return array<int, array{id: string, name: string}> */
    private function seedCompanies(): array
    {
        $rows = [];
        $refs = [];
        $now = Carbon::now();

        foreach (IvorianData::generateCompanyNames(self::TARGET_COMPANIES) as $name) {
            $city = IvorianData::randomItem(IvorianData::CITIES);
            $slug = IvorianData::slugify($name);
            $id = (string) Str::uuid();

            $rows[] = [
                'id' => $id,
                'name' => $name,
                'sector' => IvorianData::randomItem(IvorianData::SECTORS),
                'logo' => IvorianData::companyLogoUrl($name),
                'cover_image' => "https://picsum.photos/seed/{$slug}/640/480",
                'about' => "{$name} est une entreprise ivoirienne reconnue dans son secteur, basée à {$city}.",
                'website' => "https://www.{$slug}.ci",
                'creation_date' => $now->copy()->subDays(random_int(30, 15 * 365))->toDateString(),
                'partnership_date' => $now->copy()->subDays(random_int(0, 180))->toDateString(),
                'company_size' => IvorianData::randomItem(IvorianData::COMPANY_SIZES),
                'country' => "Côte d'Ivoire",
                'headquarters' => $city,
                'location' => $city,
                'phone' => IvorianData::randomIvorianPhone(),
                'email' => "contact@{$slug}.ci",
                'status' => IvorianData::randomItem(array_map(fn ($c) => $c->value, CompanyStatus::cases())),
                'social_links' => json_encode([
                    "https://www.facebook.com/{$slug}",
                    "https://www.linkedin.com/company/{$slug}",
                    "https://www.instagram.com/{$slug}",
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $refs[] = ['id' => $id, 'name' => $name];
        }

        $this->insertChunked('companies', $rows);
        $this->command?->info('✅ Companies seeded ('.count($rows).')');

        return $refs;
    }

    /**
     * @param  array<int, array{id: string, name: string}>  $companies
     * @return array<int, array{id: string, name: string, email: string, role: string}>
     */
    private function seedUsers(array $companies): array
    {
        $rows = [];
        $refs = [];
        $now = Carbon::now();

        for ($i = 0; $i < self::TARGET_USERS; $i++) {
            $isEnterprise = $i % 3 === 0;
            $name = IvorianData::randomFullName();
            $role = $i === 0 ? UserRole::ADMIN : ($isEnterprise ? UserRole::ENTERPRISE : UserRole::USER);
            $id = (string) Str::uuid();

            $rows[] = [
                'id' => $id,
                'name' => $name,
                'email' => IvorianData::emailFromName($name, $i),
                'password' => Hash::make('password123'),
                'role' => $role->value,
                'status' => UserStatus::ACTIVE->value,
                'avatar' => 'https://i.pravatar.cc/150?u='.$id,
                'registration_date' => $now,
                'company_id' => $role === UserRole::ENTERPRISE ? IvorianData::randomItem($companies)['id'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $refs[] = ['id' => $id, 'name' => $name, 'email' => $rows[count($rows) - 1]['email'], 'role' => $role->value];
        }

        $this->insertChunked('users', $rows);
        $this->command?->info('✅ Users seeded ('.count($rows).')');

        return $refs;
    }

    /**
     * @param  array<int, array{id: string, name: string}>  $companies
     * @return array<int, array<string, mixed>>
     */
    private function seedJobOffers(array $companies): array
    {
        $target = self::TARGET_JOBS;
        $jobsPerCompany = (int) ceil($target / count($companies));
        $experiences = array_map(fn ($e) => $e->value, JobExperienceType::cases());
        $types = array_map(fn ($t) => $t->value, JobType::cases());

        $jobs = [];
        $now = Carbon::now();

        foreach ($companies as $company) {
            $count = random_int(max(1, $jobsPerCompany - 5), $jobsPerCompany + 5);

            for ($i = 0; $i < $count && count($jobs) < $target; $i++) {
                $template = IvorianData::randomItem(IvorianData::JOB_TEMPLATES);
                $location = IvorianData::randomItem(IvorianData::CITIES);
                $experience = IvorianData::randomItem($experiences);
                $publishDate = $now->copy()->subDays(random_int(0, 3));
                $endDate = $publishDate->copy()->addDays(random_int(1, 45));

                $jobs[] = [
                    'id' => (string) Str::uuid(),
                    'title' => $template['title'],
                    'location' => $location,
                    'type' => IvorianData::randomItem($types),
                    'experience' => $experience,
                    'salary' => IvorianData::formatSalary($experience),
                    'description' => IvorianData::buildJobDescription($template['title'], $company['name'], $location, $template['skills']),
                    'benefits' => IvorianData::buildJobBenefits(),
                    'requirements' => json_encode($template['skills']),
                    'status' => JobStatus::ACTIVE->value,
                    'applicants' => 0,
                    'publish_date' => $publishDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'company_id' => $company['id'],
                    'company_name' => $company['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $jobs;
    }

    /**
     * @param  array<int, array{id: string, name: string, email: string, role: string}>  $users
     * @param  array<int, array<string, mixed>>  $jobs
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, int>}
     */
    private function buildCandidatures(array $users, array $jobs): array
    {
        $candidateUsers = array_values(array_filter($users, fn ($u) => $u['role'] === UserRole::USER->value));
        $statuses = array_map(fn ($s) => $s->value, CandidatureStatus::cases());
        $now = Carbon::now();

        $owners = [];
        foreach ($candidateUsers as $user) {
            $count = random_int(1, 4);
            for ($i = 0; $i < $count; $i++) {
                $owners[] = $user;
            }
        }
        $owners = array_slice($owners, 0, self::TARGET_CANDIDATURES);

        $rows = [];
        $applicantCounts = [];

        foreach ($owners as $user) {
            $job = IvorianData::randomItem($jobs);
            $applicantCounts[$job['id']] = ($applicantCounts[$job['id']] ?? 0) + 1;

            $rows[] = [
                'id' => (string) Str::uuid(),
                'candidate_name' => $user['name'],
                'candidate_email' => $user['email'],
                'status' => IvorianData::randomItem($statuses),
                'score' => random_int(50, 100),
                'applied_date' => $now->copy()->subDays(random_int(0, 30)),
                'user_id' => $user['id'],
                'job_offer_id' => $job['id'],
                'job_offer_title' => $job['title'],
                'company_name' => $job['company_name'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return [$rows, $applicantCounts];
    }

    /**
     * @param  array<int, array<string, mixed>>  &$jobs
     * @param  array<string, int>  $applicantCounts
     */
    private function applyApplicantCounts(array &$jobs, array $applicantCounts): void
    {
        foreach ($jobs as &$job) {
            $job['applicants'] = $applicantCounts[$job['id']] ?? 0;
        }
        unset($job);
    }

    /** @param array<int, array{id: string, name: string, email: string, role: string}> $users */
    private function seedPortfolios(array $users): void
    {
        $now = Carbon::now();

        $owners = [];
        foreach ($users as $user) {
            $count = random_int(1, 3);
            for ($i = 0; $i < $count; $i++) {
                $owners[] = $user;
            }
        }
        $owners = array_slice($owners, 0, self::TARGET_PORTFOLIOS);

        $rows = [];
        foreach ($owners as $user) {
            $template = IvorianData::randomItem(IvorianData::JOB_TEMPLATES);
            $rows[] = [
                'id' => (string) Str::uuid(),
                'title' => $template['title'],
                'description' => 'Portfolio professionnel présentant mes réalisations en '.implode(', ', $template['skills']).'.',
                'skills' => json_encode($template['skills']),
                'views' => random_int(0, 500),
                'downloads' => random_int(0, 100),
                'likes' => random_int(0, 200),
                'created_date' => $now->copy()->subDays(random_int(0, 365)),
                'user_id' => $user['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->insertChunked('portfolios', $rows);
        $this->command?->info('✅ Portfolios seeded ('.count($rows).')');
    }

    /** @param array<int, array<string, mixed>> $candidatures */
    private function seedScoringResults(array $candidatures): void
    {
        $statuses = array_map(fn ($s) => $s->value, ScoringStatus::cases());
        $now = Carbon::now();

        $rows = array_map(fn ($c) => [
            'id' => (string) Str::uuid(),
            'candidate_name' => $c['candidate_name'],
            'candidate_email' => $c['candidate_email'],
            'position' => $c['job_offer_title'],
            'overall_score' => $c['score'],
            'criteria' => json_encode([
                'experience' => random_int(0, 100),
                'skills' => random_int(0, 100),
                'education' => random_int(0, 100),
            ]),
            'analysis_date' => $now->copy()->subDays(random_int(0, 30)),
            'status' => IvorianData::randomItem($statuses),
            'created_at' => $now,
            'updated_at' => $now,
        ], $candidatures);

        $this->insertChunked('scoring_results', $rows);
        $this->command?->info('✅ ScoringResults seeded ('.count($rows).')');
    }

    /** @param array<int, array<string, mixed>> $candidatures */
    private function seedMatchingResults(array $candidatures): void
    {
        $statuses = array_map(fn ($s) => $s->value, MatchingStatus::cases());
        $now = Carbon::now();

        $rows = array_map(function ($c) use ($statuses, $now) {
            return [
                'id' => (string) Str::uuid(),
                'candidate_name' => $c['candidate_name'],
                'candidate_email' => $c['candidate_email'],
                'position' => $c['job_offer_title'],
                'company' => $c['company_name'],
                'matching_score' => random_int(0, 100),
                'status' => IvorianData::randomItem($statuses),
                'match_date' => $now->copy()->subDays(random_int(0, 30)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $candidatures);

        $this->insertChunked('matching_results', $rows);
        $this->command?->info('✅ MatchingResults seeded ('.count($rows).')');
    }

    /** @param array<int, array<string, mixed>> $candidatures */
    private function seedInterviewSessions(array $candidatures): void
    {
        $statuses = array_map(fn ($s) => $s->value, InterviewStatus::cases());
        $feedbacks = [
            'Bonne maîtrise technique, communication claire.',
            'Profil motivé, à approfondir sur certains points techniques.',
            'Excellente présentation, expérience solide.',
            'Manque un peu de recul sur la gestion de projet.',
        ];
        $now = Carbon::now();

        $rows = array_map(fn ($c) => [
            'id' => (string) Str::uuid(),
            'candidate_name' => $c['candidate_name'],
            'candidate_email' => $c['candidate_email'],
            'position' => $c['job_offer_title'],
            'duration' => random_int(15, 90),
            'score' => random_int(0, 100),
            'status' => IvorianData::randomItem($statuses),
            'start_time' => $now->copy()->subDays(random_int(0, 30)),
            'feedback' => IvorianData::randomItem($feedbacks),
            'created_at' => $now,
            'updated_at' => $now,
        ], $candidatures);

        $this->insertChunked('interview_sessions', $rows);
        $this->command?->info('✅ InterviewSessions seeded ('.count($rows).')');
    }

    /** @param array<int, array{id: string, name: string, email: string, role: string}> $users */
    private function seedCvAnalysis(array $users): void
    {
        $statuses = array_map(fn ($s) => $s->value, CVStatus::cases());
        $recommendationsPool = [
            'Renforcer les compétences techniques',
            'Ajouter des projets concrets',
            'Mettre à jour les expériences professionnelles',
            'Détailler les réalisations chiffrées',
        ];
        $now = Carbon::now();

        $rows = array_map(function ($u) use ($statuses, $recommendationsPool, $now) {
            $picks = $recommendationsPool;
            shuffle($picks);

            return [
                'id' => (string) Str::uuid(),
                'filename' => "{$u['name']}_cv.pdf",
                'analysis_score' => random_int(0, 100),
                'recommendations' => json_encode(array_slice($picks, 0, 2)),
                'upload_date' => $now->copy()->subDays(random_int(0, 30)),
                'status' => IvorianData::randomItem($statuses),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $users);

        $this->insertChunked('cv_analysis', $rows);
        $this->command?->info('✅ CVAnalysis seeded ('.count($rows).')');
    }

    /** @param array<int, array<string, mixed>> $candidatures */
    private function seedCalendarEvents(array $candidatures): void
    {
        $now = Carbon::now();

        $rows = array_map(function ($c) use ($now) {
            $start = $now->copy()->addDays(random_int(1, 60));
            $end = $start->copy()->addMinutes(random_int(30, 90));

            return [
                'id' => (string) Str::uuid(),
                'title' => "Interview - {$c['candidate_name']}",
                'type' => EventType::ENTRETIEN->value,
                'start_time' => $start,
                'end_time' => $end,
                'location' => IvorianData::randomItem(IvorianData::CITIES),
                'status' => EventStatus::CONFIRMED->value,
                'participants' => json_encode([$c['candidate_email']]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $candidatures);

        $this->insertChunked('calendar_events', $rows);
        $this->command?->info('✅ CalendarEvents seeded ('.count($rows).')');
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function insertChunked(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            // Les colonnes utilitaires (job_offer_title, company_name) ne
            // correspondent à aucune colonne réelle : on les retire juste
            // avant l'insertion, elles ne servent qu'à alimenter les entités
            // dérivées des candidatures (scoring/matching/interviews/events).
            $clean = array_map(function ($row) {
                unset($row['job_offer_title'], $row['company_name']);

                return $row;
            }, $chunk);

            DB::table($table)->insert($clean);
        }
    }
}
