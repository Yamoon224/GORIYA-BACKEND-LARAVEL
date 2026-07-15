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
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token');
            $table->string('platform');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // Un même token ne peut être enregistré qu'une fois par
            // utilisateur (ré-enregistrement idempotent) — voir
            // DeviceTokenService::register().
            $table->unique(['user_id', 'token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
