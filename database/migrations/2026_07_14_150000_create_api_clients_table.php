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
        Schema::create('api_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            // Seul le hash est stocké (SHA-256, comme les personal access
            // tokens Sanctum) — le jeton en clair n'est retourné qu'une
            // fois, à la création, voir ApiClientService::create().
            $table->string('token_hash')->unique();
            $table->boolean('is_sandbox')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
