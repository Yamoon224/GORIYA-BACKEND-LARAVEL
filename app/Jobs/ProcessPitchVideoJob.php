<?php

namespace App\Jobs;

use App\Contracts\PitchAiServiceInterface;
use App\Enums\PitchStatus;
use App\Models\Pitch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Premier Job de la plateforme (QUEUE_CONNECTION=database — voir
 * config/queue.php, table `jobs` déjà migrée mais jusqu'ici inutilisée).
 *
 * Score le script du pitch en arrière-plan après l'upload vidéo, pour ne pas
 * bloquer la requête HTTP sur un appel Claude. MVP volontaire : on rescore le
 * texte du script (déjà généré à la création du pitch), pas le contenu réel
 * de la vidéo — la transcription/analyse vidéo est un travail futur (voir
 * Studio IA / Avatar en V2B dans le plan de roadmap).
 */
class ProcessPitchVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $pitchId) {}

    public function handle(PitchAiServiceInterface $pitchAi): void
    {
        $pitch = Pitch::find($this->pitchId);

        if (! $pitch) {
            return;
        }

        try {
            $score = $pitchAi->score($pitch->content ?? '');
            $pitch->update(['score' => $score, 'status' => PitchStatus::READY]);
        } catch (Throwable $e) {
            Log::error('Pitch video processing failed: '.$e->getMessage());
            $pitch->update(['status' => PitchStatus::FAILED]);
        }
    }
}
