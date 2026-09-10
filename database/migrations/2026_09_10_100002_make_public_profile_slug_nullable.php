<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le slug devient l'URL personnalisée, facultative, du profil public :
     * sans elle, le profil s'ouvre par l'uuid de l'utilisateur (/p/{uuid}),
     * comme l'identifiant par défaut d'un profil LinkedIn.
     *
     * Les slugs déjà générés sont conservés : les liens partagés restent valides.
     * L'index unique tolère plusieurs NULL (MySQL comme SQLite).
     */
    public function up(): void
    {
        Schema::table('public_profiles', function (Blueprint $table) {
            $table->string('slug')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('public_profiles', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->change();
        });
    }
};
