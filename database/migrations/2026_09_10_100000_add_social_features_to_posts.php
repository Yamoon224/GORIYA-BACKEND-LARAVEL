<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fil d'actualité façon LinkedIn : pièces jointes (images ou PDF),
     * commentaires et republications.
     *
     * `content` devient facultatif : un post peut n'être qu'une image, et une
     * republication sans commentaire n'a pas de texte propre.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->text('content')->nullable()->change();
        });

        // Séparé du `change()` ci-dessus : SQLite (tests) reconstruit la table
        // pour modifier une colonne, et ne sait pas le combiner à un ajout de clé.
        Schema::table('posts', function (Blueprint $table) {
            // Post d'origine d'une republication. Le supprimer ne supprime pas
            // les republications : elles affichent « contenu indisponible ».
            $table->foreignUuid('repost_of_id')->nullable()->after('community_id')
                ->constrained('posts')->nullOnDelete();
        });

        Schema::create('post_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();
            // IMAGE ou DOCUMENT (PDF).
            $table->string('type', 20);
            // Chemin relatif sur le disque `public` (ex. "/posts/<uuid>.jpg").
            $table->string('path');
            $table->string('name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['post_id', 'position']);
        });

        Schema::create('post_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();

            $table->index(['post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_comments');
        Schema::dropIfExists('post_attachments');

        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repost_of_id');
        });
    }
};
