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
        Schema::create('call_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('host_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            // Identifiants côté lunion.meet — room_slug est l'identifiant
            // stable utilisé dans tous les appels API ultérieurs (token,
            // suppression), room_ref est l'id interne renvoyé à la création,
            // conservé pour référence/debug uniquement.
            $table->string('room_slug')->unique();
            $table->string('room_ref')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status')->default('SCHEDULED');
            $table->string('recording_url')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_sessions');
    }
};
