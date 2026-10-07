<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le bilan d'une simulation d'entretien (synthèse rédigée par l'IA, jusqu'à
 * 800 caractères) ne tenait pas dans une colonne de 255 caractères : son
 * enregistrement échouait en fin de simulation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table) {
            $table->text('feedback')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table) {
            $table->string('feedback')->nullable()->change();
        });
    }
};
