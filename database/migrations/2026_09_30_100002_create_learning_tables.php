<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parcours apprenant : leçons (vidéo en lien externe — YouTube, Vimeo ou
     * fichier MP4 hébergé ailleurs, rien n'est stocké sur Hostinger),
     * progression par leçon, favoris (« Listes »), projets soumis
     * (« Mes projets ») et pass découverte de 7 jours.
     */
    public function up(): void
    {
        Schema::create('course_lessons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('section')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('video_url', 500)->nullable();
            $table->string('video_provider', 20)->default('FILE');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_free_preview')->default(false);
            // [{lang: 'en', label: 'English', url: 'https://…/en.vtt'}]
            $table->json('subtitles')->nullable();
            // [{title: 'Support PDF', url: 'https://…'}]
            $table->json('resources')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'position']);
        });

        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);
            $table->index(['user_id', 'course_id']);
        });

        Schema::create('course_bookmarks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('courses')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });

        Schema::create('course_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('status', 20)->default('SUBMITTED');
            $table->text('feedback')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // Un seul pass découverte par utilisateur, à vie (unique user_id).
        Schema::create('course_passes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_passes');
        Schema::dropIfExists('course_projects');
        Schema::dropIfExists('course_bookmarks');
        Schema::dropIfExists('lesson_progress');
        Schema::dropIfExists('course_lessons');
    }
};
