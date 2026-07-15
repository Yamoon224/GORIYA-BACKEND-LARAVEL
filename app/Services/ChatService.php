<?php

namespace App\Services;

use App\Contracts\ChatAiServiceInterface;
use App\Enums\ChatMessageRole;
use App\Models\Candidature;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Pitch;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * GORIYA Chat — persistance des fils/messages + construction du contexte
 * utilisateur léger, scopé à l'utilisateur authentifié (pas de route
 * publique, comme Research/Pitches/Presentations).
 *
 * NOTE : le plan initial mentionnait "dernier score CV" comme signal de
 * contexte, mais CvAnalysis n'a pas de user_id dans ce backend (parité
 * NestJS d'origine — CV analysés globalement, non rattachés à un
 * utilisateur). Le contexte utilise donc les modèles réellement scopés à
 * l'utilisateur : dernière candidature, dernier pitch, compétences déclarées.
 */
class ChatService
{
    public function __construct(private readonly ChatAiServiceInterface $chatAi) {}

    public function listThreadsFor(User $user): Collection
    {
        return ChatThread::where('user_id', $user->id)->orderByDesc('updated_at')->get();
    }

    public function findThread(string $id, User $user): ?ChatThread
    {
        return ChatThread::where('user_id', $user->id)->with('messages')->find($id);
    }

    public function createThread(User $user, ?string $title = null): ChatThread
    {
        return ChatThread::create([
            'user_id' => $user->id,
            'title' => $title,
        ]);
    }

    /**
     * Persiste le message utilisateur, génère et persiste la réponse IA,
     * et renvoie le fil rechargé avec ses messages.
     */
    public function sendMessage(ChatThread $thread, User $user, string $content): ChatThread
    {
        ChatMessage::create([
            'thread_id' => $thread->id,
            'role' => ChatMessageRole::USER,
            'content' => $content,
        ]);

        if (! $thread->title) {
            $thread->update(['title' => mb_strimwidth($content, 0, 60, '…')]);
        }

        $history = $thread->messages()->get()
            ->map(fn (ChatMessage $message) => ['role' => $message->role->value, 'content' => $message->content])
            ->all();

        $reply = $this->chatAi->reply($history, $this->buildContext($user));

        ChatMessage::create([
            'thread_id' => $thread->id,
            'role' => ChatMessageRole::ASSISTANT,
            'content' => $reply,
        ]);

        $thread->touch();

        return $thread->fresh('messages');
    }

    public function deleteThread(ChatThread $thread): void
    {
        $thread->delete();
    }

    /**
     * @return array{name: string, lastJobOfferTitle?: string, lastPitchType?: string, skills?: array<int, string>}
     */
    private function buildContext(User $user): array
    {
        $context = ['name' => $user->name];

        $lastJobOfferTitle = Candidature::where('user_id', $user->id)
            ->latest('applied_date')
            ->with('jobOffer')
            ->first()?->jobOffer?->title;
        if ($lastJobOfferTitle) {
            $context['lastJobOfferTitle'] = $lastJobOfferTitle;
        }

        $lastPitchType = Pitch::where('user_id', $user->id)->latest('created_at')->first()?->type?->value;
        if ($lastPitchType) {
            $context['lastPitchType'] = $lastPitchType;
        }

        $skills = Portfolio::where('user_id', $user->id)->latest('created_date')->first()?->skills;
        if (! empty($skills)) {
            $context['skills'] = $skills;
        }

        return $context;
    }
}
