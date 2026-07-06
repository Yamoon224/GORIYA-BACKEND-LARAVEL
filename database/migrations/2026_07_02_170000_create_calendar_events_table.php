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
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('type');
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->string('location')->nullable();
            $table->string('status');
            // JSON (pas jsonb/text[] Postgres) pour rester portable MySQL <-> Postgres — voir cast 'array' sur le modèle.
            // Pas de ->default() : MySQL interdit une valeur littérale par défaut sur JSON/TEXT/BLOB
            // (erreur 1101). Toujours fourni explicitement à la création (voir CreateCalendarEventRequest).
            $table->json('participants')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
