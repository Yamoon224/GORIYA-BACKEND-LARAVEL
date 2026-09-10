<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Éditeur de portfolio complet (standard /portfolio/creer) : photo, thème,
     * brouillon / publication et un bloc `details` pour ce qui n'a pas besoin
     * d'être requêté (coordonnées, niveaux de compétences, projets, liens).
     *
     * Les portfolios existants étaient tous visibles : ils passent PUBLISHED.
     */
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->string('theme', 20)->default('default');
            $table->string('status', 20)->default('PUBLISHED');
            // Chemin relatif sur le disque `public` (ex. "/portfolios/<uuid>.jpg").
            $table->string('photo_path')->nullable();
            $table->json('details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn(['theme', 'status', 'photo_path', 'details']);
        });
    }
};
