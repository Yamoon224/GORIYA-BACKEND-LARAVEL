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
        Schema::create('matching_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('candidate_name');
            $table->string('candidate_email');
            $table->string('position');
            $table->string('company');
            $table->integer('matching_score');
            $table->string('status')->default('NOUVEAU');
            $table->timestamp('match_date');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matching_results');
    }
};
