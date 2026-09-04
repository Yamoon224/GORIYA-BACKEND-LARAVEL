<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Détails saisis dans le wizard de candidature : coordonnées confirmées à
     * l'étape 1, CV choisi à l'étape 3, lettre de motivation facultative.
     *
     * Les coordonnées sont recopiées (et non lues depuis `users`) : elles
     * figent ce que le candidat a communiqué à cette entreprise-là, et
     * restent lisibles s'il modifie ensuite son profil.
     */
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table) {
            $table->string('candidate_phone', 40)->nullable()->after('candidate_email');
            $table->string('candidate_location')->nullable()->after('candidate_phone');
            $table->text('cover_letter')->nullable()->after('candidate_location');
            // nullOnDelete : supprimer un CV de sa bibliothèque ne doit pas
            // effacer les candidatures déjà envoyées avec.
            $table->foreignUuid('resume_id')->nullable()->after('cover_letter')
                ->constrained('user_resumes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('candidatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resume_id');
            $table->dropColumn(['candidate_phone', 'candidate_location', 'cover_letter']);
        });
    }
};
