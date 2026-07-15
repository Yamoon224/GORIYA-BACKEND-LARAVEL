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
        Schema::create('candidate_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Une candidature = une évaluation (regénérée sur place, pas
            // dupliquée) — voir CandidateAssessmentService::create().
            $table->foreignUuid('candidature_id')->unique()
                ->constrained('candidatures')->cascadeOnDelete();
            $table->unsignedTinyInteger('technical_score')->nullable();
            $table->unsignedTinyInteger('soft_skills_score')->nullable();
            $table->unsignedTinyInteger('cultural_fit_score')->nullable();
            $table->unsignedTinyInteger('overall_score')->nullable();
            $table->json('skills_test')->nullable();
            $table->text('soft_skills_feedback')->nullable();
            $table->string('report_path')->nullable();
            $table->string('status')->default('PENDING');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_assessments');
    }
};
