<?php

namespace App\Services;

use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\ScoringResult;
use App\Repositories\Contracts\ScoringResultRepositoryInterface;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

/**
 * Mirroir de backend/src/scoring/scoring-results.service.ts.
 */
class ScoringResultService
{
    use HandlesUniqueViolations, MapsFieldsToColumns;

    public function __construct(private readonly ScoringResultRepositoryInterface $scoringResultRepository) {}

    public function create(array $data): ScoringResult
    {
        try {
            return $this->scoringResultRepository->create([
                'candidate_name' => $data['candidateName'],
                'candidate_email' => $data['candidateEmail'],
                'position' => $data['position'],
                'overall_score' => $data['overallScore'],
                'criteria' => $data['criteria'],
                'analysis_date' => $data['analysisDate'],
                'status' => $data['status'],
            ]);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }
    }

    public function update(ScoringResult $result, array $data): ScoringResult
    {
        $mapped = $this->mapFields($data, [
            'candidateName' => 'candidate_name',
            'candidateEmail' => 'candidate_email',
            'position' => 'position',
            'overallScore' => 'overall_score',
            'criteria' => 'criteria',
            'analysisDate' => 'analysis_date',
            'status' => 'status',
        ]);

        try {
            $this->scoringResultRepository->update($result, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        return $result;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->scoringResultRepository->paginate($page, $limit, $filters);
    }

    public function remove(ScoringResult $result): void
    {
        $this->scoringResultRepository->delete($result);
    }
}
