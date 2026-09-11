<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Services RH — étape 5 : pipeline de recrutement.
 *
 * Le pipeline s'appuie sur les candidatures existantes : leur étape
 * (`pipeline_stage`) affine le statut public que suit le candidat. Autour
 * d'elles : l'historique des étapes, les entretiens et les notes des recruteurs,
 * tous supprimés avec la candidature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table) {
            // Nul tant que la candidature n'est pas passée par le pipeline :
            // l'étape se déduit alors du statut public.
            $table->string('pipeline_stage')->nullable();
            $table->timestamp('stage_changed_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });

        Schema::create('recruitment_stage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->text('comment')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['candidature_id', 'created_at']);
        });

        Schema::create('recruitment_interviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->string('type');
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(45);
            // Adresse, numéro à appeler ou lien de visioconférence, selon le type.
            $table->string('location')->nullable();
            $table->string('meeting_url', 500)->nullable();
            $table->string('interviewers')->nullable();
            // Préparation (points à creuser) : visible des recruteurs seulement.
            $table->text('description')->nullable();
            $table->string('status')->default('SCHEDULED');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('recommendation')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('candidate_notified_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('outcome_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('outcome_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['company_id', 'scheduled_at']);
            $table->index(['candidature_id', 'status']);
        });

        Schema::create('recruitment_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->text('body');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['candidature_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_notes');
        Schema::dropIfExists('recruitment_interviews');
        Schema::dropIfExists('recruitment_stage_events');

        Schema::table('candidatures', function (Blueprint $table) {
            $table->dropColumn(['pipeline_stage', 'stage_changed_at', 'rejection_reason']);
        });
    }
};
