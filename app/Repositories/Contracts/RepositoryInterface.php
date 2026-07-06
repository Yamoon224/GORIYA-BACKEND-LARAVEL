<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Contrat générique partagé par tous les repositories d'entité — CRUD de
 * base uniquement. Les besoins spécifiques (paginate/filtres, finders
 * métier) vivent sur l'interface de chaque entité, qui étend celle-ci.
 */
interface RepositoryInterface
{
    public function find(string $id): ?Model;

    public function findOrFail(string $id): Model;

    /**
     * @return Collection<int, Model>
     */
    public function all(): Collection;

    public function count(): int;

    public function create(array $data): Model;

    public function update(Model $model, array $data): Model;

    public function delete(Model $model): void;
}
