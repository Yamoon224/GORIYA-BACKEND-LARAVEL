<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module Formation : catégories métiers (Développeur web, Data scientist,
     * Business developer, Rédaction web…) et instructeurs — les
     * « professionnels nationaux » qui donnent les cours.
     */
    public function up(): void
    {
        Schema::create('course_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon', 60)->nullable();
            $table->string('cover_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('instructors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('headline')->nullable();
            $table->text('bio')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('country', 80)->nullable();
            $table->string('linkedin_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructors');
        Schema::dropIfExists('course_categories');
    }
};
