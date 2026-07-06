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
        Schema::create('interview_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('candidate_name');
            $table->string('candidate_email');
            $table->string('position');
            $table->integer('duration');
            // Ni valeur par défaut ni obligatoire côté DTO NestJS (@IsOptional
            // sans {default:...} sur l'entité) : nullable plutôt qu'un défaut
            // inventé.
            $table->integer('score')->nullable();
            $table->string('status');
            $table->timestamp('start_time');
            $table->string('feedback')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interview_sessions');
    }
};
