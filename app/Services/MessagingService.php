<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Candidature;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/**
 * Messagerie réelle par utilisateur — remplace le stub Cache global de
 * App\Services\Admin\AdminMessagingService pour les utilisateurs normaux
 * (candidats/entreprises), qui restent hors du périmètre /admin/messages.
 *
 * Conversations modélisées 1:1 entre deux user_id fixes, ancrées optionnellement
 * sur une Candidature (une seule conversation par candidature). Suppose un seul
 * utilisateur ENTERPRISE par entreprise — vrai aujourd'hui (CompanyService::
 * create() n'en crée qu'un), à revoir si le multi-recruteur est introduit.
 *
 * Les formes JSON retournées ({id, name, role, lastMessageAt, unreadCount,
 * lastMessage} / {id, content, createdAt, senderId}) sont calées sur ce que
 * lisent déjà entreprise/actions/messages.ts et standard/lib/api/
 * message.service.ts, sans changement d'endpoint côté frontend.
 */
class MessagingService
{
    public function __construct(private readonly NotificationService $notificationService) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getConversationsFor(User $user): array
    {
        $conversations = Conversation::where('participant_one_id', $user->id)
            ->orWhere('participant_two_id', $user->id)
            ->with(['participantOne', 'participantTwo'])
            ->orderByDesc('last_message_at')
            ->get();

        return $conversations->map(fn (Conversation $c) => $this->conversationToArray($c, $user))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationToArray(Conversation $conversation, User $user): array
    {
        $other = $conversation->participant_one_id === $user->id
            ? $conversation->participantTwo
            : $conversation->participantOne;

        $lastMessage = $conversation->messages()->latest('created_at')->first();
        $unreadCount = $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();

        return [
            'id' => $conversation->id,
            'name' => $other?->name ?? '—',
            'role' => $other?->role?->value ?? '',
            'lastMessageAt' => $conversation->last_message_at,
            'unreadCount' => $unreadCount,
            'lastMessage' => $lastMessage?->content ?? '',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMessagesFor(Conversation $conversation): array
    {
        return $conversation->messages()->orderBy('created_at')->get()
            ->map(fn (Message $m) => $this->messageToArray($m))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function messageToArray(Message $message): array
    {
        return [
            'id' => $message->id,
            'content' => $message->content,
            'createdAt' => $message->created_at,
            'senderId' => $message->sender_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(Conversation $conversation, User $sender, string $content): array
    {
        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'content' => $content,
        ]);

        $conversation->update(['last_message_at' => now()]);

        $otherId = $conversation->otherParticipantId($sender->id);
        if ($otherId && $recipient = User::find($otherId)) {
            $this->notificationService->notifyNewMessage($recipient, $conversation, $content);
        }

        return $this->messageToArray($message);
    }

    public function markAsRead(Conversation $conversation, User $user): void
    {
        $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function findOrCreateForCandidature(string $candidatureId, User $requestingUser): array
    {
        $candidature = Candidature::with('jobOffer.company.users')->findOrFail($candidatureId);

        if ($requestingUser->id === $candidature->user_id) {
            $companyUser = $candidature->jobOffer?->company?->users?->first();
            if (! $companyUser) {
                abort(404, 'Aucun contact entreprise disponible pour cette offre');
            }
            $otherUserId = $companyUser->id;
        } elseif ($requestingUser->role === UserRole::ENTERPRISE
            && $requestingUser->company_id === $candidature->jobOffer?->company_id) {
            $otherUserId = $candidature->user_id;
        } else {
            abort(403, "Vous n'êtes pas autorisé à démarrer cette conversation");
        }

        $conversation = Conversation::firstOrCreate(
            ['candidature_id' => $candidature->id],
            [
                'participant_one_id' => $requestingUser->id,
                'participant_two_id' => $otherUserId,
            ]
        );

        return $this->conversationToArray($conversation->fresh(['participantOne', 'participantTwo']), $requestingUser);
    }

    public function isParticipant(Conversation $conversation, ?User $user): bool
    {
        return $user && $conversation->isParticipant($user->id);
    }
}
