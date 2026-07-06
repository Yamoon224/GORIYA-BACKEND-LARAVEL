<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Implémentation Eloquent du CRUD générique. Chaque repository concret
 * n'a qu'à déclarer son modèle et ajouter ses finders/paginate spécifiques
 * — aucun boilerplate find/all/create/update/delete à réécrire.
 */
abstract class BaseRepository implements RepositoryInterface
{
    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    public function find(string $id): ?Model
    {
        return $this->model()::find($id);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model()::findOrFail($id);
    }

    public function all(): Collection
    {
        return $this->model()::all();
    }

    public function count(): int
    {
        return $this->model()::count();
    }

    public function create(array $data): Model
    {
        return $this->model()::create($data);
    }

    public function update(Model $model, array $data): Model
    {
        $model->fill($data);
        $model->save();

        return $model;
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }
}
