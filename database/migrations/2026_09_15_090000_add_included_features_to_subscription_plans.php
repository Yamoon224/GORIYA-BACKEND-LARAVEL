<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `feature_limits` (migration précédente) ne couvre que les 3
 * fonctionnalités "Limité" à quota numérique (cv_creation,
 * document_generation, cv_analysis). Les fonctionnalités simplement
 * incluses/absentes selon le forfait (Simulation d'entretien, Portfolio,
 * Goriya Pitch, Goriya Docs, Recherche avancée entreprise côté USER ;
 * Enquêtes internes, Gestion de paie, Intégration API côté ENTERPRISE)
 * n'avaient AUCUNE vérification côté backend — seul le frontend décidait
 * d'afficher ou non la page (SubscriptionGate), ce qui laissait un compte
 * Grouilleur (ou Business sur une fonctionnalité Business+) y accéder en
 * appelant l'API directement.
 *
 * `included_features` liste les clés que le plan débloque — voir
 * SubscriptionPlan::hasFeature() et le middleware EnsurePlanIncludesFeature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->json('included_features')->nullable()->after('feature_limits');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('included_features');
        });
    }
};
