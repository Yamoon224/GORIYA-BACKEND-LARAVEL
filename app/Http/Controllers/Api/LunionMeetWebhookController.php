<?php

namespace App\Http\Controllers\Api;

use App\Enums\CallSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\CallParticipant;
use App\Models\CallSession;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Récepteur des événements sortants de lunion.meet (voir
 * https://meet.lunion-lab.com/docs/webhooks) — enregistré côté dashboard
 * lunion.meet, pas via l'API. Pas de guard `auth:api` (l'appelant est
 * lunion.meet, pas un utilisateur GORIYA) : la vérification de
 * X-Lunion-Signature (HMAC-SHA256 du corps brut, secret dédié
 * LUNION_MEET_WEBHOOK_SECRET) tient lieu d'authentification.
 *
 * NOTE : la forme exacte de `data` par type d'événement n'est pas détaillée
 * dans la documentation consultée au-delà de {event, createdAt, data}. Le
 * nommage des champs (slug de room, identity/nom du participant, URL
 * d'enregistrement) est déduit par analogie avec les conventions déjà
 * observées sur les endpoints REST (rooms/tokens) — les extractions
 * ci-dessous essaient plusieurs clés plausibles et ignorent silencieusement
 * un événement dont la session GORIYA correspondante est introuvable,
 * plutôt que d'échouer bruyamment sur un champ absent. À revérifier contre
 * une livraison réelle avant mise en production.
 */
#[OA\Tag(name: 'Calls', description: 'GORIYA Call — visioconférence intégrée (lunion.meet)')]
class LunionMeetWebhookController extends Controller
{
    #[OA\Post(
        path: '/webhooks/lunion-meet',
        tags: ['Calls'],
        summary: 'Réception des événements lunion.meet (participant/session/enregistrement)',
        responses: [
            new OA\Response(response: 200, description: 'Événement traité'),
            new OA\Response(response: 401, description: 'Signature invalide'),
        ]
    )]
    public function handle(Request $request)
    {
        $this->verifySignature($request);

        $event = (string) $request->input('event');
        $data = (array) $request->input('data', []);

        match ($event) {
            'participant.joined' => $this->onParticipantJoined($data),
            'participant.left' => $this->onParticipantLeft($data),
            'session.ended' => $this->onSessionEnded($data),
            'recording.ended' => $this->onRecordingEnded($data),
            default => null, // session.started/track.*/ingress.*/recording.started : accusés réception sans action GORIYA
        };

        return response()->json(['received' => true]);
    }

    private function verifySignature(Request $request): void
    {
        $secret = config('services.lunion_meet.webhook_secret');
        if (! $secret) {
            abort(500, 'Secret webhook lunion.meet non configuré (LUNION_MEET_WEBHOOK_SECRET)');
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        $received = (string) $request->header('X-Lunion-Signature', '');

        if ($received === '' || ! hash_equals($expected, $received)) {
            abort(401, 'Signature webhook invalide');
        }
    }

    private function onParticipantJoined(array $data): void
    {
        $session = $this->resolveSession($data);
        if (! $session) {
            return;
        }

        $identity = $this->resolveIdentity($data);
        if ($identity === null) {
            return;
        }

        CallParticipant::updateOrCreate(
            ['call_session_id' => $session->id, 'identity' => $identity],
            ['name' => $this->resolveParticipantName($data), 'joined_at' => now(), 'left_at' => null],
        );

        if ($session->status === CallSessionStatus::SCHEDULED) {
            $session->update(['status' => CallSessionStatus::ACTIVE]);
        }
    }

    private function onParticipantLeft(array $data): void
    {
        $session = $this->resolveSession($data);
        $identity = $this->resolveIdentity($data);
        if (! $session || $identity === null) {
            return;
        }

        CallParticipant::where('call_session_id', $session->id)
            ->where('identity', $identity)
            ->whereNull('left_at')
            ->latest('joined_at')
            ->first()
            ?->update(['left_at' => now()]);
    }

    private function onSessionEnded(array $data): void
    {
        $session = $this->resolveSession($data);
        if (! $session || $session->status === CallSessionStatus::ENDED) {
            return;
        }

        $session->update(['status' => CallSessionStatus::ENDED, 'ended_at' => now()]);
    }

    private function onRecordingEnded(array $data): void
    {
        $session = $this->resolveSession($data);
        $url = $data['url'] ?? $data['recordingUrl'] ?? $data['egressUrl'] ?? null;
        if (! $session || ! $url) {
            return;
        }

        $session->update(['recording_url' => $url]);
    }

    private function resolveSession(array $data): ?CallSession
    {
        $slug = $data['roomSlug']
            ?? $data['room']['slug']
            ?? (is_string($data['room'] ?? null) ? $data['room'] : null)
            ?? null;

        return $slug ? CallSession::where('room_slug', $slug)->first() : null;
    }

    private function resolveIdentity(array $data): ?string
    {
        return $data['identity'] ?? $data['participant']['identity'] ?? null;
    }

    private function resolveParticipantName(array $data): ?string
    {
        return $data['name'] ?? $data['participant']['name'] ?? null;
    }
}
