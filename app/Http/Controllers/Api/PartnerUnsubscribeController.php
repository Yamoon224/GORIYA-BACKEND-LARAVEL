<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PotentialPartner;
use App\Services\PotentialPartnerService;
use OpenApi\Attributes as OA;

/**
 * Lien de désabonnement inclus dans chaque email de campagne (voir
 * PartnerCampaignMail / emails.partner-campaign). Route publique protégée
 * uniquement par la signature de l'URL (middleware 'signed') — pas d'auth
 * admin, le destinataire n'a pas de compte Goriya.
 */
#[OA\Tag(name: 'Partner Unsubscribe', description: "Désabonnement d'un partenaire potentiel aux campagnes de mailing")]
class PartnerUnsubscribeController extends Controller
{
    public function __construct(private readonly PotentialPartnerService $partnerService) {}

    #[OA\Get(
        path: '/partners/{partner}/unsubscribe',
        tags: ['Partner Unsubscribe'],
        summary: "Désabonne un partenaire potentiel (lien signé, pas d'auth)",
        responses: [
            new OA\Response(response: 200, description: 'Désabonnement confirmé'),
            new OA\Response(response: 403, description: 'Lien invalide ou expiré'),
            new OA\Response(response: 404, description: 'Partenaire introuvable'),
        ]
    )]
    public function unsubscribe(string $partner)
    {
        $model = PotentialPartner::findOrFail($partner);
        $this->partnerService->unsubscribe($model);

        return response()->view('emails.unsubscribed', ['companyName' => $model->company_name]);
    }
}
