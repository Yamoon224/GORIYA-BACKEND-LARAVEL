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
     * Clés du formulaire réellement persistées — tout le reste est ignoré
     * pour éviter de stocker un blob arbitraire côté client.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'nom',
        'email',
        'telephone',
        'adresse',
        'profil',
        'competences',
        'experience',
        'formation',
        'langues',
    ];

    public function getOrCreateForUser(User $user): Cv
    {
        return Cv::firstOrCreate(['user_id' => $user->id], ['data' => null, 'step' => 0]);
    }

    /**
     * @param  array{data?: array<string, mixed>|null, step?: int}  $payload
     */
    public function saveForUser(User $user, array $payload): Cv
    {
        $cv = Cv::firstOrNew(['user_id' => $user->id]);

        if (array_key_exists('data', $payload)) {
            $cv->data = $payload['data'] === null
                ? null
                : collect($payload['data'])->only(self::FIELDS)->map(fn ($v) => is_string($v) ? $v : (string) $v)->toArray();
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
}
