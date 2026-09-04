<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Identifiant lisible et stable, utilisé dans les URLs publiques à la place de
 * l'uuid (/explorer-entreprises/{slug}, /explorer-emplois/{slug}).
 *
 * Le slug est calculé une seule fois, à la création : renommer une entreprise
 * ou retitrer une offre ne le régénère pas, sinon tous les liens déjà partagés
 * (et indexés) casseraient. Les routes continuent d'accepter l'uuid — voir
 * scopeWhereKeyOrSlug — pour ne pas invalider les liens antérieurs au slug.
 */
trait HasSlug
{
    public static function bootHasSlug(): void
    {
        static::saving(function ($model) {
            if (blank($model->slug)) {
                $model->slug = $model->generateSlug();
            }
        });
    }

    /**
     * Colonne dont dérive le slug (surchargeable par modèle).
     */
    protected function slugSource(): string
    {
        return 'name';
    }

    /**
     * Slug unique dérivé de la colonne source, suffixé -2, -3… en cas de
     * collision (deux entreprises homonymes, deux offres au même intitulé).
     */
    public function generateSlug(): string
    {
        $base = Str::slug((string) $this->{$this->slugSource()});
        // Titre entièrement non latin (ou vide) : Str::slug renvoie '' — on
        // retombe sur un identifiant court plutôt que sur un slug vide.
        $base = $base === '' ? Str::lower(Str::random(8)) : Str::limit($base, 80, '');
        $base = trim($base, '-');

        $slug = $base;
        $suffixe = 2;
        while ($this->slugExists($slug)) {
            $slug = $base.'-'.$suffixe++;
        }

        return $slug;
    }

    private function slugExists(string $slug): bool
    {
        $query = static::query()->where('slug', $slug);

        if ($this->getKey()) {
            $query->whereKeyNot($this->getKey());
        }

        return $query->exists();
    }

    /**
     * Résout indifféremment un uuid ou un slug.
     *
     * Le test isUuid est nécessaire : comparer la clé primaire (type uuid côté
     * Postgres) à une chaîne quelconque lèverait une erreur SQL.
     */
    public function scopeWhereKeyOrSlug(Builder $query, string $key): Builder
    {
        return Str::isUuid($key)
            ? $query->whereKey($key)
            : $query->where('slug', $key);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
