<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nouvelle grille tarifaire (sept. 2026) :
 *  - Entreprise (Business, Business+) : périodicité choisissable à l'achat
 *    (1, 3, 6, 12 mois), montant = prix mensuel du plan × nombre de mois.
 *    `available_periods` sur subscription_plans liste les durées permises
 *    (null = fixe à 1 mois, cas de tous les plans USER) ; `period_months` sur
 *    transactions/user_subscriptions trace la durée effectivement achetée.
 *  - `attempt_limit` / `reset_price` : métadonnées des forfaits "Limité"
 *    (Standard = 5 tentatives, Premium = 20 tentatives — CV, documents,
 *    analyses — rechargeables à `reset_price` XOF une fois épuisées).
 *    ⚠️ Ce ne sont que des données de catalogue : le décompte des tentatives
 *    et le paiement de réinitialisation ne sont pas encore branchés côté
 *    fonctionnalités (création CV / génération documents / analyse CV).
 *  - `notification_level` : "FAIBLE" (in-app) ou "ELEVE" (in-app + email),
 *    reflète la colonne "Notification (d'offre) prioritaire" de la grille.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->json('available_periods')->nullable()->after('billing_period');
            $table->string('notification_level')->nullable()->after('features');
            $table->unsignedInteger('attempt_limit')->nullable()->after('notification_level');
            $table->decimal('reset_price', 12, 2)->nullable()->after('attempt_limit');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedInteger('period_months')->default(1)->after('amount');
        });

        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('period_months')->default(1)->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['available_periods', 'notification_level', 'attempt_limit', 'reset_price']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('period_months');
        });

        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropColumn('period_months');
        });
    }
};
