<?php

namespace App\Repositories\Eloquent;

use App\Models\JobOffer;
use App\Repositories\Contracts\JobOfferRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class JobOfferRepository extends BaseRepository implements JobOfferRepositoryInterface
{
    private const RELATIONS = ['company', 'candidatures'];

    protected function model(): string
    {
        return JobOffer::class;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = JobOffer::query()->with(self::RELATIONS);

        if ($title = $filters['title'] ?? null) {
            $query->where('title', 'ilike', "%{$title}%");
        }
        if ($location = $filters['location'] ?? null) {
            $query->where('location', 'ilike', "%{$location}%");
        }
        if ($type = $filters['type'] ?? null) {
            $query->where('type', $type);
        }
        if ($salary = $filters['salary'] ?? null) {
            $query->where('salary', 'ilike', "%{$salary}%");
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($companyId = $filters['companyId'] ?? null) {
            $query->where('company_id', $companyId);
        }
        if (array_key_exists('applicants', $filters) && $filters['applicants'] !== null) {
            $query->where('applicants', $filters['applicants']);
        }

        $query->orderByDesc('id');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findByCompany(string $companyId): Collection
    {
        return JobOffer::where('company_id', $companyId)->with('company')->get();
    }

    public function findAllWithCompany(): Collection
    {
        return JobOffer::with('company')->get();
    }
}
