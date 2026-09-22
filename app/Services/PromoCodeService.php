<?php

namespace App\Services;

use App\Enums\PromoCampaignStatus;
use App\Enums\PromoDiscountType;
use App\Enums\PromoRedemptionStatus;
use App\Models\PromoCampaign;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Validation d'un code promo au checkout, calcul de la réduction, et cycle
 * de vie des PromoCodeRedemption (PENDING au checkout -> CONFIRMED/CANCELLED
 * à verifyCheckout, voir SubscriptionService).
 */
class PromoCodeService
{
    /*
    |----------------------------------------------------------------------
    | VALIDATION / CALCUL — utilisé par SubscriptionService::checkout() et
    | par le contrôleur public PromoCodesController::validate().
    |----------------------------------------------------------------------
    */

    /**
     * @return array{code: PromoCode, discountType: string, discountValue: float, discountAmount: float, finalAmount: float}
     */
    public function validate(string $rawCode, string $userId, SubscriptionPlan $plan): array
    {
        if ((float) $plan->price === 0.0) {
            abort(422, 'Les codes promo ne s\'appliquent pas aux forfaits gratuits.');
        }

        $code = PromoCode::query()
            ->with('campaign')
            ->where('code', strtoupper(trim($rawCode)))
            ->first();

        if (! $code || ! $code->campaign) {
            abort(422, 'Code promo invalide.');
        }

        if (! $code->campaign->isActiveNow()) {
            abort(422, 'Cette campagne promotionnelle n\'est plus active.');
        }

        if (! $code->isUsable()) {
            abort(422, 'Ce code promo n\'est plus valide (expiré, désactivé ou quota atteint).');
        }

        if (! $code->campaign->appliesToUserType($plan->user_type->value)) {
            abort(422, 'Ce code promo ne s\'applique pas à ce type de compte.');
        }

        if (! $code->campaign->appliesToPlan($plan->id)) {
            abort(422, 'Ce code promo ne s\'applique pas à ce forfait.');
        }

        if ($code->campaign->min_plan_price !== null && (float) $plan->price < (float) $code->campaign->min_plan_price) {
            abort(422, 'Ce code promo ne s\'applique pas à ce forfait.');
        }

        if ($code->max_uses_per_user !== null) {
            $usedByUser = PromoCodeRedemption::query()
                ->where('promo_code_id', $code->id)
                ->where('user_id', $userId)
                ->where('status', PromoRedemptionStatus::CONFIRMED->value)
                ->count();

            if ($usedByUser >= $code->max_uses_per_user) {
                abort(422, 'Vous avez déjà utilisé ce code promo.');
            }
        }

        return [
            'code' => $code,
            'discountType' => $code->effectiveDiscountType()->value,
            'discountValue' => $code->effectiveDiscountValue(),
        ];
    }

    /**
     * @return array{discountAmount: float, finalAmount: float}
     */
    public function computeDiscount(PromoCode $code, float $baseAmount): array
    {
        $type = $code->effectiveDiscountType();
        $value = $code->effectiveDiscountValue();

        $discountAmount = $type === PromoDiscountType::PERCENTAGE
            ? $baseAmount * ($value / 100)
            : $value;

        // Borné à baseAmount : un montant fixe supérieur au prix ne peut pas
        // rendre la facture négative.
        $discountAmount = max(0.0, min($discountAmount, $baseAmount));

        return [
            'discountAmount' => round($discountAmount, 2),
            'finalAmount' => round($baseAmount - $discountAmount, 2),
        ];
    }

    /*
    |----------------------------------------------------------------------
    | CYCLE DE VIE DE LA REDEMPTION — appelé depuis SubscriptionService.
    |----------------------------------------------------------------------
    */

    public function createPendingRedemption(
        PromoCode $code,
        string $userId,
        Transaction $transaction,
        float $originalAmount,
        float $discountAmount,
        float $finalAmount,
        string $currency,
    ): PromoCodeRedemption {
        $commissionRate = $code->effectiveCommissionRate();

        return PromoCodeRedemption::create([
            'promo_code_id' => $code->id,
            'influencer_id' => $code->influencer_id,
            'user_id' => $userId,
            'transaction_id' => $transaction->id,
            'plan_id' => $transaction->plan_id,
            'original_amount' => $originalAmount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'currency' => $currency,
            'commission_rate' => $commissionRate,
            'commission_amount' => $commissionRate !== null ? round($finalAmount * ($commissionRate / 100), 2) : null,
            'status' => PromoRedemptionStatus::PENDING->value,
        ]);
    }

    /**
     * Paiement confirmé (SUCCESS) : la redemption compte désormais dans le
     * quota du code, et la commission (si un influenceur est rattaché)
     * devient due.
     */
    public function confirmRedemption(Transaction $transaction, ?string $subscriptionId = null): void
    {
        $redemption = PromoCodeRedemption::query()->where('transaction_id', $transaction->id)->first();
        if (! $redemption || $redemption->status !== PromoRedemptionStatus::PENDING) {
            return;
        }

        $redemption->update([
            'status' => PromoRedemptionStatus::CONFIRMED->value,
            'subscription_id' => $subscriptionId,
            'redeemed_at' => now(),
        ]);

        PromoCode::query()->where('id', $redemption->promo_code_id)->increment('used_count');
    }

    /**
     * Paiement échoué/annulé : la tentative ne consomme pas le quota du code.
     */
    public function cancelRedemption(Transaction $transaction): void
    {
        PromoCodeRedemption::query()
            ->where('transaction_id', $transaction->id)
            ->where('status', PromoRedemptionStatus::PENDING->value)
            ->update(['status' => PromoRedemptionStatus::CANCELLED->value]);
    }

    /*
    |----------------------------------------------------------------------
    | ADMIN — CRUD campagnes/codes.
    |----------------------------------------------------------------------
    */

    public function paginateCampaigns(int $page, int $limit): LengthAwarePaginator
    {
        return PromoCampaign::query()
            ->withCount('codes')
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * @param  array{name: string, description?: ?string, discountType: string, discountValue: float, applicableUserTypes: array, applicablePlanIds?: ?array, minPlanPrice?: ?float, startsAt?: ?string, endsAt?: ?string, status?: ?string}  $data
     */
    public function createCampaign(array $data, ?string $createdBy): PromoCampaign
    {
        return PromoCampaign::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'discount_type' => $data['discountType'],
            'discount_value' => $data['discountValue'],
            'applicable_user_types' => $data['applicableUserTypes'],
            'applicable_plan_ids' => $data['applicablePlanIds'] ?? null,
            'min_plan_price' => $data['minPlanPrice'] ?? null,
            'starts_at' => $data['startsAt'] ?? null,
            'ends_at' => $data['endsAt'] ?? null,
            'status' => $data['status'] ?? PromoCampaignStatus::DRAFT->value,
            'created_by' => $createdBy,
        ]);
    }

    public function updateCampaign(PromoCampaign $campaign, array $data): PromoCampaign
    {
        $updates = [];
        foreach ([
            'name' => 'name',
            'description' => 'description',
            'discountType' => 'discount_type',
            'discountValue' => 'discount_value',
            'applicableUserTypes' => 'applicable_user_types',
            'applicablePlanIds' => 'applicable_plan_ids',
            'minPlanPrice' => 'min_plan_price',
            'startsAt' => 'starts_at',
            'endsAt' => 'ends_at',
            'status' => 'status',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $updates[$column] = $data[$input];
            }
        }

        $campaign->update($updates);

        return $campaign;
    }

    public function deleteCampaign(PromoCampaign $campaign): void
    {
        $campaign->delete();
    }

    /**
     * @param  array{campaignId?: ?string, influencerId?: ?string, isActive?: ?bool}  $filters
     */
    public function paginateCodes(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $query = PromoCode::query()->with(['campaign', 'influencer']);

        if (! empty($filters['campaignId'])) {
            $query->where('campaign_id', $filters['campaignId']);
        }
        if (! empty($filters['influencerId'])) {
            $query->where('influencer_id', $filters['influencerId']);
        }
        if (array_key_exists('isActive', $filters) && $filters['isActive'] !== null) {
            $query->where('is_active', (bool) $filters['isActive']);
        }

        return $query->orderByDesc('created_at')->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * @param  array{campaignId: string, influencerId?: ?string, code: string, discountType?: ?string, discountValue?: ?float, commissionRate?: ?float, maxUses?: ?int, maxUsesPerUser?: ?int, startsAt?: ?string, endsAt?: ?string, isActive?: ?bool}  $data
     */
    public function createCode(array $data, ?string $createdBy): PromoCode
    {
        return PromoCode::create([
            'campaign_id' => $data['campaignId'],
            'influencer_id' => $data['influencerId'] ?? null,
            'code' => $data['code'],
            'discount_type' => $data['discountType'] ?? null,
            'discount_value' => $data['discountValue'] ?? null,
            'commission_rate' => $data['commissionRate'] ?? null,
            'max_uses' => $data['maxUses'] ?? null,
            'max_uses_per_user' => $data['maxUsesPerUser'] ?? 1,
            'starts_at' => $data['startsAt'] ?? null,
            'ends_at' => $data['endsAt'] ?? null,
            'is_active' => $data['isActive'] ?? true,
            'created_by' => $createdBy,
        ]);
    }

    public function updateCode(PromoCode $code, array $data): PromoCode
    {
        $updates = [];
        foreach ([
            'influencerId' => 'influencer_id',
            'discountType' => 'discount_type',
            'discountValue' => 'discount_value',
            'commissionRate' => 'commission_rate',
            'maxUses' => 'max_uses',
            'maxUsesPerUser' => 'max_uses_per_user',
            'startsAt' => 'starts_at',
            'endsAt' => 'ends_at',
            'isActive' => 'is_active',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $updates[$column] = $data[$input];
            }
        }

        $code->update($updates);

        return $code;
    }

    public function deleteCode(PromoCode $code): void
    {
        $code->delete();
    }

    /**
     * Code aléatoire unique, non persisté — alimente le bouton "Générer un
     * code" côté admin.
     */
    public function generateUniqueCode(string $prefix = ''): string
    {
        do {
            $candidate = strtoupper($prefix.Str::random(6));
        } while (PromoCode::query()->where('code', $candidate)->exists());

        return $candidate;
    }

    /**
     * @return array{campaigns: int, activeCodes: int, redemptions: int, totalDiscountGiven: float, commissionOwed: float, commissionPaid: float}
     */
    public function stats(): array
    {
        $confirmed = PromoCodeRedemption::query()->where('status', PromoRedemptionStatus::CONFIRMED->value);

        return [
            'campaigns' => PromoCampaign::query()->count(),
            'activeCodes' => PromoCode::query()->where('is_active', true)->count(),
            'redemptions' => (clone $confirmed)->count(),
            'totalDiscountGiven' => (float) (clone $confirmed)->sum('discount_amount'),
            'commissionOwed' => (float) (clone $confirmed)->whereNull('payout_id')->sum('commission_amount'),
            'commissionPaid' => (float) (clone $confirmed)->whereNotNull('payout_id')->sum('commission_amount'),
        ];
    }

    public function paginateRedemptionsForCode(PromoCode $code, int $page, int $limit): LengthAwarePaginator
    {
        return $code->redemptions()
            ->with(['user', 'plan'])
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }
}
