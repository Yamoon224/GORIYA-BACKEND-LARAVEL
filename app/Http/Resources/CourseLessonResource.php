<?php

namespace App\Http\Resources;

use App\Models\CourseLesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Leçon d'un cours. Côté apprenant, construire via `forViewer()` : si la
 * leçon est verrouillée, `videoUrl`, `subtitles` et `resources` sont
 * retirés (les liens externes ne doivent pas fuiter). Côté admin, la
 * ressource brute expose tout.
 */
#[OA\Schema(
    schema: 'CourseLesson',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'courseId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'section', type: 'string', nullable: true),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'videoUrl', type: 'string', nullable: true),
        new OA\Property(property: 'videoProvider', type: 'string', enum: ['YOUTUBE', 'VIMEO', 'FILE']),
        new OA\Property(property: 'durationSeconds', type: 'integer'),
        new OA\Property(property: 'position', type: 'integer'),
        new OA\Property(property: 'isFreePreview', type: 'boolean'),
        new OA\Property(property: 'locked', type: 'boolean'),
        new OA\Property(property: 'subtitles', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'resources', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'progress', type: 'object', nullable: true),
    ]
)]
class CourseLessonResource extends JsonResource
{
    private bool $locked = false;

    /** @var array{lastPositionSeconds: int, completed: bool}|null */
    private ?array $progress = null;

    public static function forViewer(CourseLesson $lesson, bool $canWatch, ?array $progress): self
    {
        $resource = new self($lesson);
        $resource->locked = ! $canWatch;
        $resource->progress = $progress;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'courseId' => $this->course_id,
            'section' => $this->section,
            'title' => $this->title,
            'description' => $this->description,
            'videoUrl' => $this->locked ? null : $this->video_url,
            'videoProvider' => $this->video_provider,
            'durationSeconds' => (int) $this->duration_seconds,
            'position' => (int) $this->position,
            'isFreePreview' => (bool) $this->is_free_preview,
            'locked' => $this->locked,
            'subtitles' => $this->locked ? [] : ($this->subtitles ?? []),
            'resources' => $this->locked ? [] : ($this->resources ?? []),
            'progress' => $this->progress,
        ];
    }
}
