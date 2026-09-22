<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Taux appliqué par défaut à ses codes ; un PromoCode peut le
            // surcharger individuellement (deals différents par partenariat).
            $table->decimal('default_commission_rate', 5, 2)->nullable();

            $table->text('notes')->nullable();
            $table->string('status')->default('ACTIVE');

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('influencers');
    }
};
