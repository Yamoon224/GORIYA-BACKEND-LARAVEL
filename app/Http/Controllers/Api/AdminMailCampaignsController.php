<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendMailCampaignRequest;
use App\Http\Requests\StoreMailCampaignRequest;
use App\Http\Requests\TestSendMailCampaignRequest;
use App\Http\Requests\UpdateMailCampaignRequest;
use App\Http\Resources\MailCampaignRecipientResource;
use App\Http\Resources\MailCampaignResource;
use App\Models\MailCampaign;
use App\Services\MailCampaignService;
use App\Services\PotentialPartnerService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use RuntimeException;

/**
 * Campagnes de mailing du module Potentiels Partenaires (rôle ADMIN requis).
 * Le contenu (subject/bodyHtml) est rédigé/édité librement par l'admin ; voir
 * PartnerCampaignMail pour les placeholders disponibles ({{entreprise}},
 * {{contact}}, {{secteur}}, {{ville}}).
 */
#[OA\Tag(name: 'Admin Mail Campaigns', description: 'Campagnes de mailing vers les partenaires potentiels')]
class AdminMailCampaignsController extends Controller
{
    public function __construct(
        private readonly MailCampaignService $campaignService,
        private readonly PotentialPartnerService $partnerService,
    ) {}

    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->campaignService->paginate($page, $limit);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (MailCampaign $c) => (new MailCampaignResource($c))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    public function show(string $id)
    {
        $campaign = MailCampaign::findOrFail($id);

        return ApiResponse::success((new MailCampaignResource($campaign))->resolve());
    }

    public function store(StoreMailCampaignRequest $request)
    {
        $campaign = $this->campaignService->create($request->validated(), $request->user()->id);

        return ApiResponse::success((new MailCampaignResource($campaign))->resolve(), status: 201);
    }

    public function update(UpdateMailCampaignRequest $request, string $id)
    {
        $campaign = MailCampaign::findOrFail($id);

        try {
            $campaign = $this->campaignService->update($campaign, $request->validated());
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return ApiResponse::success((new MailCampaignResource($campaign))->resolve());
    }

    public function destroy(string $id)
    {
        $campaign = MailCampaign::findOrFail($id);

        try {
            $this->campaignService->delete($campaign);
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return ApiResponse::success(null);
    }

    /**
     * Compte les destinataires joignables pour un jeu de filtres, sans créer
     * de campagne — alimente l'aperçu "X destinataires" avant envoi.
     */
    public function previewRecipients(Request $request)
    {
        $count = $this->partnerService->reachable([
            'search' => $request->query('search'),
            'sector' => $request->query('sector'),
            'city' => $request->query('city'),
            'companySize' => $request->query('companySize'),
            'status' => $request->query('status'),
        ])->count();

        return ApiResponse::success(['count' => $count]);
    }

    public function testSend(TestSendMailCampaignRequest $request, string $id)
    {
        $campaign = MailCampaign::findOrFail($id);
        $this->campaignService->testSend($campaign, $request->validated()['email']);

        return ApiResponse::success(null, "Email de test envoyé à {$request->validated()['email']}.");
    }

    public function send(SendMailCampaignRequest $request, string $id)
    {
        $campaign = MailCampaign::findOrFail($id);

        try {
            $campaign = $this->campaignService->send($campaign, $request->validated());
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return ApiResponse::success((new MailCampaignResource($campaign))->resolve());
    }

    public function recipients(Request $request, string $id)
    {
        $campaign = MailCampaign::findOrFail($id);
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->campaignService->recipients($campaign, $page, $limit);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($r) => (new MailCampaignRecipientResource($r))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }
}
