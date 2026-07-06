<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Http\Resources\JobOfferResource;
use App\Http\Resources\UserResource;
use App\Models\JobOffer;
use App\Models\User;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use App\Repositories\Contracts\JobOfferRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Concerns\BuildsCsv;
use App\Services\Concerns\PaginatesArrays;

/**
 * Mirroir du sous-ensemble "recherche globale" de backend/src/admin/
 * admin-platform.service.ts — filtrage en mémoire (comme la source), pas au
 * niveau SQL : les filtres combinent texte + relations d'une façon jamais
 * poussée en WHERE côté NestJS, préservé tel quel plutôt que "optimisé" en
 * requête SQL. Extrait de l'ex-AdminPlatformService.
 */
class AdminSearchService
{
    use BuildsCsv, PaginatesArrays;

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly JobOfferRepositoryInterface $jobOfferRepository,
        private readonly CompanyRepositoryInterface $companyRepository,
    ) {}

    public function searchAll(array $query): array
    {
        $page = $this->toNumber($query['page'] ?? null, 1);
        $limit = $this->toNumber($query['limit'] ?? null, 10);
        $q = trim((string) ($query['q'] ?? ''));

        $combined = [...$this->searchCandidatesInternal($q, $query), ...$this->searchOffersInternal($q, $query)];

        return $this->paginateArray($combined, $page, $limit);
    }

    public function searchCandidates(array $query): array
    {
        $page = $this->toNumber($query['page'] ?? null, 1);
        $limit = $this->toNumber($query['limit'] ?? null, 10);
        $q = trim((string) ($query['q'] ?? ''));

        return $this->paginateArray($this->searchCandidatesInternal($q, $query), $page, $limit);
    }

    public function searchOffers(array $query): array
    {
        $page = $this->toNumber($query['page'] ?? null, 1);
        $limit = $this->toNumber($query['limit'] ?? null, 10);
        $q = trim((string) ($query['q'] ?? ''));

        return $this->paginateArray($this->searchOffersInternal($q, $query), $page, $limit);
    }

    public function getSearchFilters(): array
    {
        $companies = $this->companyRepository->all();
        $offers = $this->jobOfferRepository->all();

        return [
            'sectors' => $companies->pluck('sector')->filter()->unique()->values()->all(),
            'locations' => $companies->pluck('location')->concat($offers->pluck('location'))->filter()->unique()->values()->all(),
            'experiences' => $offers->pluck('experience')->filter()->unique()->map(fn ($e) => $e->value)->values()->all(),
        ];
    }

    public function exportSearchCsv(array $query): string
    {
        $result = $this->searchAll($query);

        return $this->toCsv($result['data']);
    }

    private function searchCandidatesInternal(string $q, array $query): array
    {
        $users = $this->userRepository->findByRole(UserRole::USER->value);
        $location = mb_strtolower(trim((string) ($query['location'] ?? '')));
        $sector = mb_strtolower(trim((string) ($query['sector'] ?? '')));
        $needle = mb_strtolower($q);

        return $users->filter(function (User $user) use ($needle, $location, $sector) {
            $textMatch = $needle === '' || str_contains(mb_strtolower($user->name), $needle) || str_contains(mb_strtolower($user->email), $needle);
            $companyLocation = mb_strtolower($user->company?->location ?? '');
            $companySector = mb_strtolower($user->company?->sector ?? '');

            return $textMatch
                && ($location === '' || str_contains($companyLocation, $location))
                && ($sector === '' || str_contains($companySector, $sector));
        })->values()->map(fn (User $user) => (new UserResource($user))->resolve())->all();
    }

    private function searchOffersInternal(string $q, array $query): array
    {
        $offers = $this->jobOfferRepository->findAllWithCompany();
        $location = mb_strtolower(trim((string) ($query['location'] ?? '')));
        $sector = mb_strtolower(trim((string) ($query['sector'] ?? '')));
        $experience = mb_strtolower(trim((string) ($query['experience'] ?? '')));
        $needle = mb_strtolower($q);

        return $offers->filter(function (JobOffer $offer) use ($needle, $location, $sector, $experience) {
            $textMatch = $needle === '' || str_contains(mb_strtolower($offer->title), $needle) || str_contains(mb_strtolower($offer->description), $needle);

            return $textMatch
                && ($location === '' || str_contains(mb_strtolower($offer->location), $location))
                && ($sector === '' || str_contains(mb_strtolower($offer->company?->sector ?? ''), $sector))
                && ($experience === '' || str_contains(mb_strtolower($offer->experience->value), $experience));
        })->values()->map(fn (JobOffer $offer) => (new JobOfferResource($offer))->resolve())->all();
    }

    private function toNumber(mixed $value, int $fallback): int
    {
        $parsed = is_numeric($value) ? (int) $value : null;

        return ($parsed !== null && $parsed > 0) ? $parsed : $fallback;
    }
}
