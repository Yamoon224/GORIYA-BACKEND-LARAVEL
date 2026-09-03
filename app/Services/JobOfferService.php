<?php

namespace App\Services;

use App\Contracts\AiAnalysisServiceInterface;
use App\Enums\JobStatus;
use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\JobOffer;
use App\Models\User;
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
        private readonly AiAnalysisServiceInterface $aiAnalysisService,
    ) {}

    public function create(array $data): JobOffer
    {
        $company = $this->companyRepository->find($data['companyId']);
        if (! $company) {
            abort(404, "Company with id {$data['companyId']} not found");
        }

        // Tout est facultatif sauf le titre : un brouillon (status = DRAFT)
        // s'enregistre incomplet, CreateJobOfferRequest ne l'exige qu'à la
        // publication.
        $payload = [
            'title' => $data['title'],
            'location' => $data['location'] ?? null,
            'type' => $data['type'] ?? null,
            'experience' => $data['experience'] ?? null,
            'salary' => $data['salary'] ?? null,
            'description' => $data['description'] ?? null,
            'benefits' => $data['benefits'] ?? null,
            'requirements' => $data['requirements'] ?? null,
            'publish_date' => $data['publishDate'] ?? null,
            'end_date' => $data['endDate'] ?? null,
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

        // Sans `status`, la colonne prend son défaut ACTIVE : l'offre est donc
        // publiée d'emblée et doit être complète.
        if (($payload['status'] ?? JobStatus::ACTIVE->value) !== JobStatus::DRAFT->value) {
            $this->assertPublishable($payload);
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

        // Publier un brouillon se fait par un simple PATCH {status: ACTIVE} :
        // la complétude se vérifie donc sur l'offre telle qu'elle sera APRÈS
        // la mise à jour, pas sur le seul corps de la requête.
        $avant = $jobOffer->getAttributes();
        $apres = array_merge($avant, $mapped);
        // Un `status` absent ou null laisse l'offre dans son statut actuel : ce
        // n'est pas une publication, on ne réclame donc rien de plus.
        $statutApres = $mapped['status'] ?? $avant['status'] ?? JobStatus::ACTIVE->value;
        $publie = $statutApres !== JobStatus::DRAFT->value;
        $sortDeBrouillon = $publie && ($avant['status'] ?? null) === JobStatus::DRAFT->value;
        // Une offre déjà publiée avant les brouillons peut avoir des colonnes
        // vides : on ne bloque son édition que si la requête touche justement
        // l'un des champs exigés à la publication — sinon corriger un titre
        // deviendrait impossible sur ces anciennes offres.
        $toucheChampPublie = array_intersect_key($mapped, $this->champsDePublication()) !== [];
        if ($sortDeBrouillon || ($publie && $toucheChampPublie)) {
            $this->assertPublishable($apres);
        }

        try {
            $this->jobOfferRepository->update($jobOffer, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        return $jobOffer->fresh(self::RELATIONS);
    }

    /**
     * Refuse la publication d'une offre incomplète.
     *
     * Les colonnes sont nullables depuis l'ajout des brouillons : c'est ici, et
     * plus dans le schéma, que se joue l'exigence « une offre publiée est
     * complète ». Renvoie un 422 nommant les champs manquants, que le
     * formulaire affiche tel quel.
     *
     * @param  array<string, mixed>  $attributs  Attributs en colonnes DB.
     */
    private function assertPublishable(array $attributs): void
    {
        $manquants = [];
        foreach ($this->champsDePublication() as $colonne => $libelle) {
            $valeur = $attributs[$colonne] ?? null;
            // `requirements` arrive tantôt en tableau (payload), tantôt en JSON
            // encodé (attributs bruts du modèle) : les deux formes doivent être
            // reconnues comme vides.
            $vide = $valeur === null
                || (is_string($valeur) && in_array(trim($valeur), ['', '[]'], true))
                || (is_array($valeur) && $valeur === []);
            if ($vide) {
                $manquants[] = $libelle;
            }
        }

        if ($manquants !== []) {
            abort(422, 'Impossible de publier : renseignez '.implode(', ', $manquants).'.');
        }
    }

    /**
     * Colonnes exigées d'une offre publiée, et leur libellé côté formulaire.
     *
     * @return array<string, string>
     */
    private function champsDePublication(): array
    {
        return [
            'title' => 'Titre du poste',
            'location' => 'Localisation',
            'type' => 'Type de contrat',
            'experience' => 'Expérience requise',
            'salary' => 'Salaire',
            'description' => 'Description du poste',
            'benefits' => 'Avantages offerts',
            'requirements' => 'Compétences et exigences',
            'publish_date' => 'Date de publication',
            'end_date' => 'Date limite de candidature',
        ];
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->jobOfferRepository->paginate($page, $limit, $filters);
    }

    public function remove(JobOffer $jobOffer): void
    {
        $this->jobOfferRepository->delete($jobOffer);
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return $this->jobOfferRepository->categories();
    }

    /**
     * Score de compatibilité candidat/offre à la demande (widget public de la
     * fiche offre) — même service IA que le matching déclenché côté admin,
     * pas de persistance ici (juste un calcul affiché à l'utilisateur).
     *
     * @return array{matchingScore: int, matchReasons: list<string>}
     */
    public function matchForUser(JobOffer $jobOffer, User $user): array
    {
        $jobOffer->loadMissing('company');

        return $this->aiAnalysisService->matchCandidateToJob(
            ['name' => $user->name, 'email' => $user->email],
            [
                'title' => $jobOffer->title,
                'company' => $jobOffer->company?->name ?? 'Entreprise',
                'description' => $jobOffer->description,
            ],
        );
    }
}
