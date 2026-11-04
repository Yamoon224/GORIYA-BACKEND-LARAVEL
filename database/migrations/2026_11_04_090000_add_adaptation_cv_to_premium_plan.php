<?php

use App\Models\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;

/**
 * « Adapter son CV aux TDR d'une offre » est réservée au forfait Premium.
 * Le seeder ne s'applique qu'aux bases neuves : cette migration ajoute la clé
 * `adaptation_cv` au plan Premium déjà en base (voir plan.feature).
 */
return new class extends Migration
{
    public function up(): void
    {
        SubscriptionPlan::where('name', 'Premium')->get()->each(function (SubscriptionPlan $plan) {
            $features = $plan->included_features ?? [];
            if (! in_array('adaptation_cv', $features, true)) {
                $features[] = 'adaptation_cv';
                $plan->included_features = $features;
                $plan->save();
            }
        });
    }

    public function down(): void
    {
        SubscriptionPlan::where('name', 'Premium')->get()->each(function (SubscriptionPlan $plan) {
            $plan->included_features = array_values(array_diff($plan->included_features ?? [], ['adaptation_cv']));
            $plan->save();
        });
    }
};
