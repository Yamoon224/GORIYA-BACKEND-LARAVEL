<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moteur de quota des fonctionnalités "Limité" (Créer un CV / Générer des
 * documents / Analyse de CV) + paiement de réinitialisation :
 *
 *  - `attempt_limit` (un seul entier partagé par les 3 fonctionnalités,
 *    ajouté dans la migration précédente) ne suffit pas : Grouilleur limite
 *    l'analyse de CV (2) sans donner accès aux deux autres fonctionnalités.
 *    Remplacé par `feature_limits`, une map featureKey -> limite
 *    ("cv_analysis": 2 pour Grouilleur, les 3 clés à 5/20 pour
 *    Standard/Premium). Une clé absente = fonctionnalité non incluse dans
 *    le plan (pas juste "illimitée").
 *  - `feature_usages` compte les utilisations par (utilisateur, plan actif,
 *    fonctionnalité) — mirroir authentifié de `anonymous_usages`
 *    (AnonymousUsageService), le pendant côté visiteur non connecté.
 *    Le scope par `user_subscription_id` (pas par date) fait qu'un
 *    réabonnement/changement de forfait reparte naturellement de 0 : chaque
 *    checkout crée une nouvelle UserSubscription (voir
 *    SubscriptionService::performSubscribe()).
 *  - `transactions.purpose`/`feature_key` distinguent un paiement de
 *    réinitialisation de quota d'un paiement d'abonnement classique, pour
 *    réutiliser le même flux de checkout (gateways déjà câblés) sans le
 *    dupliquer — voir SubscriptionService::checkout()/verifyCheckout().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->json('feature_limits')->nullable()->after('reset_price');
        });

        // SQLite (tests) ne sait pas DROP COLUMN sans doctrine/dbal — cette
        // colonne n'a jamais été lue en dehors du seeder qu'on vient de
        // changer, on la laisse simplement inutilisée plutôt que de dépendre
        // d'une extension supplémentaire.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->dropColumn('attempt_limit');
            });
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('purpose')->default('SUBSCRIPTION')->after('plan_id');
            $table->string('feature_key')->nullable()->after('purpose');
        });

        Schema::create('feature_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('user_subscription_id')->constrained('user_subscriptions')->cascadeOnDelete();
            $table->string('feature_key');
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['user_subscription_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_usages');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'feature_key']);
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->unsignedInteger('attempt_limit')->nullable();
            }
            $table->dropColumn('feature_limits');
        });
    }
};
