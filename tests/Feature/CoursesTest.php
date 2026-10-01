<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseLesson;
use App\Models\Instructor;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GivesActiveSubscription;
use Tests\TestCase;

/**
 * Module Formation : catalogue, verrouillage des vidéos (forfait / pass /
 * gratuit / aperçu), progression par leçon jusqu'au certificat, favoris et
 * projets.
 */
class CoursesTest extends TestCase
{
    use GivesActiveSubscription, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        Storage::fake('local');
    }

    private function learner(?string $plan = null): User
    {
        $user = User::create([
            'name' => 'Apprenant Test',
            'email' => 'apprenant-'.uniqid().'@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);

        if ($plan) {
            $this->giveActiveSubscription($user, $plan);
        }

        return $user;
    }

    private function course(array $attributes = []): Course
    {
        $category = CourseCategory::create(['name' => 'Développeur web '.uniqid()]);
        $instructor = Instructor::create(['name' => 'Edith Brou', 'headline' => 'Professionnelle des médias']);

        $course = Course::create([
            'title' => 'Créer son premier visuel avec Photoshop',
            'category_id' => $category->id,
            'instructor_id' => $instructor->id,
            'is_active' => true,
            'is_published' => true,
            'published_at' => now(),
            ...$attributes,
        ]);

        CourseLesson::create([
            'course_id' => $course->id, 'title' => 'Introduction', 'position' => 1,
            'video_url' => 'https://www.youtube.com/watch?v=intro', 'video_provider' => 'YOUTUBE',
            'duration_seconds' => 300, 'is_free_preview' => true,
        ]);
        CourseLesson::create([
            'course_id' => $course->id, 'title' => 'Les calques', 'position' => 2,
            'video_url' => 'https://cdn.example.com/calques.mp4', 'video_provider' => 'FILE',
            'duration_seconds' => 600,
        ]);
        $course->refreshLessonStats();

        return $course->fresh();
    }

    public function test_catalogue_lists_only_published_courses_and_filters_by_category(): void
    {
        $published = $this->course();
        $this->course(['title' => 'Brouillon', 'is_published' => false]);

        $this->getJson('/courses/paginate')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonPath('data.0.lessonsCount', 2)
            ->assertJsonPath('data.0.totalMinutes', 15)
            ->assertJsonPath('data.0.instructor.name', 'Edith Brou');

        $slug = $published->categoryRef->slug;
        $this->getJson('/courses/paginate?category='.$slug)->assertJsonPath('meta.total', 1);
        $this->getJson('/courses/paginate?search=photoshop')->assertJsonPath('meta.total', 1);
        $this->getJson('/courses/paginate?search=inexistant')->assertJsonPath('meta.total', 0);

        $this->getJson('/course-categories')->assertOk()->assertJsonFragment(['coursesCount' => 1]);
    }

    public function test_paid_lessons_are_locked_for_anonymous_and_free_plan_users(): void
    {
        $course = $this->course();

        $this->getJson("/courses/{$course->slug}")
            ->assertOk()
            ->assertJsonPath('lessons.0.locked', false)
            ->assertJsonPath('lessons.0.videoUrl', 'https://www.youtube.com/watch?v=intro')
            ->assertJsonPath('lessons.1.locked', true)
            ->assertJsonPath('lessons.1.videoUrl', null)
            ->assertJsonPath('viewer.isAuthenticated', false);

        $free = $this->learner('Grouilleur');
        $this->actingAs($free, 'api')
            ->getJson("/courses/{$course->id}")
            ->assertJsonPath('lessons.1.locked', true)
            ->assertJsonPath('viewer.hasFullAccess', false);

        $lockedLesson = $course->lessons()->where('position', 2)->first();
        $this->actingAs($free, 'api')
            ->postJson("/lessons/{$lockedLesson->id}/progress", ['completed' => true])
            ->assertForbidden();
    }

    public function test_plan_with_formations_feature_unlocks_every_lesson(): void
    {
        $course = $this->course();

        $this->actingAs($this->learner('Standard'), 'api')
            ->getJson("/courses/{$course->id}")
            ->assertJsonPath('lessons.1.locked', false)
            ->assertJsonPath('lessons.1.videoUrl', 'https://cdn.example.com/calques.mp4')
            ->assertJsonPath('viewer.hasFullAccess', true);
    }

    public function test_free_course_is_open_to_everyone(): void
    {
        $course = $this->course(['is_free' => true]);

        $this->getJson("/courses/{$course->id}")->assertJsonPath('lessons.1.locked', false);
    }

    public function test_discovery_pass_unlocks_for_seven_days_and_only_once(): void
    {
        $course = $this->course();
        $user = $this->learner('Grouilleur');

        $this->actingAs($user, 'api')->getJson('/me/course-access')
            ->assertJsonPath('hasFullAccess', false)
            ->assertJsonPath('passAvailable', true);

        $this->actingAs($user, 'api')->postJson('/me/course-pass')
            ->assertCreated()
            ->assertJsonPath('hasFullAccess', true)
            ->assertJsonPath('pass.active', true);

        $this->actingAs($user, 'api')->getJson("/courses/{$course->id}")->assertJsonPath('lessons.1.locked', false);

        $this->actingAs($user, 'api')->postJson('/me/course-pass')->assertStatus(400);

        $this->travel(8)->days();
        $this->actingAs($user, 'api')->getJson("/courses/{$course->id}")->assertJsonPath('lessons.1.locked', true);
    }

    public function test_completing_every_lesson_completes_the_course_and_issues_a_certificate(): void
    {
        $course = $this->course();
        $user = $this->learner('Premium');
        [$first, $second] = $course->lessons()->get()->all();

        $this->actingAs($user, 'api')
            ->postJson("/lessons/{$first->id}/progress", ['positionSeconds' => 120])
            ->assertOk()
            ->assertJsonPath('completed', false)
            ->assertJsonPath('lastPositionSeconds', 120)
            ->assertJsonPath('enrollment.progress', 0);

        $this->actingAs($user, 'api')
            ->postJson("/lessons/{$first->id}/progress", ['completed' => true])
            ->assertJsonPath('enrollment.progress', 50);

        $this->travel(1)->minutes();
        $this->actingAs($user, 'api')
            ->postJson("/lessons/{$second->id}/progress", ['completed' => true, 'positionSeconds' => 600])
            ->assertJsonPath('enrollment.progress', 100)
            ->assertJsonPath('enrollment.status', 'COMPLETED')
            ->assertJsonPath('enrollment.hasCertificate', true);

        $this->actingAs($user, 'api')->getJson('/me/courses')
            ->assertOk()
            ->assertJsonPath('0.course.id', $course->id)
            ->assertJsonPath('0.lastLessonId', $second->id);

        $this->actingAs($user, 'api')->getJson("/courses/{$course->id}")
            ->assertJsonPath('lessons.0.progress.completed', true)
            ->assertJsonPath('viewer.enrollment.progress', 100);
    }

    public function test_bookmarks_round_trip(): void
    {
        $course = $this->course();
        $user = $this->learner();

        $this->actingAs($user, 'api')->postJson("/courses/{$course->id}/bookmark")->assertCreated();
        $this->actingAs($user, 'api')->postJson("/courses/{$course->id}/bookmark")->assertCreated();

        $this->actingAs($user, 'api')->getJson('/me/course-bookmarks')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $course->id);

        $this->actingAs($user, 'api')->getJson("/courses/{$course->id}")->assertJsonPath('viewer.isBookmarked', true);

        $this->actingAs($user, 'api')->deleteJson("/courses/{$course->id}/bookmark")->assertOk();
        $this->actingAs($user, 'api')->getJson('/me/course-bookmarks')->assertJsonCount(0);
    }

    public function test_learner_can_submit_update_and_delete_a_project(): void
    {
        $course = $this->course();
        $user = $this->learner();
        $other = $this->learner();

        $id = $this->actingAs($user, 'api')->post('/me/course-projects', [
            'title' => 'Affiche de concert',
            'courseId' => $course->id,
            'linkUrl' => 'https://behance.net/mon-projet',
            'file' => UploadedFile::fake()->create('affiche.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('hasFile', true)
            ->assertJsonPath('course.id', $course->id)
            ->json('id');

        // Modification multipart : POST + _method=PATCH.
        $this->actingAs($user, 'api')->post("/me/course-projects/{$id}", [
            '_method' => 'PATCH',
            'title' => 'Affiche de concert v2',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('title', 'Affiche de concert v2');

        $this->actingAs($user, 'api')->get("/me/course-projects/{$id}/file")->assertOk();
        $this->actingAs($other, 'api')->getJson("/me/course-projects/{$id}/file")->assertNotFound();

        $this->actingAs($user, 'api')->getJson('/me/course-projects')->assertJsonCount(1);
        $this->actingAs($user, 'api')->deleteJson("/me/course-projects/{$id}")->assertOk();
        $this->assertDatabaseMissing('course_projects', ['id' => $id]);
    }

    public function test_project_requires_a_title(): void
    {
        $this->actingAs($this->learner(), 'api')
            ->postJson('/me/course-projects', [])
            ->assertStatus(400);
    }
}
