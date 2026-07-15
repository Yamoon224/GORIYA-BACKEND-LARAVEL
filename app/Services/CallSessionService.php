<?php

namespace App\Services;

use App\Contracts\VideoCallProviderInterface;
use App\Enums\CallSessionStatus;
use App\Models\CallSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * GORIYA Call — orchestration des sessions de visioconférence (planification,
 * émission de jeton de connexion, clôture) au-dessus de VideoCallProviderInterface
 * (lunion.meet). Pas de liste d'invités/ACL dédiée pour ce MVP : comme le
 * sketch d'origine ("participants json"), rejoindre une session ne demande
 * que de connaître son id — seul l'hôte peut la clôturer.
 */
class CallSessionService
{
    public function __construct(
        private readonly VideoCallProviderInterface $videoCallProvider,
    ) {}

    public function listFor(User $host): Collection
    {
        return CallSession::where('host_id', $host->id)->orderByDesc('created_at')->get();
    }

    public function find(string $id): ?CallSession
    {
        return CallSession::find($id);
    }

    public function schedule(User $host, string $title, ?Carbon $scheduledAt): CallSession
    {
        $room = $this->videoCallProvider->createRoom($title, $scheduledAt?->toIso8601String());

        return CallSession::create([
            'host_id' => $host->id,
            'title' => $title,
            'room_slug' => $room['slug'],
            'room_ref' => $room['id'] ?? null,
            'scheduled_at' => $scheduledAt,
            'status' => CallSessionStatus::SCHEDULED,
        ]);
    }

    /**
     * @return array{token: string, url: string, room: string, identity: string, expiresAt: string}
     */
    public function issueJoinToken(CallSession $session, User $user): array
    {
        if ($session->status === CallSessionStatus::ENDED) {
            abort(400, 'Cette session est déjà terminée');
        }

        $isHost = $session->host_id === $user->id;

        $token = $this->videoCallProvider->issueToken(
            $session->room_slug,
            identity: $user->id,
            name: $user->name,
            grants: $isHost ? ['roomAdmin' => true] : [],
        );

        if ($session->status === CallSessionStatus::SCHEDULED) {
            $session->update(['status' => CallSessionStatus::ACTIVE]);
        }

        return $token;
    }

    public function end(CallSession $session, User $user): CallSession
    {
        if ($session->host_id !== $user->id) {
            abort(403, "Seul l'hôte peut clôturer cette session");
        }

        if ($session->status !== CallSessionStatus::ENDED) {
            $this->videoCallProvider->deleteRoom($session->room_slug);

            $session->update([
                'status' => CallSessionStatus::ENDED,
                'ended_at' => now(),
            ]);
        }

        return $session->fresh();
    }
}
