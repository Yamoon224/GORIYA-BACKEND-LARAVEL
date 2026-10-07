<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Recommandations GORIYA Connect — à base de règles sur les données déjà
 * disponibles (communautés partagées, popularité), pas un nouvel appel
 * Claude : aucun signal texte à analyser ici, contrairement aux autres
 * services Anthropic* de la plateforme (voir aussi CareerDashboardService,
 * même choix pour la même raison).
 */
class ConnectRecommendationService
{
    public function __construct(
        private readonly ConnectionService $connectionService,
        private readonly CommunityService $communityService,
    ) {}

    /**
     * Personnes à suivre : membres des mêmes communautés, hors soi-même et
     * les personnes déjà suivies.
     */
    public function peopleToFollow(User $user, int $limit = 10): Collection
    {
        $communityIds = $this->communityService->communityIdsFor($user);
        $excludedIds = [...$this->connectionService->followingIds($user), $user->id];

        if ($communityIds === []) {
            return collect();
        }

        // Une seule requête : la base s'arrête dès que `$limit` membres sont
        // trouvés, au lieu de rapatrier l'identifiant de tous les membres de
        // ses communautés pour n'en garder que dix.
        return User::query()
            ->whereIn('id', CommunityMembership::query()->select('user_id')->whereIn('community_id', $communityIds))
            ->whereNotIn('id', $excludedIds)
            ->take($limit)
            ->get();
    }

    /**
     * Communautés suggérées : les plus actives parmi celles non rejointes.
     */
    public function suggestedCommunities(User $user, int $limit = 5): Collection
    {
        $joinedIds = $this->communityService->communityIdsFor($user);

        return Community::whereNotIn('id', $joinedIds)
            ->withCount('memberships')
            ->orderByDesc('memberships_count')
            ->take($limit)
            ->get();
    }

    /**
     * Offres à ne pas manquer — réutilise directement JobOffer (mêmes
     * critères que DashboardService::getRecommendedJobs()), pas de
     * duplication de logique de matching.
     */
    public function jobOffersToWatch(int $limit = 5): Collection
    {
        return JobOffer::where('status', JobStatus::ACTIVE)
            ->with('company')
            ->orderByDesc('publish_date')
            ->take($limit)
            ->get();
    }
}
