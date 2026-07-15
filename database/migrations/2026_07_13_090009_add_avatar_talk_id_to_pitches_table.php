<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pitches', function (Blueprint $table) {
            // Identifiant du "talk" côté fournisseur (D-ID) le temps du
            // rendu asynchrone — voir PollAvatarRenderJob. Pas de nouvelle
            // table Avatar : la photo source est User::avatar (déjà
            // existant), et le rendu peuple directement Pitch::video_path.
            $table->string('avatar_talk_id')->nullable()->after('is_public');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pitches', function (Blueprint $table) {
            $table->dropColumn('avatar_talk_id');
        });
    }
};
