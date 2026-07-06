<?php

use App\Http\Controllers\Api\AdminAiController;
use App\Http\Controllers\Api\AdminAnalyticsController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminCompaniesController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminJobsController;
use App\Http\Controllers\Api\AdminPlanningController;
use App\Http\Controllers\Api\AdminPortfoliosController;
use App\Http\Controllers\Api\AdminStudentsController;
use App\Http\Controllers\Api\AdminSystemController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AnonymousUsageController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarEventsController;
use App\Http\Controllers\Api\CandidaturesController;
use App\Http\Controllers\Api\CompaniesController;
use App\Http\Controllers\Api\CvAnalysisController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InterviewSessionsController;
use App\Http\Controllers\Api\JobOffersController;
use App\Http\Controllers\Api\MatchingResultsController;
use App\Http\Controllers\Api\PortfoliosController;
use App\Http\Controllers\Api\ScoringResultsController;
use App\Http\Controllers\Api\SubscriptionsController;
use App\Http\Controllers\Api\UsersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Parité stricte avec le backend NestJS : mêmes chemins, sans préfixe /api
| (voir bootstrap/app.php, apiPrefix: ''). Posture "protégé par défaut",
| comme le JwtAuthGuard global de NestJS — voir Section "Auth / security
| design" du plan de migration pour l'énumération exacte des routes
| publiques/privées/admin.
|
| Rempli au fil des phases (Phase 1 : auth + users + companies, etc.).
|
| Routes déclarées middleware par middleware (pas de groupes contigus) : les
| routes statiques ("paginate") doivent être enregistrées AVANT les routes à
| segment générique ("{id}") pour le même verbe HTTP, sous peine d'être
| avalées par le wildcard — Laravel matche dans l'ordre d'enregistrement.
|
*/

// --- Auth ---
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::post('/auth/google', [AuthController::class, 'google']);
Route::get('/auth/profile', [AuthController::class, 'profile'])->middleware('auth:api');
Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('auth:api');

// --- Users ---
Route::post('/users', [UsersController::class, 'store']);
Route::get('/users', [UsersController::class, 'index'])->middleware(['auth:api', 'role:ADMIN']);
Route::get('/users/paginate', [UsersController::class, 'paginate'])->middleware(['auth:api', 'role:ADMIN']);
// NOTE: pas de restriction "propriétaire uniquement" sur show/update — limitation
// héritée du backend NestJS (RolesGuard laisse passer tout utilisateur authentifié
// ici), volontairement préservée pour la parité.
Route::get('/users/{id}', [UsersController::class, 'show'])->middleware('auth:api');
Route::patch('/users/{id}', [UsersController::class, 'update'])->middleware('auth:api');
Route::delete('/users/{id}', [UsersController::class, 'destroy'])->middleware(['auth:api', 'role:ADMIN']);

// --- Companies ---
Route::get('/companies', [CompaniesController::class, 'index']);
Route::get('/companies/paginate', [CompaniesController::class, 'paginate']);
Route::get('/companies/{id}', [CompaniesController::class, 'show']);
Route::post('/companies', [CompaniesController::class, 'store'])->middleware('auth:api');
Route::patch('/companies/{id}', [CompaniesController::class, 'update'])->middleware('auth:api');
Route::delete('/companies/{id}', [CompaniesController::class, 'destroy'])->middleware('auth:api');

// --- Job Offers ---
Route::get('/job-offers', [JobOffersController::class, 'index']);
Route::get('/job-offers/paginate', [JobOffersController::class, 'paginate']);
Route::get('/job-offers/{id}', [JobOffersController::class, 'show']);
Route::post('/job-offers', [JobOffersController::class, 'store'])->middleware('auth:api');
Route::patch('/job-offers/{id}', [JobOffersController::class, 'update'])->middleware('auth:api');
Route::delete('/job-offers/{id}', [JobOffersController::class, 'destroy'])->middleware('auth:api');

// --- Portfolios ---
Route::get('/portfolios', [PortfoliosController::class, 'index']);
Route::get('/portfolios/paginate', [PortfoliosController::class, 'paginate']);
Route::get('/portfolios/{id}', [PortfoliosController::class, 'show']);
Route::post('/portfolios', [PortfoliosController::class, 'store'])->middleware('auth:api');
Route::patch('/portfolios/{id}', [PortfoliosController::class, 'update'])->middleware('auth:api');
Route::delete('/portfolios/{id}', [PortfoliosController::class, 'destroy'])->middleware('auth:api');

// --- Candidatures (aucune route publique, contrairement aux deux précédents) ---
Route::middleware('auth:api')->group(function () {
    Route::get('/candidatures', [CandidaturesController::class, 'index']);
    Route::get('/candidatures/paginate', [CandidaturesController::class, 'paginate']);
    Route::get('/candidatures/{id}', [CandidaturesController::class, 'show']);
    Route::post('/candidatures', [CandidaturesController::class, 'store']);
    Route::patch('/candidatures/{id}', [CandidaturesController::class, 'update']);
    Route::delete('/candidatures/{id}', [CandidaturesController::class, 'destroy']);
});

// --- Calendar Events / Interview Sessions / Matching Results / Scoring Results
// (aucune route publique dans aucun de ces 4 contrôleurs NestJS)
Route::middleware('auth:api')->group(function () {
    Route::get('/calendar-events', [CalendarEventsController::class, 'index']);
    Route::get('/calendar-events/paginate', [CalendarEventsController::class, 'paginate']);
    Route::get('/calendar-events/{id}', [CalendarEventsController::class, 'show']);
    Route::post('/calendar-events', [CalendarEventsController::class, 'store']);
    Route::patch('/calendar-events/{id}', [CalendarEventsController::class, 'update']);
    Route::delete('/calendar-events/{id}', [CalendarEventsController::class, 'destroy']);

    Route::get('/interview-sessions', [InterviewSessionsController::class, 'index']);
    Route::get('/interview-sessions/paginate', [InterviewSessionsController::class, 'paginate']);
    Route::get('/interview-sessions/{id}', [InterviewSessionsController::class, 'show']);
    Route::post('/interview-sessions', [InterviewSessionsController::class, 'store']);
    Route::patch('/interview-sessions/{id}', [InterviewSessionsController::class, 'update']);
    Route::delete('/interview-sessions/{id}', [InterviewSessionsController::class, 'destroy']);

    Route::get('/matching-results', [MatchingResultsController::class, 'index']);
    Route::get('/matching-results/paginate', [MatchingResultsController::class, 'paginate']);
    Route::get('/matching-results/{id}', [MatchingResultsController::class, 'show']);
    Route::post('/matching-results', [MatchingResultsController::class, 'store']);
    Route::patch('/matching-results/{id}', [MatchingResultsController::class, 'update']);
    Route::delete('/matching-results/{id}', [MatchingResultsController::class, 'destroy']);

    // NOTE: préfixe NestJS réel est 'scoring-results', pas 'scoring'.
    Route::get('/scoring-results', [ScoringResultsController::class, 'index']);
    Route::get('/scoring-results/paginate', [ScoringResultsController::class, 'paginate']);
    Route::get('/scoring-results/{id}', [ScoringResultsController::class, 'show']);
    Route::post('/scoring-results', [ScoringResultsController::class, 'store']);
    Route::patch('/scoring-results/{id}', [ScoringResultsController::class, 'update']);
    Route::delete('/scoring-results/{id}', [ScoringResultsController::class, 'destroy']);
});

// --- CV Analysis (aucune route publique) ---
Route::middleware('auth:api')->group(function () {
    Route::get('/cv-analysis', [CvAnalysisController::class, 'index']);
    Route::get('/cv-analysis/paginate', [CvAnalysisController::class, 'paginate']);
    Route::get('/cv-analysis/{id}', [CvAnalysisController::class, 'show']);
    Route::post('/cv-analysis', [CvAnalysisController::class, 'store']);
    Route::patch('/cv-analysis/{id}', [CvAnalysisController::class, 'update']);
    Route::delete('/cv-analysis/{id}', [CvAnalysisController::class, 'destroy']);
});

// --- Subscriptions ---
Route::get('/subscriptions/plans', [SubscriptionsController::class, 'plans']);
Route::get('/subscriptions/check/{userId}', [SubscriptionsController::class, 'check']);
Route::post('/subscriptions/subscribe', [SubscriptionsController::class, 'subscribe'])->middleware('auth:api');
Route::get('/subscriptions/me/{userId}', [SubscriptionsController::class, 'mySubscription'])->middleware('auth:api');
Route::delete('/subscriptions/me/{userId}', [SubscriptionsController::class, 'cancel'])->middleware('auth:api');
Route::post('/subscriptions/checkout', [SubscriptionsController::class, 'checkout'])->middleware('auth:api');
Route::get('/subscriptions/checkout/verify/{sessionId}', [SubscriptionsController::class, 'verifyCheckout'])->middleware('auth:api');
Route::get('/subscriptions/admin/stats', [SubscriptionsController::class, 'adminStats'])->middleware(['auth:api', 'role:ADMIN']);
Route::get('/subscriptions/admin/all', [SubscriptionsController::class, 'adminAll'])->middleware(['auth:api', 'role:ADMIN']);

// --- Anonymous Usage (entièrement public) ---
Route::post('/anonymous-usage/consume', [AnonymousUsageController::class, 'consume']);
Route::get('/anonymous-usage/status', [AnonymousUsageController::class, 'status']);

// --- Dashboard (admin uniquement) ---
Route::middleware(['auth:api', 'role:ADMIN'])->group(function () {
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/performance', [DashboardController::class, 'performance']);
    Route::get('/dashboard/recent-applications', [DashboardController::class, 'recentApplications']);
    Route::get('/dashboard/recommended-jobs', [DashboardController::class, 'recommendedJobs']);
    Route::get('/dashboard/profile-views', [DashboardController::class, 'profileViews']);
});

// --- Analytics (admin uniquement) ---
Route::middleware(['auth:api', 'role:ADMIN'])->group(function () {
    Route::get('/analytics', [AnalyticsController::class, 'index']);
    Route::get('/analytics/evolution', [AnalyticsController::class, 'evolution']);
    Route::get('/analytics/activity', [AnalyticsController::class, 'activity']);
    Route::get('/analytics/kpis', [AnalyticsController::class, 'kpis']);
    Route::get('/analytics/export', [AnalyticsController::class, 'export']);
});

// --- Admin: Auth (mostly public / any-authenticated-user ; seule /admin/users
// est role-gated — parité avec AdminAuthController, qui n'a pas de @Roles au
// niveau classe contrairement aux autres contrôleurs Admin) ---
Route::post('/admin/auth/login', [AdminAuthController::class, 'login']);
Route::post('/admin/auth/logout', [AdminAuthController::class, 'logout']);
Route::post('/admin/auth/verify-otp', [AdminAuthController::class, 'verifyOtp']);
Route::post('/admin/auth/google', [AdminAuthController::class, 'googleLogin']);
Route::post('/admin/auth/refresh', [AdminAuthController::class, 'refresh'])->middleware('auth:api');
Route::get('/admin/auth/profile', [AdminAuthController::class, 'profile'])->middleware('auth:api');
Route::put('/admin/user/profile', [AdminAuthController::class, 'updateProfile'])->middleware('auth:api');
Route::post('/admin/user/avatar', [AdminAuthController::class, 'uploadAvatar'])->middleware('auth:api');
Route::post('/admin/users', [AdminAuthController::class, 'register'])->middleware(['auth:api', 'role:ADMIN']);

// --- Admin: Students / Companies / Job Offers + Candidatures / Planning /
// Dashboard / Analytics (tous role:ADMIN). Segments statiques (paginate,
// stats, sectors, export) déclarés avant les wildcards {id}/{companyId}/
// {jobId} pour le même verbe HTTP — même règle que partout ailleurs dans ce
// fichier.
Route::middleware(['auth:api', 'role:ADMIN'])->group(function () {
    Route::get('/admin/students/paginate', [AdminStudentsController::class, 'paginate']);
    Route::get('/admin/students/stats', [AdminStudentsController::class, 'stats']);
    Route::get('/admin/students/export', [AdminStudentsController::class, 'export']);
    Route::get('/admin/students/{id}', [AdminStudentsController::class, 'show']);
    Route::post('/admin/students', [AdminStudentsController::class, 'store']);
    Route::patch('/admin/students/{id}', [AdminStudentsController::class, 'update']);
    Route::patch('/admin/students/{id}/status', [AdminStudentsController::class, 'updateStatus']);
    Route::delete('/admin/students/{id}', [AdminStudentsController::class, 'destroy']);

    Route::get('/admin/companies/paginate', [AdminCompaniesController::class, 'paginate']);
    Route::get('/admin/companies/stats', [AdminCompaniesController::class, 'stats']);
    Route::get('/admin/companies/sectors', [AdminCompaniesController::class, 'sectors']);
    Route::post('/admin/companies', [AdminCompaniesController::class, 'store']);
    Route::post('/admin/companies/{companyId}/follow', [AdminCompaniesController::class, 'follow']);
    Route::delete('/admin/companies/{companyId}/follow', [AdminCompaniesController::class, 'unfollow']);
    Route::get('/admin/companies/{companyId}/jobs', [AdminCompaniesController::class, 'companyJobs']);
    Route::get('/admin/companies/{id}', [AdminCompaniesController::class, 'show']);
    Route::patch('/admin/companies/{id}', [AdminCompaniesController::class, 'update']);
    Route::patch('/admin/companies/{id}/status', [AdminCompaniesController::class, 'updateStatus']);
    Route::delete('/admin/companies/{id}', [AdminCompaniesController::class, 'destroy']);

    Route::get('/admin/job-offers/paginate', [AdminJobsController::class, 'paginateJobOffers']);
    Route::get('/admin/job-offers/stats', [AdminJobsController::class, 'jobStats']);
    Route::get('/admin/job-offers/sectors', [AdminJobsController::class, 'jobSectors']);
    Route::post('/admin/job-offers', [AdminJobsController::class, 'storeJobOffer']);
    Route::post('/admin/job-offers/{jobId}/apply', [AdminJobsController::class, 'applyToJob']);
    Route::post('/admin/job-offers/{jobId}/save', [AdminJobsController::class, 'saveJob']);
    Route::delete('/admin/job-offers/{jobId}/save', [AdminJobsController::class, 'unsaveJob']);
    Route::get('/admin/job-offers/{id}', [AdminJobsController::class, 'showJobOffer']);
    Route::patch('/admin/job-offers/{id}', [AdminJobsController::class, 'updateJobOffer']);
    Route::patch('/admin/job-offers/{id}/status', [AdminJobsController::class, 'updateJobStatus']);
    Route::delete('/admin/job-offers/{id}', [AdminJobsController::class, 'destroyJobOffer']);

    Route::get('/admin/candidatures/paginate', [AdminJobsController::class, 'paginateCandidatures']);
    Route::get('/admin/candidatures/stats', [AdminJobsController::class, 'candidatureStats']);
    Route::get('/admin/candidatures/{id}', [AdminJobsController::class, 'showCandidature']);
    Route::patch('/admin/candidatures/{id}/status', [AdminJobsController::class, 'updateCandidatureStatus']);
    Route::delete('/admin/candidatures/{id}', [AdminJobsController::class, 'destroyCandidature']);

    Route::get('/admin/planning/stats', [AdminPlanningController::class, 'stats']);
    Route::get('/admin/planning/upcoming', [AdminPlanningController::class, 'upcoming']);
    Route::get('/admin/planning/events', [AdminPlanningController::class, 'events']);
    Route::get('/admin/planning/events/{id}', [AdminPlanningController::class, 'showEvent']);
    Route::post('/admin/planning/events', [AdminPlanningController::class, 'storeEvent']);
    Route::patch('/admin/planning/events/{id}', [AdminPlanningController::class, 'updateEvent']);
    Route::delete('/admin/planning/events/{id}', [AdminPlanningController::class, 'destroyEvent']);

    Route::get('/admin/dashboard/stats', [AdminDashboardController::class, 'stats']);
    Route::get('/admin/dashboard/performance', [AdminDashboardController::class, 'performance']);
    Route::get('/admin/dashboard/recent-applications', [AdminDashboardController::class, 'recentApplications']);
    Route::get('/admin/dashboard/recommended-jobs', [AdminDashboardController::class, 'recommendedJobs']);
    Route::get('/admin/dashboard/profile-views', [AdminDashboardController::class, 'profileViews']);

    Route::get('/admin/analytics', [AdminAnalyticsController::class, 'index']);
    Route::get('/admin/analytics/evolution', [AdminAnalyticsController::class, 'evolution']);
    Route::get('/admin/analytics/activity', [AdminAnalyticsController::class, 'activity']);
    Route::get('/admin/analytics/kpis', [AdminAnalyticsController::class, 'kpis']);
    Route::get('/admin/analytics/export', [AdminAnalyticsController::class, 'export']);

    // --- Admin: Portfolios (Phase 7B) ---
    Route::get('/admin/portfolios/stats', [AdminPortfoliosController::class, 'stats']);
    Route::get('/admin/portfolios/paginate', [AdminPortfoliosController::class, 'paginate']);
    Route::get('/admin/portfolios/featured', [AdminPortfoliosController::class, 'featured']);
    Route::get('/admin/portfolios/categories', [AdminPortfoliosController::class, 'categories']);
    Route::get('/admin/portfolios/{id}', [AdminPortfoliosController::class, 'show']);
    Route::patch('/admin/portfolios/{id}/feature', [AdminPortfoliosController::class, 'feature']);
    Route::delete('/admin/portfolios/{id}', [AdminPortfoliosController::class, 'destroy']);

    // --- Admin: AI — CV analysis / interview simulation / matching / scoring (Phase 7B) ---
    Route::get('/admin/cv-analysis/stats', [AdminAiController::class, 'cvStats']);
    Route::get('/admin/cv-analysis/recent', [AdminAiController::class, 'recentCvAnalysis']);
    Route::get('/admin/cv-analysis/recommendations', [AdminAiController::class, 'cvRecommendations']);
    Route::get('/admin/cv-analysis/{id}', [AdminAiController::class, 'showCvAnalysis']);
    Route::post('/admin/cv/analyze', [AdminAiController::class, 'analyzeCv']);
    Route::post('/admin/cv/upload', [AdminAiController::class, 'uploadCv']);
    Route::delete('/admin/cv-analysis/{id}', [AdminAiController::class, 'destroyCvAnalysis']);

    Route::get('/admin/interview-simulation/stats', [AdminAiController::class, 'interviewStats']);
    Route::get('/admin/interview-simulation/sessions', [AdminAiController::class, 'interviewSessions']);
    Route::get('/admin/interview-simulation/active', [AdminAiController::class, 'activeInterviewSessions']);
    Route::get('/admin/interview-simulation/history', [AdminAiController::class, 'interviewHistory']);
    Route::get('/admin/interview-simulation/sessions/{id}', [AdminAiController::class, 'showInterviewSession']);
    Route::post('/admin/interview-simulation/start', [AdminAiController::class, 'startInterview']);
    Route::patch('/admin/interview-simulation/sessions/{sessionId}/end', [AdminAiController::class, 'endInterview']);
    Route::delete('/admin/interview-simulation/sessions/{id}', [AdminAiController::class, 'destroyInterviewSession']);

    Route::get('/admin/matching/stats', [AdminAiController::class, 'matchingStats']);
    Route::get('/admin/matching/recent', [AdminAiController::class, 'recentMatching']);
    Route::get('/admin/matching/algorithms', [AdminAiController::class, 'matchingAlgorithms']);
    Route::get('/admin/matching/activity', [AdminAiController::class, 'matchingActivity']);
    Route::post('/admin/matching/trigger', [AdminAiController::class, 'triggerMatching']);
    Route::patch('/admin/matching/{id}/status', [AdminAiController::class, 'updateMatchingStatus']);

    Route::get('/admin/scoring/stats', [AdminAiController::class, 'scoringStats']);
    Route::get('/admin/scoring/criteria', [AdminAiController::class, 'scoringCriteria']);
    Route::get('/admin/scoring/performance', [AdminAiController::class, 'scoringPerformance']);
    Route::get('/admin/scoring/recent', [AdminAiController::class, 'recentScoring']);
    Route::get('/admin/scoring/{id}', [AdminAiController::class, 'showScoringResult']);
    Route::post('/admin/scoring/analyze', [AdminAiController::class, 'analyzeScoring']);
    Route::patch('/admin/scoring/criteria', [AdminAiController::class, 'updateScoringCriteria']);

    // --- Admin: System — messaging / notifications / search / settings (Phase 7B) ---
    Route::get('/admin/messages/conversations', [AdminSystemController::class, 'conversations']);
    Route::get('/admin/messages/conversations/{conversationId}/messages', [AdminSystemController::class, 'conversationMessages']);
    Route::post('/admin/messages/conversations/{conversationId}/messages', [AdminSystemController::class, 'sendConversationMessage']);
    Route::put('/admin/messages/conversations/{conversationId}/read', [AdminSystemController::class, 'markConversationAsRead']);
    Route::post('/admin/messages/conversations', [AdminSystemController::class, 'createConversation']);

    Route::get('/admin/notifications', [AdminSystemController::class, 'notifications']);
    Route::put('/admin/notifications/read-all', [AdminSystemController::class, 'markAllNotificationsAsRead']);
    Route::put('/admin/notifications/settings', [AdminSystemController::class, 'updateNotificationSettings']);
    Route::put('/admin/notifications/{notificationId}/read', [AdminSystemController::class, 'markNotificationAsRead']);

    Route::get('/admin/search/candidates', [AdminSystemController::class, 'searchCandidates']);
    Route::get('/admin/search/offers', [AdminSystemController::class, 'searchOffers']);
    Route::get('/admin/search/filters', [AdminSystemController::class, 'searchFilters']);
    Route::get('/admin/search/export', [AdminSystemController::class, 'exportSearch']);
    Route::get('/admin/search', [AdminSystemController::class, 'search']);

    Route::get('/admin/settings/email', [AdminSystemController::class, 'emailSettings']);
    Route::patch('/admin/settings/email', [AdminSystemController::class, 'updateEmailSettings']);
    Route::post('/admin/settings/email/test', [AdminSystemController::class, 'testEmailSettings']);
    Route::get('/admin/settings', [AdminSystemController::class, 'settings']);
    Route::patch('/admin/settings', [AdminSystemController::class, 'updateSettings']);
});
