<?php

namespace App\Services;

use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\MatchingResult;
use App\Repositories\Contracts\MatchingResultRepositoryInterface;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

/**
 * Mirroir de backend/src/matching-results/matching-results.service.ts.
 */
class MatchingResultService
{
    use HandlesUniqueViolations, MapsFieldsToColumns;

    public function __construct(private readonly MatchingResultRepositoryInterface $matchingResultRepository) {}

    public function create(array $data): MatchingResult
    {
        try {
            return $this->matchingResultRepository->create([
                'candidate_name' => $data['candidateName'],
                'candidate_email' => $data['candidateEmail'],
                'position' => $data['position'],
                'company' => $data['company'],
                'matching_score' => $data['matchingScore'],
                'status' => $data['status'],
                'match_date' => $data['matchDate'],
            ]);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }
    }

    public function update(MatchingResult $result, array $data): MatchingResult
    {
        $mapped = $this->mapFields($data, [
            'candidateName' => 'candidate_name',
            'candidateEmail' => 'candidate_email',
            'position' => 'position',
            'company' => 'company',
            'matchingScore' => 'matching_score',
            'status' => 'status',
            'matchDate' => 'match_date',
        ]);

        try {
            $this->matchingResultRepository->update($result, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        return $result;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->matchingResultRepository->paginate($page, $limit, $filters);
    }

    public function remove(MatchingResult $result): void
    {
        $this->matchingResultRepository->delete($result);
    }
}
