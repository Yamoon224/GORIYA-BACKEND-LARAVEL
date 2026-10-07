<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\CVStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Resources\CandidatureResource;
use App\Http\Resources\JobOfferResource;
use App\Models\Candidature;
use App\Models\Company;
use App\Models\Cv;
use App\Models\CvAnalysis;
use App\Models\InterviewSession;
use App\Models\JobOffer;
use App\Models\Pitch;
use App\Models\Presentation;
use App\Models\ResearchQuery;
use App\Models\User;
use App\Services\Concerns\CountsByPeriod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Mirroir de backend/src/dashboard/dashboard.service.ts. Utilisé par
 * DashboardController (préfixe public /dashboard) et AdminDashboardController
 * (préfixe /admin/dashboard, réponses enveloppées via ApiResponse::success).
 */
class DashboardService
{
    use CountsByPeriod;

    /** Relations lues par CandidatureResource et JobOfferResource : préchargées, jamais une requête par carte. */
    private const CANDIDATURE_RELATIONS = ['user', 'jobOffer.company', 'answers', 'resume'];

    private const OFFER_RELATIONS = ['company', 'questions'];

    public function __construct(private readonly BookmarkService $bookmarkService) {}

    /**
     * Dispatch vers les stats scopées à l'utilisateur courant — un ADMIN
     * garde les stats globales existantes (parité stricte, aucune régression
     * pour /admin/dashboard/stats qui appelle getStats() directement).
     *
     * @return array<string, mixed>
     */
    public function getStatsForUser(User $user, ?string $start, ?string $end): array
    {
        return match ($user->role) {
            UserRole::USER => $this->getStudentStats($user),
            UserRole::ENTERPRISE => $this->getCompanyStats($user->company_id, $start, $end),
            default => $this->getStats($start, $end),
        };
    }

    /**
     * @return array{totalApplications: int, interviews: int, profileViews: int, savedJobs: int}
     */
    public function getStudentStats(User $user): array
    {
        return [
            'totalApplications' => Candidature::where('user_id', $user->id)->count(),
            // Aucun InterviewSession n'a de FK vers un utilisateur — non
            // scopable, stub à 0 (même convention que profileViews ci-dessous).
            'interviews' => 0,
            'profileViews' => 0,
            'savedJobs' => $this->bookmarkService->savedJobsCount($user->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCompanyStats(?string $companyId, ?string $start, ?string $end): array
    {
        $range = $this->buildRange($start, $end);

        $applicationsQuery = Candidature::forCompany($companyId);
        $applicationsReceived = $range
            ? (clone $applicationsQuery)->whereBetween('applied_date', [$range['start'], $range['end']])->count()
            : $applicationsQuery->count();

        $activeOffers = JobOffer::where('company_id', $companyId)->where('status', JobStatus::ACTIVE)->count();

        // Aucun tracking de vues ni de FK InterviewSession->utilisateur —
        // stubs à 0, même convention que profileViews/getProfileViews().
        $weeklyViews = 0;
        $interviewsScheduled = 0;

        $recentCandidates = Candidature::forCompany($companyId)
            ->with(self::CANDIDATURE_RELATIONS)
            ->orderByDesc('applied_date')->take(5)->get();

        $topOffers = JobOffer::where('company_id', $companyId)->where('status', JobStatus::ACTIVE)
            ->with(self::OFFER_RELATIONS)->orderByDesc('applicants')->take(5)->get();

        $recentOffers = JobOffer::where('company_id', $companyId)
            ->with(self::OFFER_RELATIONS)->orderByDesc('publish_date')->take(5)->get();

        // Ordre fixe attendu par entreprise/app/(protected)/dashboard/content.tsx
        // (lecture positionnelle statsData[i].value) — ne pas réordonner.
        $statsData = [
            ['key' => 'activeOffers', 'label' => 'Annonces actives', 'value' => $activeOffers],
            ['key' => 'applicationsReceived', 'label' => 'Candidatures recues', 'value' => $applicationsReceived],
            ['key' => 'weeklyViews', 'label' => 'Vues cette semaine', 'value' => $weeklyViews],
            ['key' => 'interviewsScheduled', 'label' => 'Entretiens planifies', 'value' => $interviewsScheduled],
        ];

        return [
            'statsData' => $statsData,
            'chartData' => $this->getCompanyMonthlyTrend($companyId, 6),
            'lineChartData' => $this->getRecentOffersTrend(6, $companyId),
            'recentCandidates' => CandidatureResource::collection($recentCandidates),
            'topOffers' => JobOfferResource::collection($topOffers),
            'recentOffers' => JobOfferResource::collection($recentOffers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(?string $start, ?string $end): array
    {
        $range = $this->buildRange($start, $end);

        $applicationsInRange = $range
            ? Candidature::whereBetween('applied_date', [$range['start'], $range['end']])->count()
            : Candidature::count();

        $offersInRange = $range
            ? JobOffer::whereBetween('publish_date', [$range['start'], $range['end']])->count()
            : JobOffer::where('status', JobStatus::ACTIVE)->count();

        $activeStudents = User::where('role', UserRole::USER)->where('status', UserStatus::ACTIVE)->count();
        $partnerCompanies = Company::where('status', CompanyStatus::ACTIVE)->count();
        $analyzedCVs = CvAnalysis::where('status', CVStatus::COMPLETED)->count();
        $jobOffers = $offersInRange;
        $totalApplications = $applicationsInRange;
        $interviews = InterviewSession::count();

        $recentCandidates = Candidature::with(self::CANDIDATURE_RELATIONS)
            ->orderByDesc('applied_date')->take(5)->get();

        $topOffers = JobOffer::where('status', JobStatus::ACTIVE)
            ->with(self::OFFER_RELATIONS)->orderByDesc('applicants')->take(5)->get();

        $recentOffers = JobOffer::with(self::OFFER_RELATIONS)
            ->orderByDesc('publish_date')->take(5)->get();

        // Libellés français copiés tels quels depuis la source NestJS (sans
        // accents pour certains — pas une faute de frappe à corriger ici).
        $statsData = [
            ['key' => 'activeStudents', 'label' => 'Etudiants actifs', 'value' => $activeStudents],
            ['key' => 'partnerCompanies', 'label' => 'Entreprises partenaires', 'value' => $partnerCompanies],
            ['key' => 'jobOffers', 'label' => 'Offres publiees', 'value' => $jobOffers],
            ['key' => 'totalApplications', 'label' => 'Candidatures', 'value' => $totalApplications],
            ['key' => 'interviews', 'label' => 'Entretiens', 'value' => $interviews],
            ['key' => 'analyzedCVs', 'label' => 'CV analyses', 'value' => $analyzedCVs],
        ];

        return [
            'activeStudents' => $activeStudents,
            'partnerCompanies' => $partnerCompanies,
            'analyzedCVs' => $analyzedCVs,
            'jobOffers' => $jobOffers,
            'totalApplications' => $totalApplications,
            'interviews' => $interviews,
            // Jamais implémentés côté NestJS — zéros littéraux, pas un TODO.
            'profileViews' => 0,
            'savedJobs' => 0,
            'statsData' => $statsData,
            'monthly' => $this->getMonthlyGrowth(),
            // Les deux compteurs déjà lus ci-dessus ne sont pas recalculés.
            'aiTools' => $this->getAiToolsUsage($analyzedCVs, $interviews),
            'chartData' => $this->getPerformanceData('month'),
            'lineChartData' => $this->getRecentOffersTrend(6),
            'recentCandidates' => CandidatureResource::collection($recentCandidates),
            'topOffers' => JobOfferResource::collection($topOffers),
            'recentOffers' => JobOfferResource::collection($recentOffers),
        ];
    }

    /**
     * Volumes créés depuis le 1er du mois en cours — alimente les mentions
     * « ce mois-ci » des cartes et le bloc « Croissance mensuelle » du
     * tableau de bord admin (auparavant des pourcentages codés en dur).
     *
     * @return array{newCandidates: int, newCompanies: int, cvAnalyzed: int, newJobOffers: int, applications: int}
     */
    public function getMonthlyGrowth(): array
    {
        $monthStart = now()->startOfMonth();

        return [
            'newCandidates' => User::where('role', UserRole::USER)->where('created_at', '>=', $monthStart)->count(),
            'newCompanies' => Company::where('created_at', '>=', $monthStart)->count(),
            'cvAnalyzed' => CvAnalysis::where('status', CVStatus::COMPLETED)->where('upload_date', '>=', $monthStart)->count(),
            'newJobOffers' => JobOffer::where('publish_date', '>=', $monthStart)->count(),
            'applications' => Candidature::where('applied_date', '>=', $monthStart)->count(),
        ];
    }

    /**
     * Nombre d'utilisations enregistrées par outil IA, tous utilisateurs
     * confondus — remplace les pourcentages de « performance » fictifs.
     *
     * @return array<int, array{key: string, name: string, value: int}>
     */
    public function getAiToolsUsage(?int $analyzedCVs = null, ?int $interviews = null): array
    {
        return [
            ['key' => 'cvAnalysis', 'name' => 'Analyse de CV', 'value' => $analyzedCVs ?? CvAnalysis::where('status', CVStatus::COMPLETED)->count()],
            ['key' => 'cvCreation', 'name' => 'Création de CV', 'value' => Cv::count()],
            ['key' => 'interview', 'name' => "Simulation d'entretien", 'value' => $interviews ?? InterviewSession::count()],
            ['key' => 'pitch', 'name' => 'Pitch Goriya', 'value' => Pitch::count()],
            ['key' => 'presentation', 'name' => 'Présentations IA', 'value' => Presentation::count()],
            ['key' => 'research', 'name' => 'Recherche entreprise', 'value' => ResearchQuery::count()],
        ];
    }

    public function getRecentApplications(int $limit, ?User $user = null): mixed
    {
        $query = Candidature::with(self::CANDIDATURE_RELATIONS)->orderByDesc('applied_date');

        if ($user?->role === UserRole::USER) {
            $query->where('user_id', $user->id);
        } elseif ($user?->role === UserRole::ENTERPRISE) {
            $query->forCompany($user->company_id);
        }

        return CandidatureResource::collection($query->take($limit)->get());
    }

    public function getRecommendedJobs(int $limit): mixed
    {
        $jobs = JobOffer::where('status', JobStatus::ACTIVE)
            ->with(self::OFFER_RELATIONS)
            ->orderByDesc('publish_date')
            ->take($limit)
            ->get();

        return JobOfferResource::collection($jobs);
    }

    /**
     * @return array{views: array<int, array{date: string, count: int}>, total: int}
     */
    public function getProfileViews(int $days): array
    {
        $now = now();
        $views = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $views[] = [
                'date' => $now->copy()->subDays($i)->format('Y-m-d'),
                'count' => 0,
            ];
        }

        // Aucun tracking de vues n'existe réellement dans la source — stub
        // fidèle, pas une fonctionnalité à construire.
        return ['views' => $views, 'total' => 0];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{start: Carbon, end: Carbon}|null
     */
    private function buildRange(?string $start, ?string $end): ?array
    {
        $startDate = $this->parseDate($start);
        $endDate = $this->parseDate($end);

        if (! $startDate && ! $endDate) {
            return null;
        }

        return [
            'start' => $startDate ?? Carbon::parse('1970-01-01T00:00:00.000Z'),
            'end' => $endDate ?? now(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPerformanceData(?string $period): array
    {
        $now = now();
        $data = [];

        if ($period === 'week') {
            $dayNames = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
            $counts = $this->countPerDay(Candidature::query(), 'applied_date', $now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay());
            for ($i = 6; $i >= 0; $i--) {
                $dayStart = $now->copy()->subDays($i)->startOfDay();
                $data[] = ['month' => $dayNames[$dayStart->dayOfWeek], 'value' => $counts[$dayStart->format('Y-m-d')] ?? 0];
            }
        } else {
            $monthCount = $period === 'year' ? 12 : 6;
            $monthNames = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

            return $this->monthlySeries(Candidature::query(), 'applied_date', $monthCount, $monthNames);
        }

        return $data;
    }

    /**
     * Série des `$monthCount` derniers mois (le mois courant compris), dans
     * l'ordre chronologique : une requête pour toute la série.
     *
     * @param  list<string>  $monthNames
     * @return list<array{month: string, value: int, label: string}>
     */
    private function monthlySeries(Builder $query, string $column, int $monthCount, array $monthNames): array
    {
        $now = now();
        $first = $now->copy()->startOfMonth()->subMonths($monthCount - 1);
        $counts = $this->countPerMonth($query, $column, $first, $now->copy()->endOfMonth());

        $data = [];
        for ($i = $monthCount - 1; $i >= 0; $i--) {
            $monthStart = $now->copy()->startOfMonth()->subMonths($i);
            $data[] = [
                'month' => $monthNames[$monthStart->month - 1],
                'value' => $counts[$monthStart->format('Y-m')] ?? 0,
                'label' => "{$monthNames[$monthStart->month - 1]} {$monthStart->year}",
            ];
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentOffersTrend(int $monthCount = 6, ?string $companyId = null): array
    {
        // Liste non accentuée, différente de celle de getPerformanceData() —
        // incohérence réelle de la source, copiée telle quelle.
        $monthNames = ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'];

        return $this->monthlySeries(
            JobOffer::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId)),
            'publish_date',
            $monthCount,
            $monthNames,
        );
    }

    /**
     * Candidatures reçues par mois pour les offres d'une entreprise donnée —
     * équivalent company-scopé de getPerformanceData('month').
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCompanyMonthlyTrend(?string $companyId, int $monthCount = 6): array
    {
        $monthNames = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

        return $this->monthlySeries(Candidature::forCompany($companyId), 'applied_date', $monthCount, $monthNames);
    }
}
