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
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('survey_id')->constrained('employee_surveys')->cascadeOnDelete();
            // Jamais exposé par l'API (voir SurveyResponseResource /
            // EmployeeSurveyService::stats()) — sert uniquement à empêcher
            // une double réponse, pas à identifier l'auteur d'une réponse
            // pour l'entreprise. Anonymat vis-à-vis de l'entreprise, pas
            // vis-à-vis de la base de données.
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // [{questionId, value}] — value: int (RATING) ou string (TEXT)
            $table->json('answers');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['survey_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
