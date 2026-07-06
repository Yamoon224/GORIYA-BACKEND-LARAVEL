<?php

namespace App\Repositories\Eloquent;

use App\Models\Company;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CompanyRepository extends BaseRepository implements CompanyRepositoryInterface
{
    protected function model(): string
    {
        return Company::class;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = Company::query();

        if ($name = $filters['name'] ?? null) {
            $query->where('name', 'ilike', "%{$name}%");
        }
        if ($sector = $filters['sector'] ?? null) {
            $query->where('sector', 'ilike', "%{$sector}%");
        }
        if ($country = $filters['country'] ?? null) {
            $query->where('country', 'ilike', "%{$country}%");
        }
        if ($city = $filters['city'] ?? null) {
            $query->where('location', 'ilike', "%{$city}%");
        }
        if ($companySize = $filters['companySize'] ?? null) {
            $query->where('company_size', $companySize);
        }
        if ($email = $filters['email'] ?? null) {
            $query->where('email', 'ilike', "%{$email}%");
        }
        if ($phone = $filters['phone'] ?? null) {
            $query->where('phone', 'ilike', "%{$phone}%");
        }
        if ($website = $filters['website'] ?? null) {
            $query->where('website', 'ilike', "%{$website}%");
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        $startDate = $filters['startDate'] ?? null;
        $endDate = $filters['endDate'] ?? null;
        if ($startDate && $endDate) {
            $query->whereBetween('partnership_date', [$startDate, $endDate]);
        } elseif ($startDate) {
            $query->where('partnership_date', '>=', $startDate);
        } elseif ($endDate) {
            $query->where('partnership_date', '<=', $endDate);
        }

        $query->orderByDesc('created_at');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function countByStatus(string $status): int
    {
        return Company::where('status', $status)->count();
    }

    public function countCreatedBetween(Carbon $start, Carbon $end): int
    {
        return Company::whereBetween('created_at', [$start, $end])->count();
    }
}
