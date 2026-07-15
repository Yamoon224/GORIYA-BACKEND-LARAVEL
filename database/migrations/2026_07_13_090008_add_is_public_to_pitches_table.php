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
            // Opt-in explicite requis pour qu'un pitch apparaisse sur le
            // Profil Public GORIYA — un pitch créé pour une candidature
            // précise n'est pas automatiquement partageable publiquement.
            $table->boolean('is_public')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pitches', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
