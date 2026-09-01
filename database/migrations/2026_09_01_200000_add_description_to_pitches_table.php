<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Description libre de l'idée du pitch saisie par l'utilisateur : sert de
     * contexte à la génération IA (voir PitchService::create /
     * AnthropicPitchService::generate) et reste consultable ensuite.
     */
    public function up(): void
    {
        Schema::table('pitches', function (Blueprint $table) {
            $table->text('description')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('pitches', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
