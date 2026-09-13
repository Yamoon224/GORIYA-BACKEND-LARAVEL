<?php

namespace Database\Seeders;

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionUserType;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Grille tarifaire sept. 2026 (voir "FONCTIONNALITÉ GORIYA.pdf" partagé) :
 *  - "Limité" (Créer un CV / Générer des documents / Analyse de CV) veut dire
 *    un nombre de tentatives — 2 pour Grouilleur (analyse seulement), 5 pour
 *    Standard, 20 pour Premium — par clé de `feature_limits`
 *    (cv_creation/document_generation/cv_analysis). Une clé absente = la
 *    fonctionnalité n'est pas incluse dans le plan, pas "illimitée". Épuisées,
 *    elles se rechargent via un paiement de `reset_price` (500 XOF) qui remet
 *    le compteur à 0 — voir UserFeatureUsageService et
 *    SubscriptionService::checkoutUsageReset().
 *  - "Notification (d'offre) prioritaire" : FAIBLE = in-app, ELEVE = in-app
 *    + email (voir entreprise/lib/plan-access.ts et le check() du backend).
 *  - `included_features` couvre les fonctionnalités simplement incluses/
 *    absentes selon le forfait (pas de quota numérique, contrairement à
 *    `feature_limits`) : Goriya Meet/Connect, Simulation d'entretien,
 *    Portfolio, Goriya Pitch/Docs, Recherche avancée entreprise côté USER ;
 *    Enquêtes internes, Gestion de paie côté ENTERPRISE. Vérifié côté
 *    backend par le middleware `plan.feature` (voir routes/api.php et
 *    EnsurePlanIncludesFeature) — une clé absente est un vrai refus HTTP,
 *    pas seulement une page masquée côté frontend.
 *  - Entreprise (Business, Business+) : `available_periods` rend la
 *    périodicité choisissable (1/3/6/12 mois) au checkout — le prix
 *    ci-dessous reste le prix MENSUEL de base, multiplié par la durée
 *    choisie (voir SubscriptionService::checkout()). Le palier "Sur Mesure"
 *    n'est pas un SubscriptionPlan : il est sur devis, sans checkout — voir
 *    entreprise/components/marketing/custom-plan-card.tsx.
 *
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
                    '2 analyses de CV par mois',
                    'Historique de candidatures',
                    'Notifications d\'offres (in-app)',
                    'Goriya Chat',
                    'Support par email',
                    'Valable 2 semaines',
                ],
                'notification_level' => 'FAIBLE',
                'feature_limits' => ['cv_analysis' => 2],
                'included_features' => [],
                'is_active' => true,
            ],
            [
                'name' => 'Standard',
                'price' => 1999,
                'billing_period' => BillingPeriod::MONTHLY,
                'user_type' => SubscriptionUserType::USER,
                'features' => [
                    'Goriya Meet',
                    'Goriya Connect',
                    'Créer un CV (5 tentatives, réinitialisables à 500 XOF)',
                    'Générer des documents (5 tentatives, réinitialisables à 500 XOF)',
                    'Recherche avancée sur une entreprise',
                    'Analyse de CV (5 tentatives, réinitialisables à 500 XOF)',
                    "Notifications d'offres prioritaires : in-app + email",
                    'Support prioritaire',
                ],
                'notification_level' => 'ELEVE',
                'feature_limits' => ['cv_creation' => 5, 'document_generation' => 5, 'cv_analysis' => 5],
                'included_features' => ['goriya_meet', 'goriya_connect', 'recherche_entreprise'],
                'reset_price' => 500,
                'is_active' => true,
            ],
            [
                'name' => 'Premium',
                'price' => 4999,
                'billing_period' => BillingPeriod::MONTHLY,
                'user_type' => SubscriptionUserType::USER,
                'features' => [
                    'Goriya Meet',
                    'Goriya Connect',
                    'Créer un CV (20 tentatives, réinitialisables à 500 XOF)',
                    "Simulation d'entretien IA",
                    'Générer des documents (20 tentatives, réinitialisables à 500 XOF)',
                    'Créer un Portfolio',
                    'Recherche avancée sur une entreprise',
                    'Analyse de CV (20 tentatives, réinitialisables à 500 XOF)',
                    'Goriya Pitch',
                    'Goriya Docs',
                    "Notifications d'offres prioritaires : in-app + email",
                    'Support prioritaire',
                ],
                'notification_level' => 'ELEVE',
                'feature_limits' => ['cv_creation' => 20, 'document_generation' => 20, 'cv_analysis' => 20],
                'included_features' => [
                    'goriya_meet', 'goriya_connect', 'recherche_entreprise',
                    'simulation_entretien', 'portfolio', 'goriya_pitch', 'goriya_docs',
                ],
                'reset_price' => 500,
                'is_active' => true,
            ],
            [
                // Offre de découverte entreprise : activable sans paiement
                // (SubscriptionService::subscribe n'accepte que les plans à 0)
                // pour permettre de tester l'espace recrutement avant de
                // souscrire. Les Services RH y sont inclus (employés, congés,
                // recrutement, contrats, documents) ; restent fermés Goriya
                // Meet, les enquêtes internes, la gestion de paie et les
                // intégrations API — voir entreprise/lib/plan-access.ts.
                // N'apparaît pas dans la grille Business/Business+/Sur
                // Mesure : c'est un 4ᵉ palier propre à l'app, pas au document
                // tarifaire.
                'name' => 'Offre gratuite',
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
                    'Services RH : employés, recrutements, contrats, congés et documents',
                ],
                'included_features' => [],
                'is_active' => true,
            ],
            [
                'name' => 'Business',
                'price' => 35500,
                'billing_period' => BillingPeriod::MONTHLY,
                'available_periods' => [1, 3, 6, 12],
                'user_type' => SubscriptionUserType::ENTERPRISE,
                'features' => [
                    'Poster une offre',
                    'Historique des annonces avec filtres',
                    'Gestion des candidatures',
                    'Goriya Meet',
                    'Services RH : gestion des employés, processus de recrutement, gestion des contrats',
                    'Édition du profil entreprise',
                    'Notifications prioritaires (in-app)',
                ],
                'notification_level' => 'FAIBLE',
                'included_features' => ['goriya_meet'],
                'is_active' => true,
            ],
            [
                // Prix mensuel de base (auparavant 351 900 XOF/an, sans base
                // mensuelle claire) : 45 500 XOF/mois, multiplié par la durée
                // choisie au checkout comme Business.
                'name' => 'Business+',
                'price' => 45500,
                'billing_period' => BillingPeriod::MONTHLY,
                'available_periods' => [1, 3, 6, 12],
                'user_type' => SubscriptionUserType::ENTERPRISE,
                'features' => [
                    'Poster une offre',
                    'Historique des annonces avec filtres',
                    'Gestion des candidatures',
                    'Goriya Meet',
                    'Services RH complets : employés, recrutement, enquêtes internes, contrats, paie',
                    'Édition du profil entreprise',
                    "Notifications prioritaires : in-app + email",
                ],
                'notification_level' => 'ELEVE',
                'included_features' => ['goriya_meet', 'enquetes_internes', 'gestion_paie'],
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(['name' => $plan['name']], $plan);
        }
    }
}
