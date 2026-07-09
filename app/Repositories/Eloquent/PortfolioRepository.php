<?php

namespace App\Repositories\Eloquent;

use App\Http\Concerns\BuildsPgArrayLiterals;
use App\Models\Portfolio;
use App\Repositories\Contracts\PortfolioRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class PortfolioRepository extends BaseRepository implements PortfolioRepositoryInterface
{
    use BuildsPgArrayLiterals;

    protected function model(): string
    {
        return Portfolio::class;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = Portfolio::query()->with('user');

        if ($title = $filters['title'] ?? null) {
            $query->whereILike('title', $title);
        }
        if ($description = $filters['description'] ?? null) {
            $query->whereILike('description', $description);
        }

        $skills = $filters['skills'] ?? null;
        if ($skills) {
            $skills = is_array($skills) ? $skills : [$skills];
            $query->whereRaw('skills && ?::text[]', [$this->toPgArrayLiteral($skills)]);
        }

        if (array_key_exists('views', $filters) && $filters['views'] !== null) {
            $query->where('views', $filters['views']);
        }
        if (array_key_exists('downloads', $filters) && $filters['downloads'] !== null) {
            $query->where('downloads', $filters['downloads']);
        }
        if (array_key_exists('likes', $filters) && $filters['likes'] !== null) {
            $query->where('likes', $filters['likes']);
        }
        if ($createdDate = $filters['createdDate'] ?? null) {
            $query->whereDate('created_date', $createdDate);
        }
        if ($userId = $filters['userId'] ?? null) {
            $query->where('user_id', $userId);
        }

        $query->orderByDesc('created_date');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findFeatured(int $limit): Collection
    {
        return Portfolio::with('user')->orderByDesc('likes')->take($limit)->get();
    }
}
