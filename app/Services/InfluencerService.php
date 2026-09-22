<?php

namespace App\Services;

use App\Enums\InfluencerPayoutStatus;
use App\Enums\PromoRedemptionStatus;
use App\Models\Influencer;
use App\Models\InfluencerPayout;
use App\Models\PromoCodeRedemption;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use RuntimeException;

/**
 * CRUD influenceurs + versements de commission. Une commission ne devient
 * "due" qu'une fois la redemption CONFIRMED (paiement abouti) — voir
 * PromoCodeService::confirmRedemption().
 */
class InfluencerService
{
    public function paginate(int $page, int $limit): LengthAwarePaginator
    {
        return Influencer::query()
            ->withCount('promoCodes')
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * @param  array{name: string, email?: ?string, phone?: ?string, defaultCommissionRate?: ?float, notes?: ?string, status?: ?string}  $data
     */
    public function create(array $data, ?string $createdBy): Influencer
    {
        return Influencer::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'default_commission_rate' => $data['defaultCommissionRate'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? 'ACTIVE',
            'created_by' => $createdBy,
        ]);
    }

    public function update(Influencer $influencer, array $data): Influencer
    {
        $updates = [];
        foreach ([
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'defaultCommissionRate' => 'default_commission_rate',
            'notes' => 'notes',
            'status' => 'status',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $updates[$column] = $data[$input];
            }
        }

        $influencer->update($updates);

        return $influencer;
    }

    public function delete(Influencer $influencer): void
    {
        $influencer->delete();
    }

    public function paginateRedemptions(Influencer $influencer, int $page, int $limit): LengthAwarePaginator
    {
        return $influencer->redemptions()
            ->with(['promoCode', 'user', 'plan'])
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * @return array{unpaidBalance: float, totalEarned: float, totalPaid: float}
     */
    public function balance(Influencer $influencer): array
    {
        $confirmed = $influencer->redemptions()->where('status', PromoRedemptionStatus::CONFIRMED->value);

        return [
            'unpaidBalance' => $influencer->unpaidBalance(),
            'totalEarned' => (float) (clone $confirmed)->sum('commission_amount'),
            'totalPaid' => (float) (clone $confirmed)->whereNotNull('payout_id')->sum('commission_amount'),
        ];
    }

    /*
    |----------------------------------------------------------------------
    | VERSEMENTS
    |----------------------------------------------------------------------
    */

    public function paginatePayouts(int $page, int $limit, ?string $influencerId = null): LengthAwarePaginator
    {
        $query = InfluencerPayout::query()->with('influencer')->orderByDesc('created_at');

        if ($influencerId) {
            $query->where('influencer_id', $influencerId);
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Crée un versement à partir de redemptions confirmées et non encore
     * payées, choisies explicitement par l'admin (redemptionIds) : le
     * montant du versement est la somme de leur commission_amount.
     *
     * @param  array{influencerId: string, redemptionIds: array<int, string>, paymentMethod?: ?string, paymentReference?: ?string, notes?: ?string, markPaid?: bool}  $data
     */
    public function createPayout(array $data, ?string $createdBy): InfluencerPayout
    {
        $redemptions = PromoCodeRedemption::query()
            ->where('influencer_id', $data['influencerId'])
            ->where('status', PromoRedemptionStatus::CONFIRMED->value)
            ->whereNull('payout_id')
            ->whereIn('id', $data['redemptionIds'])
            ->get();

        if ($redemptions->isEmpty()) {
            throw new RuntimeException('Aucune commission éligible parmi les redemptions sélectionnées.');
        }

        $amount = (float) $redemptions->sum('commission_amount');
        $markPaid = $data['markPaid'] ?? false;

        $payout = InfluencerPayout::create([
            'influencer_id' => $data['influencerId'],
            'amount' => $amount,
            'status' => $markPaid ? InfluencerPayoutStatus::PAID->value : InfluencerPayoutStatus::PENDING->value,
            'payment_method' => $data['paymentMethod'] ?? null,
            'payment_reference' => $data['paymentReference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'paid_at' => $markPaid ? now() : null,
            'created_by' => $createdBy,
        ]);

        PromoCodeRedemption::query()
            ->whereIn('id', $redemptions->pluck('id'))
            ->update(['payout_id' => $payout->id]);

        return $payout;
    }

    /**
     * @param  array{status?: ?string, paymentMethod?: ?string, paymentReference?: ?string, notes?: ?string}  $data
     */
    public function updatePayout(InfluencerPayout $payout, array $data): InfluencerPayout
    {
        $updates = [];
        foreach ([
            'paymentMethod' => 'payment_method',
            'paymentReference' => 'payment_reference',
            'notes' => 'notes',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $updates[$column] = $data[$input];
            }
        }

        if (! empty($data['status'])) {
            $updates['status'] = $data['status'];
            if ($data['status'] === InfluencerPayoutStatus::PAID->value && ! $payout->paid_at) {
                $updates['paid_at'] = now();
            }
        }

        $payout->update($updates);

        // Un versement annulé libère ses redemptions : la commission redevient
        // due et pourra être incluse dans un prochain versement.
        if ($payout->status === InfluencerPayoutStatus::CANCELLED) {
            $payout->redemptions()->update(['payout_id' => null]);
        }

        return $payout;
    }
}
