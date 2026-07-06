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
        Schema::create('cv_analysis', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('filename');
            $table->integer('analysis_score');
            $table->timestamp('upload_date');
            $table->string('status');
            // JSON (pas jsonb/text[] Postgres) pour rester portable MySQL <-> Postgres — voir cast 'array' sur le modèle.
            // Pas de ->default() : MySQL interdit une valeur littérale par défaut sur JSON/TEXT/BLOB
            // (erreur 1101). Toujours fourni explicitement à la création (voir CvAnalysisService::create).
            $table->json('recommendations')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cv_analysis');
    }
};
