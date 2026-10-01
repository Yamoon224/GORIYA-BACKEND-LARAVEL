<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Projet « Mes projets ». Envoyé en multipart (fichier facultatif) : la
 * modification passe par POST + `_method=PATCH`, PHP ignorant le corps
 * multipart d'un vrai PATCH.
 */
class SaveCourseProjectRequest extends FormRequest
{
    public const MIMES = 'pdf,zip,png,jpg,jpeg,webp,doc,docx,ppt,pptx,xls,xlsx,txt';

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
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'linkUrl' => ['sometimes', 'nullable', 'url', 'max:500'],
            'courseId' => ['sometimes', 'nullable', 'uuid', 'exists:courses,id'],
            'file' => ['sometimes', 'nullable', 'file', 'mimes:'.self::MIMES, 'max:20480'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Formats acceptés : PDF, ZIP, images, documents Office ou texte.',
            'file.max' => 'Le fichier ne doit pas dépasser 20 Mo.',
        ];
    }
}
