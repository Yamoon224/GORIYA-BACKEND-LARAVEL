<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateCheckoutRequest',
    required: ['userId', 'planId'],
    properties: [
        new OA\Property(property: 'userId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'planId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'gateway', type: 'string', enum: ['kkiapay', 'wave', 'stripe', 'paiementpro'], description: 'Défaut : services.payment.default_gateway'),
        new OA\Property(property: 'currency', type: 'string', example: 'XOF'),
        new OA\Property(property: 'successUrl', type: 'string', description: 'Requis pour wave/stripe/paiementpro (session hébergée)'),
        new OA\Property(property: 'errorUrl', type: 'string', description: 'Requis pour wave/stripe/paiementpro (session hébergée)'),
        new OA\Property(property: 'customerPhone', type: 'string', description: 'Téléphone du payeur — utilisé par paiementpro (Mobile Money)'),
        new OA\Property(property: 'periodMonths', type: 'integer', enum: [1, 3, 6, 12], description: "Durée choisie ; doit figurer dans availablePeriods du plan (sinon 1 par défaut). Le montant facturé = prix mensuel du plan × periodMonths."),
        new OA\Property(property: 'purpose', type: 'string', enum: ['SUBSCRIPTION', 'USAGE_RESET'], description: "SUBSCRIPTION (défaut) active le plan `planId` une fois payé. USAGE_RESET remet à 0 le quota de `featureKey` sur l'abonnement actif de l'utilisateur — le montant facturé est alors `resetPrice` du plan, pas son prix d'abonnement."),
        new OA\Property(property: 'featureKey', type: 'string', enum: ['cv_creation', 'document_generation', 'cv_analysis'], description: 'Requis quand purpose = USAGE_RESET.'),
    ]
)]
class CreateCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'userId' => ['required', 'uuid'],
            'planId' => ['required', 'uuid'],
            'gateway' => ['nullable', 'string', 'in:kkiapay,wave,stripe,paiementpro'],
            'currency' => ['nullable', 'string', 'size:3'],
            'successUrl' => ['nullable', 'url'],
            'errorUrl' => ['nullable', 'url'],
            'customerPhone' => ['nullable', 'string', 'max:30'],
            // Pas de whitelist stricte ici : une durée que le plan n'offre pas
            // n'est pas une erreur de requête, elle retombe simplement à 1
            // mois (voir SubscriptionService::checkout() -> allowedPeriods()).
            'periodMonths' => ['nullable', 'integer', 'min:1'],
            'purpose' => ['nullable', 'string', 'in:SUBSCRIPTION,USAGE_RESET'],
            'featureKey' => ['required_if:purpose,USAGE_RESET', 'nullable', 'string', 'in:cv_creation,document_generation,cv_analysis'],
        ];
    }
}
