<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserResume;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bibliothèque de CV d'un candidat : plusieurs fichiers, dont un marqué par
 * défaut, parmi lesquels il choisit à chaque candidature.
 */
class UserResumeService
{
    /**
     * PDF et Word uniquement — ce que les recruteurs peuvent réellement ouvrir,
     * et ce que l'analyse de CV sait déjà traiter.
     *
     * @var list<string>
     */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    /** Garde-fou contre une bibliothèque qui enfle sans limite. */
    public const MAX_PER_USER = 10;

    /**
     * @return Collection<int, UserResume>
     */
    public function listForUser(User $user): Collection
    {
        return UserResume::where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
    }

    public function findForUser(User $user, string $id): ?UserResume
    {
        return UserResume::where('user_id', $user->id)->find($id);
    }

    public function store(User $user, UploadedFile $file, ?string $name = null): UserResume
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            abort(400, 'Format non supporté : joignez un CV au format PDF ou Word.');
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            abort(400, 'Le CV ne doit pas dépasser 5 Mo.');
        }

        if (UserResume::where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
            abort(400, 'Vous avez atteint la limite de '.self::MAX_PER_USER.' CV. Supprimez-en un avant d\'en ajouter un autre.');
        }

        $extension = $file->getClientOriginalExtension() ?: 'pdf';
        $filename = Str::uuid().'.'.$extension;
        Storage::disk('public')->putFileAs('resumes', $file, $filename);

        // Le premier CV déposé devient le CV par défaut : sans ça, le wizard
        // de candidature n'aurait rien de présélectionné.
        $estPremier = ! UserResume::where('user_id', $user->id)->exists();

        return UserResume::create([
            'user_id' => $user->id,
            'name' => trim((string) $name) !== '' ? trim((string) $name) : $file->getClientOriginalName(),
            'path' => "/resumes/{$filename}",
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'is_default' => $estPremier,
        ]);
    }

    /**
     * @param  array{name?: string, isDefault?: bool}  $data
     */
    public function update(UserResume $resume, array $data): UserResume
    {
        if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
            $resume->name = trim((string) $data['name']);
        }

        if (! empty($data['isDefault'])) {
            $this->markDefault($resume);
        }

        $resume->save();

        return $resume->refresh();
    }

    public function delete(UserResume $resume): void
    {
        $etaitDefaut = $resume->is_default;
        $userId = $resume->user_id;

        Storage::disk('public')->delete('resumes/'.basename($resume->path));
        $resume->delete();

        // Ne jamais laisser la bibliothèque sans CV par défaut : le suivant le
        // devient, sinon le wizard rouvrirait sans sélection.
        if ($etaitDefaut) {
            $suivant = UserResume::where('user_id', $userId)->orderByDesc('created_at')->first();
            $suivant?->forceFill(['is_default' => true])->save();
        }
    }

    /**
     * Un seul CV par défaut par utilisateur — les autres sont démarqués dans
     * la même transaction pour qu'aucune lecture intermédiaire n'en voie deux.
     */
    private function markDefault(UserResume $resume): void
    {
        DB::transaction(function () use ($resume) {
            UserResume::where('user_id', $resume->user_id)
                ->where('id', '!=', $resume->id)
                ->update(['is_default' => false]);
            $resume->is_default = true;
        });
    }
}
