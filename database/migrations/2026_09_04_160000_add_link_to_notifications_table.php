<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lien profond porté par la notification. Sans lui, un clic sur une
 * notification ne pouvait que la marquer comme lue : le front n'avait aucun
 * moyen de savoir *quelle* conversation ou *quelle* candidature était
 * concernée. Le chemin est relatif (« /messages?conversation=… ») et donc
 * valable aussi bien dans standard/ que dans entreprise/.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('link')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('link');
        });
    }
};
