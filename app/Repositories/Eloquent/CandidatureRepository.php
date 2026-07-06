<?php

namespace App\Repositories\Eloquent;

use App\Models\Candidature;
use App\Repositories\Contracts\CandidatureRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CandidatureRepository extends BaseRepository implements CandidatureRepositoryInterface
{
    private const RELATIONS = ['user', 'jobOffer'];

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
            $query->where('candidate_name', 'ilike', "%{$candidateName}%");
        }
        if ($candidateEmail = $filters['candidateEmail'] ?? null) {
            $query->where('candidate_email', 'ilike', "%{$candidateEmail}%");
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

        $query->orderByDesc('applied_date');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function countByStatus(string $status): int
    {
        return Candidature::where('status', $status)->count();
    }
}
