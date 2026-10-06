<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Mission et valeurs » de la fiche entreprise — jusqu'ici un champ texte
 * libre côté front (entreprise/app/(protected)/profil/content.tsx) qui
 * n'était même pas persisté (il réutilisait par erreur `about`). Devient une
 * liste de valeurs choisies parmi un référentiel fixe (voir COMPANY_VALUES
 * côté entreprise), stockée comme `social_links`/`gallery`.
 *
 * Nommée `company_values`, pas `values` : mot réservé SQL (clause INSERT
 * ... VALUES), à éviter comme nom de colonne même si Eloquent l'échapperait
 * correctement dans les requêtes générées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('company_values')->nullable()->after('social_links');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('company_values');
        });
    }
};
