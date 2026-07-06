<?php

namespace App\Services;

use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\Portfolio;
use App\Repositories\Contracts\PortfolioRepositoryInterface;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

/**
 * Mirroir de backend/src/portfolios/portfolios.service.ts.
 */
class PortfolioService
{
    use HandlesUniqueViolations, MapsFieldsToColumns;

    public function __construct(private readonly PortfolioRepositoryInterface $portfolioRepository) {}

    /*
    |----------------------------------------------------------------------
    | CREATE — pas de vérification d'existence de userId côté NestJS : on
    | laisse la contrainte FK de la DB faire foi (parité volontaire).
    |----------------------------------------------------------------------
    */
    public function create(array $data): Portfolio
    {
        $payload = [
            'title' => $data['title'],
            'description' => $data['description'],
            'skills' => $data['skills'],
            'created_date' => $data['createdDate'],
            'user_id' => $data['userId'],
        ];

        foreach (['views', 'downloads', 'likes'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        try {
            $portfolio = $this->portfolioRepository->create($payload);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        return $portfolio->fresh('user');
    }

    public function update(Portfolio $portfolio, array $data): Portfolio
    {
        $mapped = [];

        if (array_key_exists('userId', $data)) {
            $mapped['user_id'] = $data['userId'];
        }

        $mapped += $this->mapFields($data, [
            'title' => 'title',
            'description' => 'description',
            'skills' => 'skills',
            'views' => 'views',
            'downloads' => 'downloads',
            'likes' => 'likes',
            'createdDate' => 'created_date',
        ]);

        try {
            $this->portfolioRepository->update($portfolio, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        return $portfolio->fresh('user');
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->portfolioRepository->paginate($page, $limit, $filters);
    }

    public function remove(Portfolio $portfolio): void
    {
        $this->portfolioRepository->delete($portfolio);
    }
}
