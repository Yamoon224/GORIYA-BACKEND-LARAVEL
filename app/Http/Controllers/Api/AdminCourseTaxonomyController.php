<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseCategoryResource;
use App\Http\Resources\InstructorResource;
use App\Models\CourseCategory;
use App\Models\Instructor;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Référentiels du module Formation (rôle ADMIN) : catégories métiers et
 * instructeurs. CRUD simple, sans service dédié.
 */
#[OA\Tag(name: 'Admin Course Taxonomy', description: 'Catégories et instructeurs du module Formation')]
class AdminCourseTaxonomyController extends Controller
{
    /*
    |----------------------------------------------------------------------
    | Catégories
    |----------------------------------------------------------------------
    */

    public function categories()
    {
        $categories = CourseCategory::withCount('courses')->orderBy('sort_order')->orderBy('name')->get();

        return ApiResponse::success(CourseCategoryResource::collection($categories)->resolve());
    }

    public function storeCategory(Request $request)
    {
        $category = CourseCategory::create($this->categoryPayload($request, true));

        return ApiResponse::success((new CourseCategoryResource($category))->resolve(), status: 201);
    }

    public function updateCategory(string $id, Request $request)
    {
        $category = CourseCategory::find($id) ?? abort(404, 'Catégorie introuvable');
        $category->update($this->categoryPayload($request, false, $category));

        return ApiResponse::success((new CourseCategoryResource($category))->resolve());
    }

    /**
     * Les cours rattachés perdent leur catégorie (nullOnDelete) mais ne
     * sont pas supprimés.
     */
    public function destroyCategory(string $id)
    {
        $category = CourseCategory::find($id) ?? abort(404, 'Catégorie introuvable');
        $category->delete();

        return ApiResponse::success(null, 'Catégorie supprimée');
    }

    private function categoryPayload(Request $request, bool $creating, ?CourseCategory $category = null): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:120', Rule::unique('course_categories', 'name')->ignore($category?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:60'],
            'coverPath' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        return $this->toColumns($data, [
            'name' => 'name', 'description' => 'description', 'icon' => 'icon',
            'coverPath' => 'cover_path', 'sortOrder' => 'sort_order', 'isActive' => 'is_active',
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | Instructeurs
    |----------------------------------------------------------------------
    */

    public function instructors()
    {
        $instructors = Instructor::withCount('courses')->orderBy('name')->get();

        return ApiResponse::success(InstructorResource::collection($instructors)->resolve());
    }

    public function storeInstructor(Request $request)
    {
        $instructor = Instructor::create($this->instructorPayload($request, true));

        return ApiResponse::success((new InstructorResource($instructor))->resolve(), status: 201);
    }

    public function updateInstructor(string $id, Request $request)
    {
        $instructor = Instructor::find($id) ?? abort(404, 'Instructeur introuvable');
        $instructor->update($this->instructorPayload($request, false));

        return ApiResponse::success((new InstructorResource($instructor))->resolve());
    }

    public function destroyInstructor(string $id)
    {
        $instructor = Instructor::find($id) ?? abort(404, 'Instructeur introuvable');
        $instructor->delete();

        return ApiResponse::success(null, 'Instructeur supprimé');
    }

    private function instructorPayload(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'photoPath' => ['sometimes', 'nullable', 'string', 'max:500'],
            'country' => ['sometimes', 'nullable', 'string', 'max:80'],
            'linkedinUrl' => ['sometimes', 'nullable', 'url', 'max:255'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        return $this->toColumns($data, [
            'name' => 'name', 'headline' => 'headline', 'bio' => 'bio', 'photoPath' => 'photo_path',
            'country' => 'country', 'linkedinUrl' => 'linkedin_url', 'isActive' => 'is_active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $map
     * @return array<string, mixed>
     */
    private function toColumns(array $data, array $map): array
    {
        $columns = [];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $columns[$column] = $data[$key];
            }
        }

        return $columns;
    }
}
