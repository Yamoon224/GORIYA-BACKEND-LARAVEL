<?php

namespace App\Services;

use App\Models\Cv;
use App\Models\User;

/**
 * Persistance du brouillon "Créer CV" (un par utilisateur). Aucune logique
 * métier : la génération/analyse du CV vit ailleurs (CvAnalysisController),
 * ici on ne fait que stocker/relire ce que le formulaire envoie.
 */
class CvService
{
    /**
     * Champs scalaires du formulaire réellement persistés — tout le reste est
     * ignoré pour éviter de stocker un blob arbitraire côté client.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'nom',
        'email',
        'indicatif',
        'telephone',
        'adresse',
        'profil',
    ];

    /**
     * Listes répétables du formulaire (étapes Expérience / Formation /
     * Compétences) : clé => sous-clés autorisées de chaque entrée.
     *
     * @var array<string, list<string>>
     */
    public const LISTS = [
        'experiences' => ['entreprise', 'poste', 'dateDebut', 'dateFin', 'enCours', 'description'],
        'formations' => ['etablissement', 'diplome', 'dateDebut', 'dateFin'],
        'competences' => ['nom', 'niveau'],
    ];

    /**
     * Sous-clés des listes à conserver en booléen plutôt qu'en chaîne.
     *
     * @var list<string>
     */
    private const BOOLEAN_SUBKEYS = ['enCours'];

    /**
     * Lecture seule : renvoie un brouillon non persisté quand l'utilisateur
     * n'en a pas encore. Créer la ligne à la lecture remplirait la table dès
     * qu'un écran consulte l'état du brouillon (bibliothèque, tableau de bord).
     */
    public function findForUser(User $user): Cv
    {
        return Cv::firstWhere('user_id', $user->id)
            ?? new Cv(['user_id' => $user->id, 'data' => null, 'step' => 0]);
    }

    /**
     * @param  array{data?: array<string, mixed>|null, step?: int}  $payload
     */
    public function saveForUser(User $user, array $payload): Cv
    {
        $cv = Cv::firstOrNew(['user_id' => $user->id]);

        if (array_key_exists('data', $payload)) {
            $cv->data = $payload['data'] === null ? null : $this->sanitize($payload['data']);
        }

        if (array_key_exists('step', $payload)) {
            $cv->step = $payload['step'];
        }

        $cv->save();

        return $cv->refresh();
    }

    public function deleteForUser(User $user): void
    {
        Cv::where('user_id', $user->id)->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        $clean = collect($data)->only(self::FIELDS)->map(fn ($v) => is_string($v) ? $v : (string) $v)->all();

        foreach (self::LISTS as $key => $subKeys) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $clean[$key] = collect(is_array($data[$key]) ? $data[$key] : [])
                ->filter(fn ($row) => is_array($row))
                ->map(fn (array $row) => collect($row)
                    ->only($subKeys)
                    ->map(fn ($v, $k) => in_array($k, self::BOOLEAN_SUBKEYS, true)
                        ? (bool) $v
                        : (is_string($v) ? $v : (string) $v))
                    ->all())
                ->values()
                ->all();
        }

        return $clean;
    }
}
