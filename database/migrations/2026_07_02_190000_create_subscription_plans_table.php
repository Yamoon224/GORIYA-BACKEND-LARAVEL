<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('billing_period');
            $table->string('user_type');
            $table->boolean('is_active')->default(true);
            // json (pas jsonb) pour rester portable MySQL <-> Postgres — Laravel 'array' cast fonctionne pareil sur les deux.
            // Pas de ->default() : MySQL interdit une valeur littérale par défaut sur JSON/TEXT/BLOB
            // (erreur 1101). Toujours fourni explicitement à la création (voir SubscriptionPlanSeeder).
            $table->json('features')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
