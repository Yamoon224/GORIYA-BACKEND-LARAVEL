<?php

namespace App\Repositories\Eloquent;

use App\Http\Concerns\BuildsPgArrayLiterals;
use App\Models\CvAnalysis;
use App\Repositories\Contracts\CvAnalysisRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class CvAnalysisRepository extends BaseRepository implements CvAnalysisRepositoryInterface
{
    use BuildsPgArrayLiterals;

    protected function model(): string
    {
        return CvAnalysis::class;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = CvAnalysis::query();

        if (array_key_exists('analysisScore', $filters) && $filters['analysisScore'] !== null) {
            $query->where('analysis_score', $filters['analysisScore']);
        }

        $recommendations = $filters['recommendations'] ?? null;
        if ($recommendations) {
            $recommendations = is_array($recommendations) ? $recommendations : [$recommendations];
            $query->whereRaw('recommendations && ?::text[]', [$this->toPgArrayLiteral($recommendations)]);
        }

        if ($uploadDate = $filters['uploadDate'] ?? null) {
            $query->whereDate('upload_date', $uploadDate);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        $query->orderByDesc('upload_date');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findRecent(int $limit): Collection
    {
        return CvAnalysis::orderByDesc('upload_date')->take($limit)->get();
    }
}
