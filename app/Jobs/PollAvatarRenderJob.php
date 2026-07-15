<?php

namespace App\Jobs;

use App\Contracts\AvatarGenerationServiceInterface;
use App\Enums\PitchFormat;
use App\Enums\PitchStatus;
use App\Models\Pitch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Interroge D-ID jusqu'à ce que le rendu de l'avatar soit prêt (ou échoue),
 * en se redéclenchant lui-même avec un délai — pas de webhook D-ID ici : cet
 * environnement n'a pas d'URL publique stable pour recevoir un callback. À
 * remplacer par le webhook D-ID en production si une URL publique existe
 * (voir DIdAvatarService::createTalk()).
 *
 * $attempt est passé explicitement (pas $this->attempts(), qui ne suit que
 * les retries d'un même job après échec) car chaque tour de polling est un
 * nouveau job dispatché, pas une réexécution du même.
 */
class PollAvatarRenderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_ATTEMPTS = 30; // ~5 minutes à 10s d'intervalle

    private const POLL_DELAY_SECONDS = 10;

    public function __construct(
        public readonly string $pitchId,
        public readonly int $attempt = 1,
    ) {}

    public function handle(AvatarGenerationServiceInterface $avatarService): void
    {
        $pitch = Pitch::find($this->pitchId);

        if (! $pitch || ! $pitch->avatar_talk_id) {
            return;
        }

        $result = $avatarService->getTalkStatus($pitch->avatar_talk_id);

        if ($result['status'] === 'DONE' && $result['resultUrl']) {
            $pitch->update([
                'video_path' => $result['resultUrl'],
                'format' => PitchFormat::VIDEO,
                'status' => PitchStatus::READY,
            ]);

            return;
        }

        if ($result['status'] === 'FAILED') {
            $pitch->update(['status' => PitchStatus::FAILED]);

            return;
        }

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            Log::warning("Avatar render timed out for pitch {$this->pitchId} after ".self::MAX_ATTEMPTS.' attempts');
            $pitch->update(['status' => PitchStatus::FAILED]);

            return;
        }

        self::dispatch($this->pitchId, $this->attempt + 1)->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));
    }
}
