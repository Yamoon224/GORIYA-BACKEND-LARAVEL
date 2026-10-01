<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCoursesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Goriya',
            'email' => 'admin@goriya.net',
            'password' => 'motdepasse-solide',
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_non_admin_is_rejected(): void
    {
        $user = User::create([
            'name' => 'Candidat', 'email' => 'candidat@goriya-test.ci',
            'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE',
        ]);

        $this->actingAs($user, 'api')->getJson('/admin/courses/paginate')->assertForbidden();
        $this->actingAs($user, 'api')->postJson('/admin/courses', ['title' => 'X'])->assertForbidden();
    }

    public function test_admin_builds_a_course_with_category_instructor_and_lessons(): void
    {
        $admin = $this->admin();

        $categoryId = $this->actingAs($admin, 'api')
            ->postJson('/admin/course-categories', ['name' => 'Data scientist', 'icon' => 'chart'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'data-scientist')
            ->json('data.id');

        $instructorId = $this->actingAs($admin, 'api')
            ->postJson('/admin/instructors', ['name' => 'Edith Brou', 'headline' => 'Professionnelle des médias'])
            ->assertCreated()
            ->json('data.id');

        $courseId = $this->actingAs($admin, 'api')->postJson('/admin/courses', [
            'title' => 'Python pour la data',
            'categoryId' => $categoryId,
            'instructorId' => $instructorId,
            'level' => 'BEGINNER',
            'language' => 'fr',
            'subtitleLanguages' => ['en', 'es'],
            'learningOutcomes' => ['Manipuler des DataFrames'],
        ])->assertCreated()
            ->assertJsonPath('data.category', 'Data scientist')
            ->assertJsonPath('data.isPublished', false)
            ->json('data.id');

        $first = $this->actingAs($admin, 'api')->postJson("/admin/courses/{$courseId}/lessons", [
            'title' => 'Installer Python',
            'videoUrl' => 'https://youtu.be/abc123',
            'durationSeconds' => 600,
            'isFreePreview' => true,
        ])->assertCreated()
            ->assertJsonPath('data.videoProvider', 'YOUTUBE')
            ->assertJsonPath('data.position', 1)
            ->json('data.id');

        $second = $this->actingAs($admin, 'api')->postJson("/admin/courses/{$courseId}/lessons", [
            'title' => 'Pandas',
            'videoUrl' => 'https://cdn.example.com/pandas.mp4',
            'durationSeconds' => 1200,
            'subtitles' => [['lang' => 'en', 'label' => 'English', 'url' => 'https://cdn.example.com/pandas.en.vtt']],
        ])->assertCreated()
            ->assertJsonPath('data.videoProvider', 'FILE')
            ->json('data.id');

        $this->actingAs($admin, 'api')
            ->postJson("/admin/courses/{$courseId}/lessons/reorder", ['ids' => [$second, $first]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $second);

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/courses/{$courseId}", ['isPublished' => true])
            ->assertOk()
            ->assertJsonPath('data.isPublished', true)
            ->assertJsonPath('data.lessonsCount', 2)
            ->assertJsonPath('data.totalMinutes', 30);

        $this->assertNotNull(Course::find($courseId)->published_at);

        $this->actingAs($admin, 'api')->getJson("/admin/courses/{$courseId}")
            ->assertOk()
            ->assertJsonCount(2, 'data.lessons');

        $this->getJson('/courses/paginate')->assertJsonPath('meta.total', 1);

        $this->actingAs($admin, 'api')->deleteJson("/admin/course-lessons/{$first}")->assertOk();
        $this->assertSame(1, Course::find($courseId)->lessons_count);

        $this->actingAs($admin, 'api')->deleteJson("/admin/courses/{$courseId}")->assertOk();
        $this->getJson('/courses/paginate')->assertJsonPath('meta.total', 0);
        $this->actingAs($admin, 'api')->getJson('/admin/courses/paginate?status=ARCHIVED')->assertJsonPath('meta.total', 1);

        $this->actingAs($admin, 'api')->getJson('/admin/courses/stats')->assertOk()->assertJsonPath('data.lessons', 1);
    }

    public function test_invalid_lesson_payload_is_rejected(): void
    {
        $admin = $this->admin();
        $course = Course::create(['title' => 'Cours', 'is_active' => true]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/courses/{$course->id}/lessons", ['title' => 'Sans URL valide', 'videoUrl' => 'pas-une-url'])
            ->assertStatus(400);
    }

    public function test_admin_uploads_a_thumbnail(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin(), 'api')
            ->post('/admin/courses/upload-image', ['image' => UploadedFile::fake()->image('cover.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['path', 'url']]);
    }

    public function test_admin_reviews_a_project(): void
    {
        $admin = $this->admin();
        $learner = User::create([
            'name' => 'Apprenant', 'email' => 'apprenant@goriya-test.ci',
            'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE',
        ]);
        $project = CourseProject::create(['user_id' => $learner->id, 'title' => 'Mon site vitrine', 'status' => 'SUBMITTED']);

        $this->actingAs($admin, 'api')->getJson('/admin/course-projects/paginate?status=SUBMITTED')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user.name', 'Apprenant');

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/course-projects/{$project->id}/review", ['feedback' => 'Très propre, bravo.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REVIEWED')
            ->assertJsonPath('data.feedback', 'Très propre, bravo.');
    }
}
