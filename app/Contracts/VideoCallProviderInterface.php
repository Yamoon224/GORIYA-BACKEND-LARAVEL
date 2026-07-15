<?php

namespace App\Contracts;

/**
 * Fournisseur de visioconférence (GORIYA Call) — voir LunionMeetService pour
 * l'implémentation active (lunion.meet, SFU self-hosted Lunion-Lab).
 */
interface VideoCallProviderInterface
{
    /**
     * Crée une room et retourne sa représentation brute côté fournisseur.
     *
     * @return array{id: string, slug: string, name: string, scheduledAt: ?string, createdAt: string}
     */
    public function createRoom(string $name, ?string $scheduledAt = null, ?string $description = null): array;

    public function deleteRoom(string $slug): void;

    /**
     * Émet un token de connexion à court terme pour un participant. Doit
     * toujours être appelé depuis le backend (jamais exposer la clé API
     * elle-même au client).
     *
     * @param  array{canPublish?: bool, canSubscribe?: bool, canPublishData?: bool, hidden?: bool, roomAdmin?: bool}  $grants
     * @return array{token: string, url: string, room: string, identity: string, expiresAt: string}
     */
    public function issueToken(
        string $slug,
        string $identity,
        ?string $name = null,
        array $grants = [],
        int $ttlSeconds = 21600
    ): array;
}
