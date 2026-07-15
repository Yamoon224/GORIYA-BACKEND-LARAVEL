<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class EnrollmentService
{
    public function __construct(private readonly CertificateService $certificateService) {}

    public function listFor(User $user): Collection
    {
        return Enrollment::where('user_id', $user->id)->with('course')->orderByDesc('created_at')->get();
    }

    public function find(string $id, User $user): ?Enrollment
    {
        return Enrollment::where('user_id', $user->id)->with('course')->find($id);
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
            $payload['completed_at'] = now();
        }

        $enrollment->update($payload);

        if ($justCompleted) {
            $enrollment->update(['certificate_path' => $this->certificateService->generate($enrollment)]);
        }

        return $enrollment->fresh('course');
    }
}
