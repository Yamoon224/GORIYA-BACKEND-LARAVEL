<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pièce jointe d'un message.
 *
 * `content` reste NOT NULL : un message qui n'est qu'un fichier est stocké
 * avec un contenu vide plutôt qu'avec un NULL, ce qui évite d'avoir à relâcher
 * la contrainte (impossible sans doctrine/dbal, et sans reconstruction de
 * table sous SQLite, la base des tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('content');
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime')->nullable()->after('attachment_name');
            $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size']);
        });
    }
};
