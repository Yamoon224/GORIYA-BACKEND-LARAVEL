<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface JobOfferRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator;

    /**
     * @return Collection<int, \App\Models\JobOffer>
     */
    public function findByCompany(string $companyId): Collection;

    /**
     * @return Collection<int, \App\Models\JobOffer>
     */
    public function findAllWithCompany(): Collection;
}
