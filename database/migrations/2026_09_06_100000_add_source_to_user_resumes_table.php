<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Origine du CV : « upload » pour un fichier déposé par le candidat,
     * « builder » pour un PDF produit par le créateur de CV. La liste « Mes CV »
     * (standard) les présente différemment, et seul un CV « builder » peut
     * renvoyer vers le formulaire qui l'a produit.
     *
     * Les lignes existantes sont toutes des dépôts : le défaut leur convient.
     */
    public function up(): void
    {
        Schema::table('user_resumes', function (Blueprint $table) {
            $table->string('source', 20)->default('upload')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('user_resumes', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
