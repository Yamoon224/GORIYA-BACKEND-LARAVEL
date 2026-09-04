<?php

namespace App\Repositories\Eloquent;

use App\Enums\JobStatus;
use App\Models\JobOffer;
use App\Repositories\Contracts\JobOfferRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class JobOfferRepository extends BaseRepository implements JobOfferRepositoryInterface
{
    private const RELATIONS = ['company', 'candidatures', 'questions'];

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
            $query->whereILike('title', $title);
        }
        if ($search = $filters['search'] ?? null) {
            $query->where(function ($q) use ($search) {
                $q->whereILike('title', $search)
                    ->orWhereILike('description', $search);
            });
        }
        if ($location = $filters['location'] ?? null) {
            $query->whereILike('location', $location);
        }
        if ($type = $filters['type'] ?? null) {
            $this->whereInOrEqual($query, 'type', $type);
        }
        if ($jobType = $filters['jobType'] ?? null) {
            $this->whereInOrEqual($query, 'type', $jobType);
        }
        if ($experience = $filters['experience'] ?? null) {
            $this->whereInOrEqual($query, 'experience', $experience);
        }
        if ($salary = $filters['salary'] ?? null) {
            $query->whereILike('salary', $salary);
        }
        if (filter_var($filters['hasSalary'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNotNull('salary')->where('salary', '!=', '');
        }
        if (filter_var($filters['remote'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('remote', true);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($companyId = $filters['companyId'] ?? null) {
            $query->where('company_id', $companyId);
        }
        if ($companySize = $filters['companySize'] ?? null) {
            $sizes = is_array($companySize) ? $companySize : [$companySize];
            $query->whereHas('company', function ($q) use ($sizes) {
                $q->where(function ($q2) use ($sizes) {
                    foreach ($sizes as $size) {
                        $q2->orWhereILike('company_size', $size);
                    }
                });
            });
        }
        if ($sector = $filters['sector'] ?? null) {
            $query->whereHas('company', fn ($q) => $q->where('sector', $sector));
        }
        if (array_key_exists('applicants', $filters) && $filters['applicants'] !== null) {
            $query->where('applicants', $filters['applicants']);
        }

        $this->hideForeignDrafts($query, $filters);

        // Forfait de l'entreprise décroissant, puis date d'ajout décroissante
        // — voir JobOffer::scopeOrderByPlanThenRecency.
        $query->orderByPlanThenRecency();

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Masque les brouillons qui ne sont pas ceux de l'appelant.
     *
     * `/job-offers` et `/job-offers/paginate` sont des routes publiques : sans
     * ce filtre, une offre enregistrée en brouillon — donc non publiée — sortirait
     * dans la recherche d'emploi des candidats. L'entreprise propriétaire
     * (`viewerCompanyId`) et l'administration (`viewerIsAdmin`) continuent de
     * voir les leurs, c'est ce qui alimente la page « Mes annonces ».
     *
     * @param  array<string, mixed>  $filters
     */
    private function hideForeignDrafts(Builder $query, array $filters): void
    {
        if (filter_var($filters['viewerIsAdmin'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $viewerCompanyId = $filters['viewerCompanyId'] ?? null;

        $query->where(function (Builder $q) use ($viewerCompanyId) {
            $q->where('status', '!=', JobStatus::DRAFT->value);
            if ($viewerCompanyId) {
                $q->orWhere('company_id', $viewerCompanyId);
            }
        });
    }

    /**
     * @param  string|array<int, string>  $value
     */
    private function whereInOrEqual(Builder $query, string $column, string|array $value): void
    {
        if (is_array($value)) {
            $query->whereIn($column, $value);

            return;
        }

        $query->where($column, $value);
    }

    public function findByCompany(string $companyId): Collection
    {
        return JobOffer::where('company_id', $companyId)->with('company')->get();
    }

    public function findAllWithCompany(): Collection
    {
        return JobOffer::with('company')->get();
    }

    /**
     * Secteurs distincts des entreprises ayant au moins une offre active —
     * sert de taxonomie "catégories d'emploi" côté public (il n'existe pas
     * de table Category dédiée, le secteur de la Company en tient lieu,
     * cohérent avec /admin/companies/sectors et /admin/job-offers/sectors).
     *
     * @return list<string>
     */
    public function categories(): array
    {
        return JobOffer::query()
            ->join('companies', 'companies.id', '=', 'job_offers.company_id')
            ->whereNotNull('companies.sector')
            ->where('companies.sector', '!=', '')
            ->distinct()
            ->orderBy('companies.sector')
            ->pluck('companies.sector')
            ->values()
            ->all();
    }
}
