<?php

namespace App\Services;

use App\Models\CvProfile;
use App\Models\User;

/**
 * Persistance du profil extrait du CV (un par utilisateur). Aucune logique
 * métier : l'extraction est faite par l'analyse IA côté standard, ici on ne
 * fait que stocker/relire ce que le candidat a validé.
 */
class CvProfileService
{
    /**
     * Champs scalaires persistés — tout le reste est ignoré pour éviter de
     * stocker un blob arbitraire côté client.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'firstName',
        'fullName',
        'title',
        'phone',
        'email',
        'birthDate',
        'gender',
        'desiredJob',
        'linkedin',
        'website',
    ];

    /**
     * Listes de chaînes simples.
     *
     * @var list<string>
     */
    public const STRING_LISTS = ['skills', 'hobbies'];

    /**
     * Listes d'objets : clé => sous-clés scalaires autorisées. Les sous-clés
     * de NESTED_STRING_LISTS sont, elles, des listes de chaînes.
     *
     * @var array<string, list<string>>
     */
    public const LISTS = [
        'experiences' => ['position', 'company', 'location', 'date'],
        'educations' => ['title', 'level', 'school', 'date'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const NESTED_STRING_LISTS = [
        'experiences' => ['skills', 'missions'],
        'educations' => ['skills'],
    ];

    /**
     * Lecture seule : renvoie un profil non persisté quand l'utilisateur n'en
     * a pas encore (même choix que CvService::findForUser).
     */
    public function findForUser(User $user): CvProfile
    {
        return CvProfile::firstWhere('user_id', $user->id)
            ?? new CvProfile(['user_id' => $user->id, 'data' => null]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function saveForUser(User $user, ?array $data): CvProfile
    {
        $profile = CvProfile::firstOrNew(['user_id' => $user->id]);
        $profile->data = $data === null ? null : $this->sanitize($data);
        $profile->save();

        return $profile->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        $clean = collect($data)->only(self::FIELDS)->map(fn ($v) => $this->toString($v))->all();

        foreach (self::STRING_LISTS as $key) {
            if (array_key_exists($key, $data)) {
                $clean[$key] = $this->toStringList($data[$key]);
            }
        }

        foreach (self::LISTS as $key => $subKeys) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $clean[$key] = collect(is_array($data[$key]) ? $data[$key] : [])
                ->filter(fn ($row) => is_array($row))
                ->map(function (array $row) use ($key, $subKeys) {
                    $entry = collect($row)->only($subKeys)->map(fn ($v) => $this->toString($v))->all();

                    foreach (self::NESTED_STRING_LISTS[$key] as $listKey) {
                        $entry[$listKey] = $this->toStringList($row[$listKey] ?? []);
                    }

                    return $entry;
                })
                ->values()
                ->all();
        }

        return $clean;
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @return list<string>
     */
    private function toStringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn ($v) => $this->toString($v))
            ->filter(fn (string $v) => $v !== '')
            ->values()
            ->all();
    }
}
