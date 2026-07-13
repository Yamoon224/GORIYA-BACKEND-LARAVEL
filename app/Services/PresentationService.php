<?php

namespace App\Services;

use App\Contracts\PresentationAiServiceInterface;
use App\Models\Presentation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Génération + persistance des présentations/schémas, scopée à
 * l'utilisateur authentifié (pas de route publique, comme Research/Pitch).
 */
class PresentationService
{
    public function __construct(private readonly PresentationAiServiceInterface $presentationAi) {}

    public function listFor(User $user): Collection
    {
        return Presentation::where('user_id', $user->id)->orderByDesc('created_at')->get();
    }

    public function find(string $id, User $user): ?Presentation
    {
        return Presentation::where('user_id', $user->id)->find($id);
    }

    /**
     * @param  array{title: string, type: string, brief: string}  $data
     */
    public function create(User $user, array $data): Presentation
    {
        $content = $this->presentationAi->generate($data['brief'], $data['type']);

        return Presentation::create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'type' => $data['type'],
            'brief' => $data['brief'],
            'content' => $content,
        ]);
    }

    public function delete(Presentation $presentation): void
    {
        $presentation->delete();
    }
}
