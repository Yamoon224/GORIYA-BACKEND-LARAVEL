<?php

namespace App\Services;

use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\Candidature;
use App\Repositories\Contracts\CandidatureRepositoryInterface;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

/**
 * Mirroir de backend/src/candidatures/candidatures.service.ts.
 */
class CandidatureService
{
    use HandlesUniqueViolations, MapsFieldsToColumns;

    private const RELATIONS = ['user', 'jobOffer', 'jobOffer.company'];

    public function __construct(
        private readonly CandidatureRepositoryInterface $candidatureRepository,
        private readonly NotificationService $notificationService,
        private readonly WebhookService $webhookService,
    ) {}

    /*
    |----------------------------------------------------------------------
    | CREATE — pas de vérification d'existence de userId/jobOfferId côté
    | NestJS : on laisse la contrainte FK de la DB faire foi (parité).
    |----------------------------------------------------------------------
    */
    public function create(array $data): Candidature
    {
        $payload = [
            'candidate_name' => $data['candidateName'],
            'candidate_email' => $data['candidateEmail'],
            'applied_date' => $data['appliedDate'],
            'user_id' => $data['userId'],
            'job_offer_id' => $data['jobOfferId'],
        ];

        foreach (['status', 'score'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        try {
            $candidature = $this->candidatureRepository->create($payload);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        $fresh = $candidature->fresh(self::RELATIONS);
        $this->notificationService->notifyNewApplication($fresh);

        return $fresh;
    }

    public function update(Candidature $candidature, array $data): Candidature
    {
        $mapped = $this->mapFields($data, [
            'candidateName' => 'candidate_name',
            'candidateEmail' => 'candidate_email',
            'status' => 'status',
            'score' => 'score',
            'appliedDate' => 'applied_date',
            'userId' => 'user_id',
            'jobOfferId' => 'job_offer_id',
        ]);

        $oldStatus = $candidature->status?->value ?? $candidature->status;

        try {
            $this->candidatureRepository->update($candidature, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        $fresh = $candidature->fresh(self::RELATIONS);

        if (array_key_exists('status', $mapped) && $mapped['status'] !== $oldStatus) {
            $this->notificationService->notifyApplicationStatusChanged($fresh);

            if ($companyId = $fresh->jobOffer?->company_id) {
                $this->webhookService->dispatch($companyId, 'candidature.status_updated', [
                    'candidatureId' => $fresh->id,
                    'status' => $mapped['status'],
                    'candidateName' => $fresh->candidate_name,
                    'candidateEmail' => $fresh->candidate_email,
                    'jobOfferId' => $fresh->job_offer_id,
                ]);
            }
        }

        return $fresh;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->candidatureRepository->paginate($page, $limit, $filters);
    }

    public function remove(Candidature $candidature): void
    {
        $this->candidatureRepository->delete($candidature);
    }
}
