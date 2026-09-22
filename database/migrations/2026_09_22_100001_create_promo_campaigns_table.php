<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('discount_type');
            $table->decimal('discount_value', 10, 2);

            // Sous-ensemble de SubscriptionUserType::values() — ['USER'],
            // ['ENTREPRISE'] ou les deux, cf. "standard et/ou entreprise".
            $table->json('applicable_user_types');

            // null = tous les plans des types ciblés ; sinon liste blanche
            // d'ids SubscriptionPlan.
            $table->json('applicable_plan_ids')->nullable();
            $table->decimal('min_plan_price', 10, 2)->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status')->default('DRAFT');

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_campaigns');
    }
};
