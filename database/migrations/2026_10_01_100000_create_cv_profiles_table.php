<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil extrait du CV par l'analyse IA puis validé par le candidat
     * (standard/app/resultat-analyse-cv) : un seul document par utilisateur,
     * stocké en JSON comme le brouillon de CV (table `cvs`).
     */
    public function up(): void
    {
        Schema::create('cv_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cv_profiles');
    }
};
