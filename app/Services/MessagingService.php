<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Candidature;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    /**
     * Ce qu'un recruteur et un candidat s'échangent réellement : un document
     * ou une capture. Le reste est refusé plutôt que stocké sans que personne
     * ne puisse l'ouvrir.
     *
     * @var list<string>
     */
    public const ALLOWED_ATTACHMENT_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    public const MAX_ATTACHMENT_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly NotificationService $notificationService) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getConversationsFor(User $user): array
    {
        $conversations = Conversation::where(fn ($q) => $q
            ->where('participant_one_id', $user->id)
            ->orWhere('participant_two_id', $user->id))
            ->with(['participantOne', 'participantTwo'])
            ->orderByDesc('last_message_at')
            ->get()
            // Une conversation supprimée ne l'est que pour celui qui l'a
            // supprimée : le filtre est ici, pas dans un delete réel.
            ->reject(fn (Conversation $c) => $c->isDeletedBy($user->id));

        return $conversations->map(fn (Conversation $c) => $this->conversationToArray($c, $user))->values()->all();
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
            'lastMessage' => $this->previewOf($lastMessage),
            'starred' => $conversation->isStarredBy($user->id),
        ];
    }

    /**
     * Ce qui s'affiche sous le nom dans la liste des conversations. Un message
     * réduit à un fichier n'a pas de texte : on annonce alors le fichier.
     */
    private function previewOf(?Message $message): string
    {
        if ($message === null) {
            return '';
        }

        if (($message->content ?? '') !== '') {
            return (string) $message->content;
        }

        return $message->attachment_name !== null ? 'Pièce jointe : '.$message->attachment_name : '';
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
            'attachment' => $message->attachment_path === null ? null : [
                'name' => $message->attachment_name,
                'url' => MediaUrl::resolve($message->attachment_path),
                'mimeType' => $message->attachment_mime,
                'size' => $message->attachment_size,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(Conversation $conversation, User $sender, string $content, ?UploadedFile $attachment = null): array
    {
        $attributs = ['sender_id' => $sender->id, 'content' => $content];

        if ($attachment !== null) {
            $attributs += $this->storeAttachment($attachment);
        }

        $message = $conversation->messages()->create($attributs);

        // Un envoi remonte la conversation dans la liste de tout le monde :
        // celui qui l'avait supprimee de la sienne la retrouve avec le nouveau
        // message, plutôt que de ne jamais le voir arriver.
        $conversation->forceFill([
            'last_message_at' => now(),
            'deleted_by' => array_values(array_diff($conversation->deleted_by ?? [], [$sender->id])),
        ])->save();

        $otherId = $conversation->otherParticipantId($sender->id);
        if ($otherId && $recipient = User::find($otherId)) {
            $this->notificationService->notifyNewMessage($recipient, $conversation, $this->previewOf($message));
        }

        return $this->messageToArray($message);
    }

    /**
     * @return array{attachment_path: string, attachment_name: string, attachment_mime: string|null, attachment_size: int|null}
     */
    private function storeAttachment(UploadedFile $file): array
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_ATTACHMENT_MIME_TYPES, true)) {
            abort(400, 'Format non supporté : joignez un PDF, un document Office, un fichier texte ou une image.');
        }

        if ($file->getSize() > self::MAX_ATTACHMENT_BYTES) {
            abort(400, 'La pièce jointe ne doit pas dépasser 8 Mo.');
        }

        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $filename = Str::uuid().'.'.$extension;
        Storage::disk('public')->putFileAs('messages', $file, $filename);

        return [
            'attachment_path' => "/messages/{$filename}",
            'attachment_name' => $file->getClientOriginalName(),
            'attachment_mime' => $file->getMimeType(),
            'attachment_size' => $file->getSize(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function setStarred(Conversation $conversation, User $user, bool $starred): array
    {
        $conversation->forceFill([
            'starred_by' => $this->withId($conversation->starred_by ?? [], $user->id, $starred),
        ])->save();

        return $this->conversationToArray($conversation->fresh(['participantOne', 'participantTwo']), $user);
    }

    /**
     * Suppression côté participant uniquement : l'autre garde la conversation
     * et son historique (cf. migration starred_by/deleted_by).
     */
    public function deleteForUser(Conversation $conversation, User $user): void
    {
        $conversation->forceFill([
            'deleted_by' => $this->withId($conversation->deleted_by ?? [], $user->id, true),
        ])->save();
    }

    /**
     * Remet la conversation en non lu : seul le dernier message reçu est
     * "déslu", c'est lui qui fait réapparaître la pastille.
     */
    public function markAsUnread(Conversation $conversation, User $user): void
    {
        $dernier = $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->latest('created_at')
            ->first();

        $dernier?->forceFill(['read_at' => null])->save();
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function withId(array $ids, string $id, bool $present): array
    {
        $ids = array_values(array_diff($ids, [$id]));

        if ($present) {
            $ids[] = $id;
        }

        return $ids;
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
