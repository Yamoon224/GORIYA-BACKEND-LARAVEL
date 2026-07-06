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
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('sector');
            $table->string('logo')->nullable();
            $table->string('cover_image')->nullable();
            $table->text('about')->nullable();
            $table->string('website')->nullable();
            $table->date('creation_date')->nullable();
            $table->date('partnership_date');
            $table->string('company_size')->nullable();
            $table->string('country')->nullable();
            $table->string('headquarters')->nullable();
            $table->string('location')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('ACTIVE');
            // JSON (pas jsonb/text[] Postgres) pour rester portable MySQL <-> Postgres — voir cast 'array' sur le modèle.
            $table->json('social_links')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
