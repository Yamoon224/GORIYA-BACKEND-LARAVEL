<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\CoursePass;
use App\Models\User;
use App\Repositories\Contracts\UserSubscriptionRepositoryInterface;

/**
 * Qui peut regarder quoi dans le module Formation.
 *
 * Le catalogue (fiches, programme, instructeurs) est public. La vidéo d'une
 * leçon n'est servie que si :
 *   - le cours est gratuit, ou la leçon est un aperçu gratuit ;
 *   - ou le forfait actif inclut la fonctionnalité `formations` (Standard,
 *     Premium — voir SubscriptionPlanSeeder) ;
 *   - ou l'utilisateur a un pass découverte de 7 jours en cours.
 *
 * Les URLs vidéo étant des liens externes, « verrouiller » consiste à ne pas
 * les renvoyer : la garde est côté API, pas seulement dans l'interface.
 */
class CourseAccessService
{
    public const FEATURE_KEY = 'formations';

    public const PASS_DAYS = 7;

    public function __construct(
        private readonly UserSubscriptionRepositoryInterface $subscriptions,
    ) {}

    public function planIncludesCourses(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return (bool) $this->subscriptions->findActiveForUser($user->id)?->plan?->hasFeature(self::FEATURE_KEY);
    }

    public function passFor(User $user): ?CoursePass
    {
        return CoursePass::where('user_id', $user->id)->first();
    }

    public function hasFullAccess(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->planIncludesCourses($user) || (bool) $this->passFor($user)?->isActive();
    }

    public function canWatch(?User $user, Course $course, CourseLesson $lesson, ?bool $fullAccess = null): bool
    {
        if ($course->is_free || $lesson->is_free_preview) {
            return true;
        }

        return $fullAccess ?? $this->hasFullAccess($user);
    }

    /**
     * @return array{hasFullAccess: bool, viaPlan: bool, pass: array{startsAt: string, endsAt: string, active: bool}|null, passAvailable: bool, passDays: int}
     */
    public function summary(User $user): array
    {
        $viaPlan = $this->planIncludesCourses($user);
        $pass = $this->passFor($user);

        return [
            'hasFullAccess' => $viaPlan || (bool) $pass?->isActive(),
            'viaPlan' => $viaPlan,
            'pass' => $pass ? [
                'startsAt' => $pass->starts_at->toIso8601String(),
                'endsAt' => $pass->ends_at->toIso8601String(),
                'active' => $pass->isActive(),
            ] : null,
            'passAvailable' => $pass === null,
            'passDays' => self::PASS_DAYS,
        ];
    }

    /**
     * Active le pass découverte. Un seul par utilisateur, à vie : un second
     * appel est refusé (400) même une fois le premier expiré.
     */
    public function activatePass(User $user): CoursePass
    {
        if ($this->passFor($user)) {
            abort(400, 'Vous avez déjà utilisé votre pass découverte Formation.');
        }

        return CoursePass::create([
            'user_id' => $user->id,
            'starts_at' => now(),
            'ends_at' => now()->addDays(self::PASS_DAYS),
        ]);
    }
}
