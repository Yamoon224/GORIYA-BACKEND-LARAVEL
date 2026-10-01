<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\Enrollment;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class EnrollmentService
{
    public function __construct(private readonly CertificateService $certificateService) {}

    public function listFor(User $user): Collection
    {
        return Enrollment::where('user_id', $user->id)
            ->with(['course.instructor', 'course.categoryRef'])
            ->orderByDesc('updated_at')
            ->get();
    }

    public function find(string $id, User $user): ?Enrollment
    {
        return Enrollment::where('user_id', $user->id)->with('course')->find($id);
    }

    public function findFor(User $user, Course $course): ?Enrollment
    {
        return Enrollment::where('user_id', $user->id)->where('course_id', $course->id)->first();
    }

    public function enroll(User $user, Course $course): Enrollment
    {
        return Enrollment::firstOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id],
            ['progress' => 0, 'status' => EnrollmentStatus::IN_PROGRESS, 'enrolled_at' => now()],
        );
    }

    public function updateProgress(Enrollment $enrollment, int $progress): Enrollment
    {
        $progress = max(0, min(100, $progress));
        $payload = ['progress' => $progress];

        $justCompleted = $progress >= 100 && $enrollment->status !== EnrollmentStatus::COMPLETED;

        if ($progress >= 100) {
            $payload['status'] = EnrollmentStatus::COMPLETED;
            $payload['completed_at'] = $enrollment->completed_at ?? now();
        }

        $enrollment->update($payload);

        if ($justCompleted) {
            $enrollment->update(['certificate_path' => $this->certificateService->generate($enrollment)]);
        }

        return $enrollment->fresh('course');
    }

    /**
     * Enregistre l'avancement sur une leçon (position de lecture, leçon
     * terminée) et recalcule la progression du cours à partir des leçons
     * terminées. Inscrit automatiquement au cours au premier visionnage.
     * Une leçon terminée le reste : repasser `completed: false` ne la
     * décoche pas (évite de perdre un certificat par un clic).
     */
    public function recordLessonProgress(User $user, CourseLesson $lesson, ?int $positionSeconds, bool $completed): array
    {
        $course = $lesson->course;
        $enrollment = $this->enroll($user, $course);

        $progress = LessonProgress::firstOrNew(['user_id' => $user->id, 'lesson_id' => $lesson->id]);
        $progress->course_id = $course->id;
        if ($positionSeconds !== null) {
            $progress->last_position_seconds = max(0, $positionSeconds);
        }
        if ($completed && ! $progress->completed_at) {
            $progress->completed_at = now();
        }
        $progress->save();

        $total = $course->lessons()->count();
        $done = LessonProgress::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereNotNull('completed_at')
            ->count();

        $percent = $total > 0 ? (int) floor($done * 100 / $total) : 0;
        // Ne jamais faire reculer une progression (ex. leçon ajoutée après
        // coup à un cours déjà terminé).
        $enrollment = $this->updateProgress($enrollment, max($percent, $enrollment->progress));

        return ['lessonProgress' => $progress, 'enrollment' => $enrollment];
    }

    /**
     * @return array<string, array{lastPositionSeconds: int, completed: bool}>
     */
    public function lessonProgressMap(User $user, Course $course): array
    {
        return LessonProgress::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->get()
            ->mapWithKeys(fn (LessonProgress $p) => [$p->lesson_id => [
                'lastPositionSeconds' => $p->last_position_seconds,
                'completed' => $p->completed_at !== null,
            ]])
            ->all();
    }

    /**
     * Dernière leçon ouverte par cours — pour le bouton « Reprendre » de
     * « Mes cours ».
     *
     * @param  list<string>  $courseIds
     * @return array<string, string> course_id => lesson_id
     */
    public function lastLessonByCourse(User $user, array $courseIds): array
    {
        return LessonProgress::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->orderByDesc('updated_at')
            ->get(['course_id', 'lesson_id'])
            ->unique('course_id')
            ->mapWithKeys(fn (LessonProgress $p) => [$p->course_id => $p->lesson_id])
            ->all();
    }
}
