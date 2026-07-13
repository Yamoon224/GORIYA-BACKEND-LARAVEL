<?php

namespace App\Services;

use App\Contracts\CompanyResearchServiceInterface;
use App\Models\ResearchQuery;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Historique et favoris de recherches Goriya IA Research, scopés à
 * l'utilisateur authentifié — pas de route publique, comme CvAnalysis et
 * InterviewSessions.
 */
class CompanyResearchService
{
    public function __construct(private readonly CompanyResearchServiceInterface $researchProvider) {}

    public function listFor(User $user): Collection
    {
        return ResearchQuery::where('user_id', $user->id)->orderByDesc('created_at')->get();
    }

    public function research(User $user, string $companyName): ResearchQuery
    {
        $result = $this->researchProvider->research($companyName);

        return ResearchQuery::create([
            'user_id' => $user->id,
            'company_name' => $companyName,
            'result' => $result,
        ]);
    }

    public function find(string $id, User $user): ?ResearchQuery
    {
        return ResearchQuery::where('user_id', $user->id)->find($id);
    }

    public function toggleFavorite(ResearchQuery $query): ResearchQuery
    {
        $query->update(['is_favorite' => ! $query->is_favorite]);

        return $query;
    }

    public function delete(ResearchQuery $query): void
    {
        $query->delete();
    }
}
