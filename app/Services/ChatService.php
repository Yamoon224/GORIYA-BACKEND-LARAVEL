<?php

namespace App\Services;

use App\Contracts\ChatAiServiceInterface;
use App\Enums\ChatMessageRole;
use App\Models\Candidature;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\CvProfile;
use App\Models\Pitch;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
     * Recherche dans les titres et le contenu des messages. Renvoie, pour
     * chaque fil trouvé, un extrait autour du premier message correspondant.
     *
     * @return list<array{id: string, title: string|null, excerpt: string|null, updatedAt: mixed}>
     */
    public function searchThreads(User $user, string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return ChatThread::where('user_id', $user->id)
            ->where(fn ($query) => $query
                ->where('title', 'like', $like)
                ->orWhereHas('messages', fn ($messages) => $messages->where('content', 'like', $like)))
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(function (ChatThread $thread) use ($like, $term) {
                $content = $thread->messages()->where('content', 'like', $like)->value('content');
                $excerpt = null;
                if ($content) {
                    $position = max(0, (int) mb_stripos($content, $term) - 40);
                    $excerpt = ($position > 0 ? '…' : '').trim(mb_substr($content, $position, 140));
                }

                return ['id' => $thread->id, 'title' => $thread->title, 'excerpt' => $excerpt, 'updatedAt' => $thread->updated_at];
            })
            ->all();
    }

    /**
     * Persiste le message utilisateur (et ses pièces jointes), génère et
     * persiste la réponse IA, et renvoie le fil rechargé avec ses messages.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function sendMessage(ChatThread $thread, User $user, string $content, array $files = []): ChatThread
    {
        $attachments = collect($files)
            ->filter(fn ($file) => $file instanceof UploadedFile)
            ->map(function (UploadedFile $file) use ($thread) {
                // Disque privé : ces fichiers (CV, offres...) ne sont lus que par l'IA.
                $path = $file->storeAs("chat/{$thread->id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');

                return [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ];
            })
            ->values()
            ->all();

        ChatMessage::create([
            'thread_id' => $thread->id,
            'role' => ChatMessageRole::USER,
            'content' => $content,
            // La clé n'est posée que s'il y a des fichiers : un envoi sans pièce
            // jointe fonctionne ainsi même avant la migration `attachments`.
            ...($attachments ? ['attachments' => $attachments] : []),
        ]);

        if (! $thread->title) {
            $thread->update(['title' => mb_strimwidth($content !== '' ? $content : ($attachments[0]['name'] ?? 'Conversation'), 0, 60, '…')]);
        }

        $history = $thread->messages()->get()
            ->map(fn (ChatMessage $message) => [
                'role' => $message->role->value,
                'content' => $message->content,
                'attachments' => $message->attachments ?? [],
            ])
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
        Storage::disk('local')->deleteDirectory("chat/{$thread->id}");
        $thread->delete();
    }

    /**
     * @return array{name: string, lastJobOfferTitle?: string, lastPitchType?: string, skills?: array<int, string>, cv?: string}
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

        // Profil extrait du CV analysé : sans lui, le chat répondait « je
        // n'ai pas accès à votre CV » à qui venait pourtant de le déposer.
        $cv = CvProfile::firstWhere('user_id', $user->id)?->data;
        if (! empty($cv)) {
            $context['cv'] = $this->summarizeCv($cv);
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $cv
     */
    private function summarizeCv(array $cv): string
    {
        $experiences = collect($cv['experiences'] ?? [])->take(6)->map(fn (array $e) => trim(
            implode(' chez ', array_filter([$e['position'] ?? '', $e['company'] ?? '']))
            .(! empty($e['date']) ? " ({$e['date']})" : '')
            .(! empty($e['missions']) ? ' : '.implode(' ; ', array_slice($e['missions'], 0, 4)) : '')
        ))->filter()->implode("\n  * ");

        $educations = collect($cv['educations'] ?? [])->take(4)->map(fn (array $e) => trim(
            implode(' - ', array_filter([$e['title'] ?? '', $e['school'] ?? '']))
            .(! empty($e['date']) ? " ({$e['date']})" : '')
        ))->filter()->implode(' ; ');

        return mb_substr(implode("\n", array_filter([
            ! empty($cv['title']) ? "Titre : {$cv['title']}" : '',
            ! empty($cv['desiredJob']) ? "Poste recherché : {$cv['desiredJob']}" : '',
            ! empty($cv['skills']) ? 'Compétences : '.implode(', ', array_slice($cv['skills'], 0, 20)) : '',
            $experiences !== '' ? "Expériences :\n  * {$experiences}" : '',
            $educations !== '' ? "Formation : {$educations}" : '',
        ])), 0, 3000);
    }
}
