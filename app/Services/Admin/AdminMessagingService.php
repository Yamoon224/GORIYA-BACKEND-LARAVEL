<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mirroir du stub de messagerie in-memory de backend/src/admin/
 * admin-platform.service.ts — pas de table dédiée côté source, persistance
 * via Cache pour survivre au modèle sans état par-requête de PHP-FPM.
 * Extrait de l'ex-AdminPlatformService.
 */
class AdminMessagingService
{
    public function getConversations(): array
    {
        return Cache::get('admin:conversations', []);
    }

    public function getConversationMessages(string $conversationId): array
    {
        return Cache::get("admin:conversation_messages:{$conversationId}", []);
    }

    public function sendConversationMessage(string $conversationId, string $content): array
    {
        $messages = Cache::get("admin:conversation_messages:{$conversationId}", []);
        $message = ['id' => (string) Str::uuid(), 'content' => $content, 'createdAt' => now()->toJSON()];
        $messages[] = $message;
        Cache::forever("admin:conversation_messages:{$conversationId}", $messages);

        return $message;
    }

    public function markConversationAsRead(string $conversationId): void
    {
        // No-op : aucune notion de "lu" n'existe réellement dans la source.
    }

    public function createConversation(string $participantId): array
    {
        $conversation = ['id' => (string) Str::uuid(), 'participantId' => $participantId, 'createdAt' => now()->toJSON()];

        $conversations = Cache::get('admin:conversations', []);
        $conversations[] = $conversation;
        Cache::forever('admin:conversations', $conversations);
        Cache::forever("admin:conversation_messages:{$conversation['id']}", []);

        return $conversation;
    }
}
