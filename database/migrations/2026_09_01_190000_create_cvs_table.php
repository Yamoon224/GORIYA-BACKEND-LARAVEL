<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brouillon du créateur de CV (standard/app/(protected)/creer-cv) : un seul
     * document par utilisateur, contenu libre stocké en JSON pour ne pas
     * dépendre du schéma du formulaire (qui évolue côté front).
     */
    public function up(): void
    {
        Schema::create('cvs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('data')->nullable();
            $table->unsignedTinyInteger('step')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cvs');
    }
};
