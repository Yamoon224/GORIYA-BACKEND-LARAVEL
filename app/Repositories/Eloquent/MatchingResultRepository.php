<?php

namespace App\Repositories\Eloquent;

use App\Models\MatchingResult;
use App\Repositories\Contracts\MatchingResultRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class MatchingResultRepository extends BaseRepository implements MatchingResultRepositoryInterface
{
    protected function model(): string
    {
        return MatchingResult::class;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = MatchingResult::query();

        if ($candidateName = $filters['candidateName'] ?? null) {
            $query->where('candidate_name', 'ilike', "%{$candidateName}%");
        }
        if ($candidateEmail = $filters['candidateEmail'] ?? null) {
            $query->where('candidate_email', 'ilike', "%{$candidateEmail}%");
        }
        if ($position = $filters['position'] ?? null) {
            $query->where('position', 'ilike', "%{$position}%");
        }
        if ($company = $filters['company'] ?? null) {
            $query->where('company', 'ilike', "%{$company}%");
        }
        if (array_key_exists('matchingScore', $filters) && $filters['matchingScore'] !== null) {
            $query->where('matching_score', $filters['matchingScore']);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($matchDate = $filters['matchDate'] ?? null) {
            $query->whereDate('match_date', $matchDate);
        }

        $query->orderByDesc('match_date');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findRecent(int $limit): Collection
    {
        return MatchingResult::orderByDesc('match_date')->take($limit)->get();
    }
}
