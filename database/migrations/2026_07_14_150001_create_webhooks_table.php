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
        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('url');
            // ['candidature.status_updated', 'candidate_assessment.completed']
            $table->json('events');
            // Secret HMAC pour signer les payloads sortants (header
            // X-Goriya-Signature) — voir WebhookService::sign().
            $table->string('secret');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
