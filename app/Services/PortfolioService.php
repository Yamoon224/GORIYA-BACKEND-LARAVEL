<?php

namespace App\Services;

use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\Portfolio;
use App\Repositories\Contracts\PortfolioRepositoryInterface;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mirroir de backend/src/portfolios/portfolios.service.ts, étendu pour
 * l'éditeur complet (photo, thème, brouillon / publication, détails).
 */
class PortfolioService
{
    use HandlesUniqueViolations, MapsFieldsToColumns;

    /** Extension enregistrée par type MIME : jamais celle fournie par le client. */
    public const PHOTO_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly PortfolioRepositoryInterface $portfolioRepository) {}

    /*
    |----------------------------------------------------------------------
    | CREATE — pas de vérification d'existence de userId côté NestJS : on
    | laisse la contrainte FK de la DB faire foi (parité volontaire).
    |----------------------------------------------------------------------
    */
    public function create(array $data): Portfolio
    {
        $payload = [
            'title' => $data['title'],
            // Un brouillon peut être enregistré avant d'avoir sa description.
            'description' => $data['description'] ?? '',
            'skills' => $data['skills'] ?? [],
            'created_date' => $data['createdDate'] ?? now(),
            'user_id' => $data['userId'],
            'theme' => $data['theme'] ?? 'default',
            // Défaut PUBLISHED : les écrans antérieurs à l'éditeur publiaient
            // directement, ils continuent de le faire.
            'status' => $data['status'] ?? Portfolio::STATUS_PUBLISHED,
            'photo_path' => $data['photo'] ?? null,
            'details' => $data['details'] ?? null,
        ];

        foreach (['views', 'downloads', 'likes'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        try {
            $portfolio = $this->portfolioRepository->create($payload);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        return $portfolio->fresh('user');
    }

    public function update(Portfolio $portfolio, array $data): Portfolio
    {
        $mapped = [];

        if (array_key_exists('userId', $data)) {
            $mapped['user_id'] = $data['userId'];
        }

        $mapped += $this->mapFields($data, [
            'title' => 'title',
            'description' => 'description',
            'skills' => 'skills',
            'views' => 'views',
            'downloads' => 'downloads',
            'likes' => 'likes',
            'createdDate' => 'created_date',
            'theme' => 'theme',
            'status' => 'status',
            'photo' => 'photo_path',
            'details' => 'details',
        ]);

        $anciennePhoto = $portfolio->photo_path;

        try {
            $this->portfolioRepository->update($portfolio, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        // La photo remplacée n'est plus référencée : on libère le fichier.
        if (array_key_exists('photo_path', $mapped) && $anciennePhoto && $anciennePhoto !== $mapped['photo_path']) {
            $this->deletePhoto($anciennePhoto);
        }

        return $portfolio->fresh('user');
    }

    /**
     * Enregistre la photo d'un portfolio et renvoie son chemin, à transmettre
     * ensuite dans `photo` à la création ou à la mise à jour.
     */
    public function storePhoto(UploadedFile $file): string
    {
        $extension = self::PHOTO_MIME_TYPES[(string) $file->getMimeType()] ?? null;
        if ($extension === null) {
            abort(400, 'Format non supporté : choisissez une image JPG, PNG ou WebP.');
        }

        $filename = Str::uuid().'.'.$extension;
        Storage::disk('public')->putFileAs('portfolios', $file, $filename);

        return "/portfolios/{$filename}";
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->portfolioRepository->paginate($page, $limit, $filters);
    }

    public function remove(Portfolio $portfolio): void
    {
        if ($portfolio->photo_path) {
            $this->deletePhoto($portfolio->photo_path);
        }

        $this->portfolioRepository->delete($portfolio);
    }

    private function deletePhoto(string $path): void
    {
        Storage::disk('public')->delete('portfolios/'.basename($path));
    }
}
