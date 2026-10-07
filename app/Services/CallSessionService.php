<?php

namespace App\Services;

use App\Contracts\VideoCallProviderInterface;
use App\Enums\CallSessionStatus;
use App\Mail\CallInvitationMail;
use App\Models\CallSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

use function Illuminate\Support\defer;

/**
 * GORIYA Call — orchestration des sessions de visioconférence (planification,
 * émission de jeton de connexion, clôture) au-dessus de VideoCallProviderInterface
 * (lunion.meet). Pas d'ACL dédiée pour ce MVP : rejoindre une session ne
 * demande que de connaître son id — seul l'hôte peut la clôturer. Les invités
 * (`guests`) servent à retrouver la session dans sa liste : un candidat
 * convoqué à un entretien vidéo n'en est pas l'hôte.
 *
 * Une session dont l'heure est passée se termine d'elle-même : à la lecture
 * (liste, détail, connexion) et par la commande planifiée `calls:close-expired`
 * — voir CallSession::expiresAt().
 */
class CallSessionService
{
    public function __construct(
        private readonly VideoCallProviderInterface $videoCallProvider,
    ) {}

    /** Sessions dont l'utilisateur est l'hôte ou l'invité. */
    public function listFor(User $user): Collection
    {
        $this->closeExpired($user);

        return CallSession::query()
            ->where(fn (Builder $q) => $this->visibleTo($q, $user))
            ->with('host')
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(string $id): ?CallSession
    {
        $session = CallSession::find($id);
        if ($session?->isOverdue()) {
            $this->expire($session);
        }

        return $session;
    }

    /**
     * Termine les sessions dont l'heure est passée — celles de l'utilisateur,
     * ou toutes (commande planifiée). Retourne le nombre de sessions closes.
     */
    public function closeExpired(?User $user = null): int
    {
        // Filtre large en SQL (aucune session n'expire avant la marge), règle
        // exacte en PHP : elle dépend de la durée de l'entretien associé.
        $limit = now()->subMinutes(CallSession::GRACE_MINUTES);

        return $this->expireOverdue(
            CallSession::query()
                ->where('status', '!=', CallSessionStatus::ENDED->value)
                ->where(fn (Builder $q) => $q
                    ->where('scheduled_at', '<=', $limit)
                    ->orWhere(fn (Builder $instant) => $instant->whereNull('scheduled_at')->where('created_at', '<=', $limit)))
                ->when($user, fn (Builder $q) => $q->where(fn (Builder $mine) => $this->visibleTo($mine, $user)))
                ->with('interview')
                ->get()
        );
    }

    /**
     * @param  iterable<CallSession>  $sessions
     */
    public function expireOverdue(iterable $sessions): int
    {
        $closed = 0;
        foreach ($sessions as $session) {
            if ($session->isOverdue()) {
                $this->expire($session);
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * @param  list<string>  $guestIds  Utilisateurs invités (candidat convoqué…)
     * @param  list<string>  $invitees  Adresses email à inviter (membres ou non)
     */
    public function schedule(User $host, string $title, ?CarbonInterface $scheduledAt, array $guestIds = [], array $invitees = [], ?string $description = null): CallSession
    {
        $invitees = collect($invitees)
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter(fn (string $email) => $email !== '' && $email !== mb_strtolower($host->email))
            ->unique()
            ->values()
            ->all();
        $description = trim((string) $description) ?: null;

        $title = Str::limit($title, 250, '');
        $room = $this->videoCallProvider->createRoom($title, $scheduledAt?->toIso8601String());

        $session = CallSession::create([
            'host_id' => $host->id,
            'title' => $title,
            'room_slug' => $room['slug'],
            'room_ref' => $room['id'] ?? null,
            'scheduled_at' => $scheduledAt,
            'status' => CallSessionStatus::SCHEDULED,
            // Clés posées seulement si renseignées : un appel sans invité
            // fonctionne ainsi même avant la migration `invitees`.
            ...($invitees ? ['invitees' => $invitees] : []),
            ...($description ? ['description' => $description] : []),
        ]);

        // Un invité déjà membre retrouve aussi l'appel dans sa liste GORIYA Meet.
        $members = $invitees ? User::whereIn('email', $invitees)->get()->keyBy(fn (User $u) => mb_strtolower($u->email)) : collect();
        $guestIds = [...$guestIds, ...$members->pluck('id')->all()];

        $guests = array_values(array_unique(array_filter($guestIds, fn ($id) => $id && $id !== $host->id)));
        if ($guests !== []) {
            $session->guests()->attach($guests);
        }

        // Envoi immédiat mais après la réponse HTTP : l'organisateur n'attend
        // pas le serveur SMTP. Adresse par adresse — une adresse en échec ne
        // bloque pas les autres.
        if ($invitees !== []) {
            $hostName = $host->name;
            $names = $members->map(fn (User $member) => $member->name)->all();
            defer(function () use ($session, $invitees, $hostName, $names) {
                foreach ($invitees as $email) {
                    try {
                        Mail::to($email)->send(new CallInvitationMail($session, $hostName, $names[$email] ?? null));
                    } catch (Throwable $e) {
                        Log::warning("GORIYA Meet : invitation à {$email} non envoyée — {$e->getMessage()}");
                    }
                }
            });
        }

        return $session;
    }

    /**
     * @return array{token: string, url: string, room: string, identity: string, expiresAt: string}
     */
    public function issueJoinToken(CallSession $session, User $user): array
    {
        if ($session->isOverdue()) {
            $this->expire($session);
        }
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

        // Chaque connexion repousse l'échéance de la session (updated_at).
        if ($session->status === CallSessionStatus::SCHEDULED) {
            $session->update(['status' => CallSessionStatus::ACTIVE]);
        } else {
            $session->touch();
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
    /**
     * Session dont l'heure est passée : terminée à sa fin prévue. La salle est
     * supprimée chez le fournisseur après la réponse — la lecture d'une liste
     * n'attend pas un appel externe.
     */
    private function expire(CallSession $session): void
    {
        $endedAt = $session->expiresAt()->copy()->subMinutes(CallSession::GRACE_MINUTES);
        $session->update(['status' => CallSessionStatus::ENDED, 'ended_at' => $endedAt->isFuture() ? now() : $endedAt]);

        $slug = $session->room_slug;
        defer(function () use ($slug) {
            try {
                $this->videoCallProvider->deleteRoom($slug);
            } catch (Throwable $e) {
                Log::warning("GORIYA Meet : fermeture de la salle {$slug} impossible — {$e->getMessage()}");
            }
        }, always: true);
    }

    /** Sessions dont l'utilisateur est l'hôte ou l'invité. */
    private function visibleTo(Builder $query, User $user): Builder
    {
        return $query
            ->where('host_id', $user->id)
            ->orWhereHas('guests', fn (Builder $guests) => $guests->where('users.id', $user->id));
    }
}
