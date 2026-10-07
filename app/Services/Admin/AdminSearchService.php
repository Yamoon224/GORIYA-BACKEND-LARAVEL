<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Http\Resources\JobOfferResource;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\JobOffer;
use App\Models\User;
use App\Services\Concerns\BuildsCsv;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recherche globale du back-office (candidats + offres), mirroir de
 * backend/src/admin/admin-platform.service.ts.
 *
 * Filtres et pagination sont poussés en SQL : la version d'origine chargeait
 * tous les candidats et toutes les offres, les filtrait en PHP puis découpait
 * le tableau — plusieurs secondes et des milliers de requêtes dès quelques
 * milliers d'offres. Les critères sont les mêmes (« contient », insensible à
 * la casse) et la réponse garde la forme {data, meta}, candidats d'abord puis
 * offres.
 */
class AdminSearchService
{
    use BuildsCsv;

    public function searchAll(array $query): array
    {
        [$page, $limit] = $this->paging($query);
        $candidates = $this->candidatesQuery($query);
        $offers = $this->offersQuery($query);

        $candidateTotal = (clone $candidates)->count();
        $offerTotal = (clone $offers)->count();
        $offset = ($page - 1) * $limit;

        // Une seule liste « candidats puis offres » : la page peut chevaucher les deux.
        $data = $offset < $candidateTotal ? $this->candidateRows($candidates, $offset, $limit) : [];
        if (($remaining = $limit - count($data)) > 0) {
            $data = [...$data, ...$this->offerRows($offers, max(0, $offset - $candidateTotal), $remaining)];
        }

        return ['data' => $data, 'meta' => $this->meta($candidateTotal + $offerTotal, $page, $limit)];
    }

    public function searchCandidates(array $query): array
    {
        [$page, $limit] = $this->paging($query);
        $candidates = $this->candidatesQuery($query);

        return [
            'data' => $this->candidateRows($candidates, ($page - 1) * $limit, $limit),
            'meta' => $this->meta((clone $candidates)->count(), $page, $limit),
        ];
    }

    public function searchOffers(array $query): array
    {
        [$page, $limit] = $this->paging($query);
        $offers = $this->offersQuery($query);

        return [
            'data' => $this->offerRows($offers, ($page - 1) * $limit, $limit),
            'meta' => $this->meta((clone $offers)->count(), $page, $limit),
        ];
    }

    public function getSearchFilters(): array
    {
        $distinct = fn (Builder $query, string $column) => $query->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->toBase()->pluck($column);

        return [
            'sectors' => $distinct(Company::query(), 'sector')->all(),
            'locations' => $distinct(Company::query(), 'location')
                ->concat($distinct(JobOffer::query(), 'location'))->unique()->values()->all(),
            'experiences' => $distinct(JobOffer::query(), 'experience')->all(),
        ];
    }

    public function exportSearchCsv(array $query): string
    {
        $result = $this->searchAll($query);

        return $this->toCsv($result['data']);
    }

    private function candidatesQuery(array $query): Builder
    {
        $needle = $this->term($query, 'q');
        $location = $this->term($query, 'location');
        $sector = $this->term($query, 'sector');

        return User::query()
            ->where('role', UserRole::USER->value)
            ->when($needle !== '', fn (Builder $q) => $q->where(fn (Builder $text) => $this->contains($this->contains($text, 'name', $needle), 'email', $needle, 'or')))
            ->when($location !== '', fn (Builder $q) => $q->whereHas('company', fn (Builder $company) => $this->contains($company, 'location', $location)))
            ->when($sector !== '', fn (Builder $q) => $q->whereHas('company', fn (Builder $company) => $this->contains($company, 'sector', $sector)));
    }

    private function offersQuery(array $query): Builder
    {
        $needle = $this->term($query, 'q');
        $location = $this->term($query, 'location');
        $sector = $this->term($query, 'sector');
        $experience = $this->term($query, 'experience');

        return JobOffer::query()
            ->when($needle !== '', fn (Builder $q) => $q->where(fn (Builder $text) => $this->contains($this->contains($text, 'title', $needle), 'description', $needle, 'or')))
            ->when($location !== '', fn (Builder $q) => $this->contains($q, 'location', $location))
            ->when($sector !== '', fn (Builder $q) => $q->whereHas('company', fn (Builder $company) => $this->contains($company, 'sector', $sector)))
            ->when($experience !== '', fn (Builder $q) => $this->contains($q, 'experience', $experience));
    }

    /** @return list<array<string, mixed>> */
    private function candidateRows(Builder $query, int $offset, int $limit): array
    {
        return $query->with('company')->orderBy('name')->orderBy('id')->skip($offset)->take($limit)->get()
            ->map(fn (User $user) => (new UserResource($user))->resolve())->all();
    }

    /** @return list<array<string, mixed>> */
    private function offerRows(Builder $query, int $offset, int $limit): array
    {
        return $query->with(['company', 'questions'])->orderByDesc('created_at')->orderBy('id')->skip($offset)->take($limit)->get()
            ->map(fn (JobOffer $offer) => (new JobOfferResource($offer))->resolve())->all();
    }

    /** « Contient », insensible à la casse ; `%` et `_` saisis sont cherchés tels quels. */
    private function contains(Builder $query, string $column, string $needle, string $boolean = 'and'): Builder
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';

        return $query->whereRaw('LOWER('.$query->qualifyColumn($column).") LIKE ? ESCAPE '!'", [$pattern], $boolean);
    }

    private function term(array $query, string $key): string
    {
        return mb_strtolower(trim((string) ($query[$key] ?? '')));
    }

    /** @return array{0: int, 1: int} */
    private function paging(array $query): array
    {
        return [$this->toNumber($query['page'] ?? null, 1), $this->toNumber($query['limit'] ?? null, 10)];
    }

    /** @return array{total: int, page: int, limit: int, totalPages: int} */
    private function meta(int $total, int $page, int $limit): array
    {
        return ['total' => $total, 'page' => $page, 'limit' => $limit, 'totalPages' => (int) (ceil($total / $limit) ?: 1)];
    }

    private function toNumber(mixed $value, int $fallback): int
    {
        $parsed = is_numeric($value) ? (int) $value : null;

        return ($parsed !== null && $parsed > 0) ? $parsed : $fallback;
    }
}
