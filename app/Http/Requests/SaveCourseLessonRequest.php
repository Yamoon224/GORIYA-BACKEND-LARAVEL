<?php

namespace App\Http\Requests;

use App\Enums\VideoProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Leçon d'un cours : la vidéo est un lien externe (YouTube, Vimeo ou
 * fichier MP4/WebM direct). Le fournisseur est déduit de l'URL s'il n'est
 * pas précisé.
 */
#[OA\Schema(
    schema: 'SaveCourseLessonRequest',
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'section', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'videoUrl', type: 'string', nullable: true),
        new OA\Property(property: 'videoProvider', type: 'string', enum: ['YOUTUBE', 'VIMEO', 'FILE']),
        new OA\Property(property: 'durationSeconds', type: 'integer'),
        new OA\Property(property: 'isFreePreview', type: 'boolean'),
        new OA\Property(property: 'subtitles', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'lang', type: 'string'),
            new OA\Property(property: 'label', type: 'string'),
            new OA\Property(property: 'url', type: 'string'),
        ])),
        new OA\Property(property: 'resources', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'url', type: 'string'),
        ])),
    ]
)]
class SaveCourseLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255'],
            'section' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'videoUrl' => ['sometimes', 'nullable', 'url', 'max:500'],
            'videoProvider' => ['sometimes', Rule::enum(VideoProvider::class)],
            'durationSeconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'isFreePreview' => ['sometimes', 'boolean'],
            'subtitles' => ['sometimes', 'nullable', 'array', 'max:10'],
            'subtitles.*.lang' => ['required', 'string', 'max:5'],
            'subtitles.*.label' => ['nullable', 'string', 'max:50'],
            'subtitles.*.url' => ['required', 'url', 'max:500'],
            'resources' => ['sometimes', 'nullable', 'array', 'max:20'],
            'resources.*.title' => ['required', 'string', 'max:255'],
            'resources.*.url' => ['required', 'url', 'max:500'],
        ];
    }
}
