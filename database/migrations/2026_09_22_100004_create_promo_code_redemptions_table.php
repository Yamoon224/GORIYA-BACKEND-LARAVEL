<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_code_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();

            // Dénormalisé depuis promo_codes.influencer_id au moment de
            // l'usage : le reporting/solde d'un influenceur reste correct
            // même si le code change de titulaire plus tard.
            $table->foreignUuid('influencer_id')->nullable()->constrained('influencers')->nullOnDelete();

            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('user_subscriptions')->nullOnDelete();
            $table->foreignUuid('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();

            $table->decimal('original_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->decimal('final_amount', 12, 2);
            $table->string('currency')->default('XOF');

            // Snapshot au moment de la redemption : une évolution ultérieure
            // du taux de l'influenceur/du code ne doit pas rejouer l'histo.
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->decimal('commission_amount', 12, 2)->nullable();

            $table->string('status')->default('PENDING');

            // Renseigné une fois la commission incluse dans un versement.
            $table->foreignUuid('payout_id')->nullable()->constrained('influencer_payouts')->nullOnDelete();

            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_redemptions');
    }
};
