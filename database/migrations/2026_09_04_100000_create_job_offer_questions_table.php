<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Questions de présélection configurées par l'entreprise sur une offre
     * (façon LinkedIn) : le candidat y répond dans le wizard de candidature.
     *
     * Table dédiée plutôt qu'une colonne JSON sur `job_offers` : les réponses
     * (`candidature_answers`) pointent vers une question par sa clé étrangère,
     * ce qui garantit qu'une réponse orpheline ne peut pas exister.
     */
    public function up(): void
    {
        Schema::create('job_offer_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('job_offer_id')->constrained('job_offers')->cascadeOnDelete();
            $table->text('label');
            $table->string('type')->default('TEXT');
            // Choix proposés (SINGLE_CHOICE / MULTI_CHOICE) — null pour les
            // autres types. JSON sans ->default() : MySQL refuse un littéral
            // par défaut sur une colonne JSON (erreur 1101).
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['job_offer_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_offer_questions');
    }
};
