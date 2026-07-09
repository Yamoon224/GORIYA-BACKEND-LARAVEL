<?php

namespace App\Repositories\Eloquent;

use App\Enums\InterviewStatus;
use App\Models\InterviewSession;
use App\Repositories\Contracts\InterviewSessionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class InterviewSessionRepository extends BaseRepository implements InterviewSessionRepositoryInterface
{
    protected function model(): string
    {
        return InterviewSession::class;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = InterviewSession::query();

        if ($candidateName = $filters['candidateName'] ?? null) {
            $query->whereILike('candidate_name', $candidateName);
        }
        if ($candidateEmail = $filters['candidateEmail'] ?? null) {
            $query->whereILike('candidate_email', $candidateEmail);
        }
        if ($position = $filters['position'] ?? null) {
            $query->whereILike('position', $position);
        }
        if (array_key_exists('duration', $filters) && $filters['duration'] !== null) {
            $query->where('duration', $filters['duration']);
        }
        if (array_key_exists('score', $filters) && $filters['score'] !== null) {
            $query->where('score', $filters['score']);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($startTime = $filters['startTime'] ?? null) {
            $query->whereDate('start_time', $startTime);
        }

        $query->orderByDesc('start_time');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findByStatus(array $statuses): Collection
    {
        return InterviewSession::whereIn('status', $statuses)->get();
    }

    public function findCompletedOrderedByStartTime(): Collection
    {
        return InterviewSession::where('status', InterviewStatus::COMPLETED)->orderByDesc('start_time')->get();
    }
}
