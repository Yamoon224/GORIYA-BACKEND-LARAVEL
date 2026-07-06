<?php

namespace App\Services;

use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\JobOffer;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use App\Repositories\Contracts\JobOfferRepositoryInterface;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

/**
 * Mirroir de backend/src/job-offers/job-offers.service.ts. Utilisé par
 * JobOffersController (validé) et AdminJobsController (non validé).
 */
class JobOfferService
{
    use HandlesUniqueViolations, MapsFieldsToColumns;

    private const RELATIONS = ['company', 'candidatures'];

    public function __construct(
        private readonly JobOfferRepositoryInterface $jobOfferRepository,
        private readonly CompanyRepositoryInterface $companyRepository,
    ) {}

    public function create(array $data): JobOffer
    {
        $company = $this->companyRepository->find($data['companyId']);
        if (! $company) {
            abort(404, "Company with id {$data['companyId']} not found");
        }

        $payload = [
            'title' => $data['title'],
            'location' => $data['location'],
            'type' => $data['type'],
            'experience' => $data['experience'],
            'salary' => $data['salary'],
            'description' => $data['description'],
            'benefits' => $data['benefits'],
            'requirements' => $data['requirements'],
            'publish_date' => $data['publishDate'],
            'end_date' => $data['endDate'],
            'company_id' => $company->id,
        ];

        // Colonnes avec défaut DB (status='ACTIVE', applicants=0) : ne les inclure
        // que si fournies, sinon un `null` explicite écraserait le défaut et
        // violerait la contrainte NOT NULL.
        if (array_key_exists('status', $data)) {
            $payload['status'] = $data['status'];
        }
        if (array_key_exists('applicants', $data)) {
            $payload['applicants'] = $data['applicants'];
        }

        try {
            $jobOffer = $this->jobOfferRepository->create($payload);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        return $jobOffer->fresh(self::RELATIONS);
    }

    public function update(JobOffer $jobOffer, array $data): JobOffer
    {
        $mapped = [];

        if (array_key_exists('companyId', $data)) {
            $mapped['company_id'] = $data['companyId'];
        }

        $mapped += $this->mapFields($data, [
            'title' => 'title',
            'location' => 'location',
            'type' => 'type',
            'experience' => 'experience',
            'salary' => 'salary',
            'description' => 'description',
            'benefits' => 'benefits',
            'requirements' => 'requirements',
            'status' => 'status',
            'publishDate' => 'publish_date',
            'endDate' => 'end_date',
            'applicants' => 'applicants',
        ]);

        try {
            $this->jobOfferRepository->update($jobOffer, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        return $jobOffer->fresh(self::RELATIONS);
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->jobOfferRepository->paginate($page, $limit, $filters);
    }

    public function remove(JobOffer $jobOffer): void
    {
        $this->jobOfferRepository->delete($jobOffer);
    }
}
