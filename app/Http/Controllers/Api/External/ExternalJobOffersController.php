<?php

namespace App\Http\Controllers\Api\External;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobOfferResource;
use App\Models\ApiClient;
use App\Models\JobOffer;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'External API', description: 'API B2B pour intégrations ATS/SIRH (authentification par clé)')]
class ExternalJobOffersController extends Controller
{
    public function __construct(private readonly ApiClient $apiClient) {}

    #[OA\Get(
        path: '/external/v1/job-offers',
        tags: ['External API'],
        summary: "Liste des offres d'emploi de l'entreprise",
        security: [['apiKeyAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des offres',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/JobOffer'))
            ),
            new OA\Response(response: 401, description: 'Clé API invalide'),
        ]
    )]
    public function index()
    {
        $offers = JobOffer::where('company_id', $this->apiClient->company_id)
            ->with('company')
            ->orderByDesc('publish_date')
            ->get();

        return JobOfferResource::collection($offers);
    }
}
