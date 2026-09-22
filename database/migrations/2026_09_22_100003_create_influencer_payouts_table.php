<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencer_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('influencer_id')->constrained('influencers')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('currency')->default('XOF');
            $table->string('status')->default('PENDING');
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('influencer_payouts');
    }
};
