<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CV téléversés par un candidat. Plusieurs par utilisateur : à la
     * candidature il choisit celui qu'il joint, comme sur LinkedIn.
     *
     * Distinct de `cvs`, qui stocke le brouillon du *créateur* de CV (un seul
     * par utilisateur, contenu de formulaire) : ici ce sont des fichiers.
     */
    public function up(): void
    {
        Schema::create('user_resumes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            // Chemin relatif sur le disque `public` (ex. "/resumes/<uuid>.pdf").
            $table->string('path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_resumes');
    }
};
