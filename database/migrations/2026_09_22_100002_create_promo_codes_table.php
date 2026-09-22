<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('promo_campaigns')->cascadeOnDelete();

            // Lien "code lié ou non à un influenceur" : nullable, un code
            // générique n'a pas d'influenceur.
            $table->foreignUuid('influencer_id')->nullable()->constrained('influencers')->nullOnDelete();

            $table->string('code')->unique();

            // Surcharge la réduction de la campagne si renseignée (deal
            // différent pour cet influenceur/ce code précis).
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();

            // Surcharge default_commission_rate de l'influenceur pour ce code.
            $table->decimal('commission_rate', 5, 2)->nullable();

            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('max_uses_per_user')->default(1);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
