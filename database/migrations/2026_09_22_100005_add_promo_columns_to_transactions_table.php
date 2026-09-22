<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignUuid('promo_code_id')->nullable()->after('plan_id')->constrained('promo_codes')->nullOnDelete();
            $table->decimal('discount_amount', 12, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropColumn('discount_amount');
        });
    }
};
