<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entretiens vidéo sur GORIYA Meet (lunion.meet) plutôt qu'un lien externe.
 *
 *  - `call_session_guests` : utilisateurs invités à une session sans en être
 *    l'hôte (le candidat convoqué). Distinct de `call_participants`, qui trace
 *    les connexions réelles remontées par les webhooks lunion.meet.
 *  - `recruitment_interviews.call_session_id` remplace `meeting_url`. Pas de
 *    clé étrangère, comme `hr_requests.payslip_id` : l'ajout d'une contrainte
 *    sur une table existante reste fragile sous SQLite, et RecruitmentService
 *    gère la fermeture des salles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_session_guests', function (Blueprint $table) {
            $table->foreignUuid('call_session_id')->constrained('call_sessions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->primary(['call_session_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('recruitment_interviews', function (Blueprint $table) {
            $table->uuid('call_session_id')->nullable()->index();
        });

        Schema::table('recruitment_interviews', function (Blueprint $table) {
            $table->dropColumn('meeting_url');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_interviews', function (Blueprint $table) {
            $table->string('meeting_url', 500)->nullable();
        });

        Schema::table('recruitment_interviews', function (Blueprint $table) {
            $table->dropIndex(['call_session_id']);
            $table->dropColumn('call_session_id');
        });

        Schema::dropIfExists('call_session_guests');
    }
};
