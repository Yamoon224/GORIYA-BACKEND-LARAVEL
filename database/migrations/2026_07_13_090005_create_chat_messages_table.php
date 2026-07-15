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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            // Précision microseconde (pas la précision seconde par défaut
            // ailleurs dans ce schéma) : dans sendMessage(), le message
            // utilisateur et la réponse IA sont créés dans la même requête,
            // à quelques millisecondes d'écart — sans ça, ChatThread::
            // messages() (orderBy created_at) peut les départager dans le
            // désordre et envoyer un historique incohérent à Claude.
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
