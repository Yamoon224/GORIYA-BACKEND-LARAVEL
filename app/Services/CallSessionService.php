<?php

namespace App\Services;

use App\Contracts\VideoCallProviderInterface;
use App\Enums\CallSessionStatus;
use App\Models\CallSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * GORIYA Call — orchestration des sessions de visioconférence (planification,
 * émission de jeton de connexion, clôture) au-dessus de VideoCallProviderInterface
 * (lunion.meet). Pas d'ACL dédiée pour ce MVP : rejoindre une session ne
 * demande que de connaître son id — seul l'hôte peut la clôturer. Les invités
 * (`guests`) servent à retrouver la session dans sa liste : un candidat
 * convoqué à un entretien vidéo n'en est pas l'hôte.
 */
class CallSessionService
{
    public function __construct(
        private readonly VideoCallProviderInterface $videoCallProvider,
    ) {}

    /** Sessions dont l'utilisateur est l'hôte ou l'invité. */
    public function listFor(User $user): Collection
    {
        return CallSession::query()
            ->where(fn (Builder $q) => $q
                ->where('host_id', $user->id)
                ->orWhereHas('guests', fn (Builder $guests) => $guests->where('users.id', $user->id)))
            ->with('host')
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(string $id): ?CallSession
    {
        return CallSession::find($id);
    }

    /**
     * @param  list<string>  $guestIds  Utilisateurs invités (candidat convoqué…)
     */
    public function schedule(User $host, string $title, ?CarbonInterface $scheduledAt, array $guestIds = []): CallSession
    {
        $title = Str::limit($title, 250, '');
        $room = $this->videoCallProvider->createRoom($title, $scheduledAt?->toIso8601String());

        $session = CallSession::create([
            'host_id' => $host->id,
            'title' => $title,
            'room_slug' => $room['slug'],
            'room_ref' => $room['id'] ?? null,
            'scheduled_at' => $scheduledAt,
            'status' => CallSessionStatus::SCHEDULED,
        ]);

        $guests = array_values(array_unique(array_filter($guestIds, fn ($id) => $id && $id !== $host->id)));
        if ($guests !== []) {
            $session->guests()->attach($guests);
        }

        return $session;
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

    /**
     * Clôture décidée par l'application (entretien passé, annulé, supprimé) :
     * sans contrôle d'hôte, et sans bloquer l'action métier si le fournisseur
     * ne répond pas — la session est marquée terminée de toute façon.
     */
    public function close(CallSession $session): void
    {
        if ($session->status === CallSessionStatus::ENDED) {
            return;
        }

        try {
            $this->videoCallProvider->deleteRoom($session->room_slug);
        } catch (Throwable $e) {
            Log::warning("GORIYA Meet : fermeture de la salle {$session->room_slug} impossible — {$e->getMessage()}");
        }

        $session->update(['status' => CallSessionStatus::ENDED, 'ended_at' => now()]);
    }
}
