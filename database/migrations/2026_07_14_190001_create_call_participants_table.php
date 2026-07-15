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
        Schema::create('call_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('call_session_id')->constrained('call_sessions')->cascadeOnDelete();
            // "identity" est la valeur transmise à lunion.meet lors de
            // l'émission du token (voir LunionMeetService::issueToken()) —
            // ici l'id GORIYA de l'utilisateur, pas forcément un fk strict
            // (les webhooks peuvent référencer une identity déjà expirée/
            // supprimée côté GORIYA, ce champ reste donc une simple string).
            $table->string('identity');
            $table->string('name')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_participants');
    }
};
