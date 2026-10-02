<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitations par email à une session GORIYA Meet : la liste des adresses
     * conviées (membres ou non) et le message de l'organisateur.
     */
    public function up(): void
    {
        Schema::table('call_sessions', function (Blueprint $table) {
            $table->json('invitees')->nullable()->after('scheduled_at');
            $table->text('description')->nullable()->after('invitees');
        });
    }

    public function down(): void
    {
        Schema::table('call_sessions', function (Blueprint $table) {
            $table->dropColumn(['invitees', 'description']);
        });
    }
};
