<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Passe `courses` d'un simple catalogue de formations partenaires à un
     * vrai cours structuré (leçons, instructeur, niveau, langue, sous-titres).
     *
     * `category` et `provider` (texte libre) sont conservés pour ne pas casser
     * les écrans existants ; les catégories texte déjà saisies sont
     * converties en lignes `course_categories` et rattachées via
     * `category_id`.
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('title');
            $table->string('subtitle')->nullable()->after('slug');
            $table->foreignUuid('category_id')->nullable()->after('category')
                ->constrained('course_categories')->nullOnDelete();
            $table->foreignUuid('instructor_id')->nullable()->after('provider')
                ->constrained('instructors')->nullOnDelete();
            $table->string('level', 20)->default('BEGINNER')->after('instructor_id');
            $table->string('language', 5)->default('fr')->after('level');
            $table->json('subtitle_languages')->nullable()->after('language');
            $table->json('learning_outcomes')->nullable()->after('subtitle_languages');
            $table->string('trailer_url')->nullable()->after('thumbnail_path');
            $table->boolean('is_free')->default(false)->after('trailer_url');
            $table->boolean('is_published')->default(false)->after('is_free');
            $table->unsignedInteger('lessons_count')->default(0)->after('is_published');
            $table->unsignedInteger('total_minutes')->default(0)->after('lessons_count');
            $table->timestamp('published_at')->nullable()->after('total_minutes');
        });

        // Les formations existantes étaient déjà visibles : on les garde
        // publiées pour ne pas les faire disparaître du catalogue.
        DB::table('courses')->where('is_active', true)->update(['is_published' => true]);

        $now = now();
        foreach (DB::table('courses')->whereNotNull('category')->distinct()->pluck('category') as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $slug = Str::slug($name) ?: Str::lower(Str::random(8));
            $id = DB::table('course_categories')->where('slug', $slug)->value('id');
            if (! $id) {
                $id = (string) Str::uuid();
                DB::table('course_categories')->insert([
                    'id' => $id, 'name' => $name, 'slug' => $slug,
                    'is_active' => true, 'sort_order' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            DB::table('courses')->where('category', $name)->update(['category_id' => $id]);
        }

        foreach (DB::table('courses')->whereNull('slug')->get(['id', 'title']) as $course) {
            $base = Str::slug((string) $course->title) ?: Str::lower(Str::random(8));
            $slug = $base;
            $suffix = 2;
            while (DB::table('courses')->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$suffix++;
            }
            DB::table('courses')->where('id', $course->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('instructor_id');
            $table->dropUnique(['slug']);
            $table->dropColumn([
                'slug', 'subtitle', 'level', 'language', 'subtitle_languages', 'learning_outcomes',
                'trailer_url', 'is_free', 'is_published', 'lessons_count', 'total_minutes', 'published_at',
            ]);
        });
    }
};
