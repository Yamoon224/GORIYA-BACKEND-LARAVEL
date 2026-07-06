<?php

namespace Database\Seeders;

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionUserType;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Mirroir de SubscriptionsService.seedPlans() (NestJS) — là-bas exécuté une
 * fois au boot du process via OnModuleInit ; ici un seeder classique Laravel
 * (php artisan db:seed), idempotent via updateOrCreate pour rester safe à
 * relancer.
 */
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Grouilleur',
                'price' => 0,
                'billing_period' => BillingPeriod::MONTHLY,
                'user_type' => SubscriptionUserType::USER,
                'features' => [
                    "Recherche d'emploi illimitée",
                    '3 analyses CV par mois',
                    'Support par email',
                    'Valable 2 semaines',
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Standard',
                'price' => 1999,
                'billing_period' => BillingPeriod::MONTHLY,
                'user_type' => SubscriptionUserType::USER,
                'features' => [
                    '20 analyses CV par mois',
                    'Suggestions avancées IA',
                    'Multi-formats export',
                    'Personnalisation sectorielle',
                    'Support prioritaire',
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Premium',
                'price' => 4999,
                'billing_period' => BillingPeriod::MONTHLY,
                'user_type' => SubscriptionUserType::USER,
                'features' => [
                    'Analyses CV illimitées',
                    'Suggestions IA avancées',
                    "Simulation d'entretien IA",
                    'Support prioritaire',
                    'Export multi-formats',
                    'Personnalisation sectorielle',
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Business',
                'price' => 35500,
                'billing_period' => BillingPeriod::MONTHLY,
                'user_type' => SubscriptionUserType::ENTERPRISE,
                'features' => [
                    '20 analyses CV par mois',
                    'Suggestions avancées IA',
                    'Multi-formats export',
                    'Personnalisation sectorielle',
                    'Support prioritaire',
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Business+',
                'price' => 351900,
                'billing_period' => BillingPeriod::ANNUAL,
                'user_type' => SubscriptionUserType::ENTERPRISE,
                'features' => [
                    '20 analyses CV par mois',
                    'Suggestions avancées IA',
                    'Multi-formats export',
                    'Personnalisation sectorielle',
                    'Support prioritaire',
                ],
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(['name' => $plan['name']], $plan);
        }
    }
}
