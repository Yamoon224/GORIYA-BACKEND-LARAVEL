<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Slugs publics des entreprises et des offres : les fiches détail passent de
 * /explorer-entreprises/{uuid} à /explorer-entreprises/{slug}.
 *
 * La colonne est nullable pour laisser passer le backfill, puis remplie pour
 * toutes les lignes existantes — App\Concerns\HasSlug s'occupe des suivantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        Schema::table('job_offers', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('title');
        });

        $this->backfill('companies', 'name');
        $this->backfill('job_offers', 'title');

        Schema::table('companies', function (Blueprint $table) {
            $table->unique('slug');
        });

        Schema::table('job_offers', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });

        Schema::table('job_offers', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }

    /**
     * Slugifie les lignes déjà en base, en dédoublonnant en mémoire (-2, -3…)
     * exactement comme le fera HasSlug ensuite.
     */
    private function backfill(string $table, string $source): void
    {
        $pris = [];

        DB::table($table)->orderBy('id')->select('id', $source)->chunk(200, function ($lignes) use ($table, $source, &$pris) {
            foreach ($lignes as $ligne) {
                $base = Str::slug((string) $ligne->{$source});
                $base = $base === '' ? Str::lower(Str::random(8)) : trim(Str::limit($base, 80, ''), '-');

                $slug = $base;
                $suffixe = 2;
                while (isset($pris[$slug])) {
                    $slug = $base.'-'.$suffixe++;
                }
                $pris[$slug] = true;

                DB::table($table)->where('id', $ligne->id)->update(['slug' => $slug]);
            }
        });
    }
};
