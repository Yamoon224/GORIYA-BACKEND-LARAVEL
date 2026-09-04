<?php

namespace App\Repositories\Eloquent;

use App\Models\Candidature;
use App\Repositories\Contracts\CandidatureRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CandidatureRepository extends BaseRepository implements CandidatureRepositoryInterface
{
    // `user.portfolios` / `user.cv` alimentent candidateSkills dans
    // CandidatureResource : sans eux la ressource ferait deux requetes par
    // candidature listee.
    private const RELATIONS = ['user', 'user.portfolios', 'user.cv', 'jobOffer', 'answers', 'resume'];

    protected function model(): string
    {
        return Candidature::class;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = Candidature::query()->with(self::RELATIONS);

        if ($candidateName = $filters['candidateName'] ?? null) {
            $query->whereILike('candidate_name', $candidateName);
        }
        if ($candidateEmail = $filters['candidateEmail'] ?? null) {
            $query->whereILike('candidate_email', $candidateEmail);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if (array_key_exists('score', $filters) && $filters['score'] !== null) {
            $query->where('score', $filters['score']);
        }
        if ($appliedDate = $filters['appliedDate'] ?? null) {
            $query->whereDate('applied_date', $appliedDate);
        }
        if ($userId = $filters['userId'] ?? null) {
            $query->where('user_id', $userId);
        }
        if ($jobOfferId = $filters['jobOfferId'] ?? null) {
            $query->where('job_offer_id', $jobOfferId);
        }

        $this->scopeToViewer($query, $filters);

        $query->orderByDesc('applied_date');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Restreint la liste à ce que l'appelant a le droit de voir : un candidat
     * ne voit que ses candidatures, une entreprise que celles déposées sur ses
     * propres offres, un admin voit tout.
     *
     * Sans ce filtre, /candidatures/paginate renvoyait à tout compte
     * authentifié les coordonnées, les réponses et le CV de tous les candidats
     * de la plateforme.
     *
     * @param  array<string, mixed>  $filters
     */
    private function scopeToViewer(Builder $query, array $filters): void
    {
        if ($filters['viewerIsAdmin'] ?? false) {
            return;
        }

        $viewerUserId = $filters['viewerUserId'] ?? null;
        $viewerCompanyId = $filters['viewerCompanyId'] ?? null;

        // Ni candidat ni entreprise identifiés : rien à montrer, plutôt que tout.
        if (! $viewerUserId && ! $viewerCompanyId) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $q) use ($viewerUserId, $viewerCompanyId) {
            if ($viewerUserId) {
                $q->orWhere('user_id', $viewerUserId);
            }
            if ($viewerCompanyId) {
                $q->orWhereHas('jobOffer', fn (Builder $offre) => $offre->where('company_id', $viewerCompanyId));
            }
        });
    }

    public function countByStatus(string $status): int
    {
        return Candidature::where('status', $status)->count();
    }

    public function existsForUserAndJob(string $userId, string $jobOfferId): bool
    {
        return Candidature::where('user_id', $userId)
            ->where('job_offer_id', $jobOfferId)
            ->exists();
    }

    public function appliedJobIds(string $userId): array
    {
        return Candidature::where('user_id', $userId)
            ->whereNotNull('job_offer_id')
            ->distinct()
            ->pluck('job_offer_id')
            ->all();
    }
}
