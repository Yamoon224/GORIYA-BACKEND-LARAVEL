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
        Schema::create('scoring_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('candidate_name');
            $table->string('candidate_email');
            $table->string('position');
            $table->integer('overall_score');
            // json (pas jsonb) pour rester portable MySQL <-> Postgres — Laravel 'array' cast fonctionne pareil sur les deux.
            $table->json('criteria');
            $table->timestamp('analysis_date');
            $table->string('status');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scoring_results');
    }
};
