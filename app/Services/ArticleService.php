<?php

namespace App\Services;

use App\Enums\ArticleStatus;
use App\Models\Article;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Blog Goriya (page "À propos" + articles) — CRUD simple, pas de couche
 * Repository dédiée (ressource autonome, pas de logique de filtrage
 * complexe partagée avec un autre contrôleur comme JobOffer/Company).
 */
class ArticleService
{
    public function paginate(int $page, int $limit, bool $publishedOnly = true): LengthAwarePaginator
    {
        $query = Article::query()->orderByDesc('published_at')->orderByDesc('created_at');

        if ($publishedOnly) {
            $query->where('status', ArticleStatus::PUBLISHED);
        }

        return $query->paginate(max(1, $limit), ['*'], 'page', max(1, $page));
    }

    public function findBySlug(string $slug, bool $publishedOnly = true): Article
    {
        $query = Article::where('slug', $slug);

        if ($publishedOnly) {
            $query->where('status', ArticleStatus::PUBLISHED);
        }

        return $query->firstOrFail();
    }

    public function create(array $data, ?UploadedFile $coverImage): Article
    {
        $payload = [
            'title' => $data['title'],
            'slug' => $this->uniqueSlug($data['slug'] ?? $data['title']),
            'excerpt' => $data['excerpt'] ?? null,
            'content' => $data['content'],
            'author_name' => $data['authorName'] ?? null,
            'status' => $data['status'] ?? ArticleStatus::DRAFT->value,
        ];

        if ($payload['status'] === ArticleStatus::PUBLISHED->value) {
            $payload['published_at'] = now();
        }

        if ($coverImage) {
            $payload['cover_image'] = $this->storeCover($coverImage);
        }

        return Article::create($payload);
    }

    public function update(Article $article, array $data, ?UploadedFile $coverImage): Article
    {
        $payload = [];

        foreach (['title' => 'title', 'excerpt' => 'excerpt', 'content' => 'content', 'authorName' => 'author_name'] as $from => $to) {
            if (array_key_exists($from, $data)) {
                $payload[$to] = $data[$from];
            }
        }

        if (array_key_exists('slug', $data) && $data['slug'] !== $article->slug) {
            $payload['slug'] = $this->uniqueSlug($data['slug'], $article->id);
        }

        if (array_key_exists('status', $data)) {
            $payload['status'] = $data['status'];
            if ($data['status'] === ArticleStatus::PUBLISHED->value && ! $article->published_at) {
                $payload['published_at'] = now();
            }
        }

        if ($coverImage) {
            if ($article->cover_image) {
                $this->deleteCover($article->cover_image);
            }
            $payload['cover_image'] = $this->storeCover($coverImage);
        }

        $article->update($payload);

        return $article->fresh();
    }

    public function remove(Article $article): void
    {
        if ($article->cover_image) {
            $this->deleteCover($article->cover_image);
        }

        $article->delete();
    }

    private function uniqueSlug(string $source, ?string $ignoreId = null): string
    {
        $base = Str::slug($source);
        $slug = $base;
        $i = 1;

        while (Article::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function storeCover(UploadedFile $file): string
    {
        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        Storage::disk('public')->putFileAs('articles', $file, $filename);

        return "/articles/{$filename}";
    }

    private function deleteCover(string $path): void
    {
        Storage::disk('public')->delete('articles/'.basename($path));
    }
}
