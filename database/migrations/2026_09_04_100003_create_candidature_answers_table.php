<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réponses du candidat aux questions de l'offre. Le libellé est recopié
     * dans `question_label` : l'entreprise peut réécrire ou supprimer une
     * question après coup, la candidature doit rester lisible telle qu'elle a
     * été soumise.
     */
    public function up(): void
    {
        Schema::create('candidature_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->foreignUuid('question_id')->nullable()
                ->constrained('job_offer_questions')->nullOnDelete();
            $table->text('question_label');
            $table->string('question_type')->default('TEXT');
            // Toujours une liste de chaînes, y compris pour une réponse unique :
            // MULTI_CHOICE en produit plusieurs, le reste exactement une.
            $table->json('value');
            // Ordre d'affichage figé à la soumission : le recruteur relit les
            // réponses dans l'ordre où les questions ont été posées, même si
            // l'entreprise réordonne ou supprime des questions ensuite.
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('candidature_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidature_answers');
    }
};
