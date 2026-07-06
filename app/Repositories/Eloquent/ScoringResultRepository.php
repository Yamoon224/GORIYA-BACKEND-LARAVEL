<?php

namespace App\Repositories\Eloquent;

use App\Models\ScoringResult;
use App\Repositories\Contracts\ScoringResultRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ScoringResultRepository extends BaseRepository implements ScoringResultRepositoryInterface
{
    protected function model(): string
    {
        return ScoringResult::class;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = ScoringResult::query();

        if ($candidateName = $filters['candidateName'] ?? null) {
            $query->where('candidate_name', 'ilike', "%{$candidateName}%");
        }
        if ($candidateEmail = $filters['candidateEmail'] ?? null) {
            $query->where('candidate_email', 'ilike', "%{$candidateEmail}%");
        }
        if ($position = $filters['position'] ?? null) {
            $query->where('position', 'ilike', "%{$position}%");
        }
        if (array_key_exists('overallScore', $filters) && $filters['overallScore'] !== null) {
            $query->where('overall_score', $filters['overallScore']);
        }
        if ($analysisDate = $filters['analysisDate'] ?? null) {
            $query->whereDate('analysis_date', $analysisDate);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        $query->orderByDesc('analysis_date');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findRecent(int $limit): Collection
    {
        return ScoringResult::orderByDesc('analysis_date')->take($limit)->get();
    }

    public function findAllOrderedByAnalysisDate(): Collection
    {
        return ScoringResult::orderBy('analysis_date')->get();
    }
}
