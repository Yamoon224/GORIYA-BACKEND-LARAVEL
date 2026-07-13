<?php

namespace App\Services;

use App\Contracts\PitchAiServiceInterface;
use App\Enums\PitchFormat;
use App\Enums\PitchStatus;
use App\Jobs\ProcessPitchVideoJob;
use App\Models\Candidature;
use App\Models\JobOffer;
use App\Models\Pitch;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pitch Goriya — génération/scoring IA + persistance, scopé à l'utilisateur
 * authentifié (pas de route publique, comme CvAnalysis/Research).
 */
class PitchService
{
    public function __construct(
        private readonly PitchAiServiceInterface $pitchAi,
        private readonly CandidatureService $candidatureService,
    ) {}

    public function listFor(User $user): Collection
    {
        return Pitch::where('user_id', $user->id)->orderByDesc('created_at')->get();
    }

    public function find(string $id, User $user): ?Pitch
    {
        return Pitch::where('user_id', $user->id)->find($id);
    }

    /**
     * @param  array{type: string, jobOfferId?: string, content?: string}  $data
     */
    public function create(User $user, array $data): Pitch
    {
        $jobOffer = ! empty($data['jobOfferId'])
            ? JobOffer::with('company')->find($data['jobOfferId'])
            : null;

        $profile = ['name' => $user->name, 'email' => $user->email];
        $job = $jobOffer ? [
            'title' => $jobOffer->title,
            'company' => $jobOffer->company?->name,
            'description' => $jobOffer->description,
        ] : null;

        $content = $data['content'] ?? $this->pitchAi->generate($profile, $job, $data['type']);
        $score = $this->pitchAi->score($content);

        return Pitch::create([
            'user_id' => $user->id,
            'job_offer_id' => $jobOffer?->id,
            'type' => $data['type'],
            'format' => PitchFormat::TEXT,
            'content' => $content,
            'score' => $score,
            'status' => PitchStatus::READY,
        ]);
    }

    /**
     * Stocke la vidéo et déclenche le (re)scoring en arrière-plan — le script
     * (content) existe déjà (généré ou fourni à la création), donc l'upload
     * vidéo ne bloque pas sur un nouvel appel IA synchrone.
     */
    public function attachVideo(Pitch $pitch, UploadedFile $file): Pitch
    {
        $this->deleteVideo($pitch);

        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        Storage::disk('public')->putFileAs('pitches', $file, $filename);

        $pitch->update([
            'format' => PitchFormat::VIDEO,
            'video_path' => "/storage/pitches/{$filename}",
            'status' => PitchStatus::PROCESSING,
        ]);

        ProcessPitchVideoJob::dispatch($pitch->id);

        return $pitch->fresh();
    }

    /**
     * Réutilise CandidatureService::create() (notification à l'entreprise
     * incluse) plutôt que de dupliquer la logique d'envoi de candidature —
     * seul l'attachement du pitch_id est spécifique à ce flux.
     */
    public function sendToRecruiter(Pitch $pitch, User $user, string $jobOfferId): Candidature
    {
        $candidature = $this->candidatureService->create([
            'candidateName' => $user->name,
            'candidateEmail' => $user->email,
            'appliedDate' => now(),
            'userId' => $user->id,
            'jobOfferId' => $jobOfferId,
        ]);

        $candidature->update(['pitch_id' => $pitch->id]);

        return $candidature->fresh(['jobOffer', 'jobOffer.company', 'pitch']);
    }

    public function delete(Pitch $pitch): void
    {
        $this->deleteVideo($pitch);
        $pitch->delete();
    }

    private function deleteVideo(Pitch $pitch): void
    {
        if ($pitch->video_path) {
            Storage::disk('public')->delete('pitches/'.basename($pitch->video_path));
        }
    }
}
