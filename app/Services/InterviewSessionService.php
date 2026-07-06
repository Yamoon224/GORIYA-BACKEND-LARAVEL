<?php

namespace App\Services;

use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\InterviewSession;
use App\Repositories\Contracts\InterviewSessionRepositoryInterface;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

/**
 * Mirroir de backend/src/interview-sessions/interview-sessions.service.ts.
 */
class InterviewSessionService
{
    use HandlesUniqueViolations, MapsFieldsToColumns;

    public function __construct(private readonly InterviewSessionRepositoryInterface $interviewSessionRepository) {}

    public function create(array $data): InterviewSession
    {
        $payload = [
            'candidate_name' => $data['candidateName'],
            'candidate_email' => $data['candidateEmail'],
            'position' => $data['position'],
            'duration' => $data['duration'],
            'status' => $data['status'],
            'start_time' => $data['startTime'],
            'feedback' => $data['feedback'] ?? null,
        ];

        if (array_key_exists('score', $data)) {
            $payload['score'] = $data['score'];
        }

        try {
            return $this->interviewSessionRepository->create($payload);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }
    }

    public function update(InterviewSession $session, array $data): InterviewSession
    {
        $mapped = $this->mapFields($data, [
            'candidateName' => 'candidate_name',
            'candidateEmail' => 'candidate_email',
            'position' => 'position',
            'duration' => 'duration',
            'score' => 'score',
            'status' => 'status',
            'startTime' => 'start_time',
            'feedback' => 'feedback',
        ]);

        try {
            $this->interviewSessionRepository->update($session, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        return $session;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->interviewSessionRepository->paginate($page, $limit, $filters);
    }

    public function remove(InterviewSession $session): void
    {
        $this->interviewSessionRepository->delete($session);
    }
}
