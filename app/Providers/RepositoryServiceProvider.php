<?php

namespace App\Providers;

use App\Repositories\Contracts\AnonymousUsageRepositoryInterface;
use App\Repositories\Contracts\CalendarEventRepositoryInterface;
use App\Repositories\Contracts\CandidatureRepositoryInterface;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use App\Repositories\Contracts\CvAnalysisRepositoryInterface;
use App\Repositories\Contracts\InterviewSessionRepositoryInterface;
use App\Repositories\Contracts\JobOfferRepositoryInterface;
use App\Repositories\Contracts\MatchingResultRepositoryInterface;
use App\Repositories\Contracts\PortfolioRepositoryInterface;
use App\Repositories\Contracts\ScoringResultRepositoryInterface;
use App\Repositories\Contracts\SubscriptionPlanRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\UserSubscriptionRepositoryInterface;
use App\Repositories\Eloquent\AnonymousUsageRepository;
use App\Repositories\Eloquent\CalendarEventRepository;
use App\Repositories\Eloquent\CandidatureRepository;
use App\Repositories\Eloquent\CompanyRepository;
use App\Repositories\Eloquent\CvAnalysisRepository;
use App\Repositories\Eloquent\InterviewSessionRepository;
use App\Repositories\Eloquent\JobOfferRepository;
use App\Repositories\Eloquent\MatchingResultRepository;
use App\Repositories\Eloquent\PortfolioRepository;
use App\Repositories\Eloquent\ScoringResultRepository;
use App\Repositories\Eloquent\SubscriptionPlanRepository;
use App\Repositories\Eloquent\UserRepository;
use App\Repositories\Eloquent\UserSubscriptionRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const BINDINGS = [
        UserRepositoryInterface::class => UserRepository::class,
        CompanyRepositoryInterface::class => CompanyRepository::class,
        JobOfferRepositoryInterface::class => JobOfferRepository::class,
        CandidatureRepositoryInterface::class => CandidatureRepository::class,
        CalendarEventRepositoryInterface::class => CalendarEventRepository::class,
        PortfolioRepositoryInterface::class => PortfolioRepository::class,
        CvAnalysisRepositoryInterface::class => CvAnalysisRepository::class,
        InterviewSessionRepositoryInterface::class => InterviewSessionRepository::class,
        MatchingResultRepositoryInterface::class => MatchingResultRepository::class,
        ScoringResultRepositoryInterface::class => ScoringResultRepository::class,
        SubscriptionPlanRepositoryInterface::class => SubscriptionPlanRepository::class,
        UserSubscriptionRepositoryInterface::class => UserSubscriptionRepository::class,
        AnonymousUsageRepositoryInterface::class => AnonymousUsageRepository::class,
    ];

    public function register(): void
    {
        foreach (self::BINDINGS as $interface => $implementation) {
            $this->app->bind($interface, $implementation);
        }
    }
}
