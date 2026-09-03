<?php

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionUserType;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;

/**
 * Insère l'« Offre gratuite » entreprise. Le plan est aussi décrit dans
 * SubscriptionPlanSeeder (source de vérité pour un environnement neuf), mais
 * le seeder n'est pas rejoué sur les bases déjà en production : cette
 * migration garantit que l'offre existe après déploiement.
 *
 * Idempotent (updateOrCreate sur le nom), comme le seeder.
 */
return new class extends Migration
{
    private const PLAN_NAME = 'Offre gratuite';

    public function up(): void
    {
        SubscriptionPlan::updateOrCreate(
            ['name' => self::PLAN_NAME],
            [
                'price' => 0,
                'billing_period' => BillingPeriod::MONTHLY,
                'user_type' => SubscriptionUserType::ENTERPRISE,
                'features' => [
                    'Accès au tableau de bord',
                    "Publication d'offres d'emploi",
                    'Gestion de vos annonces',
                    'Suivi des candidatures',
                    'Messagerie avec les candidats',
                    'Profil entreprise complet',
                ],
                'is_active' => true,
            ]
        );
    }

    /**
     * On désactive au lieu de supprimer : des UserSubscription peuvent déjà
     * référencer ce plan, et un delete casserait leur relation.
     */
    public function down(): void
    {
        SubscriptionPlan::where('name', self::PLAN_NAME)->update(['is_active' => false]);
    }
};
