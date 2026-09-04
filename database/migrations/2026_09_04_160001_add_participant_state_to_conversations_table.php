<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Épinglage et suppression d'une conversation, côté *participant*.
 *
 * Une conversation est partagée par deux personnes : que l'entreprise la
 * supprime de sa liste ne doit rien effacer chez le candidat, ni détruire
 * l'historique (la conversation est ré-ouverte à la candidature suivante par
 * MessagingService::findOrCreateForCandidature). D'où deux listes d'ids
 * utilisateur plutôt qu'un booléen ou un delete réel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->json('starred_by')->nullable()->after('last_message_at');
            $table->json('deleted_by')->nullable()->after('starred_by');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['starred_by', 'deleted_by']);
        });
    }
};
