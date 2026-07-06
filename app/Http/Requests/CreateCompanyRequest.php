<?php

namespace App\Http\Requests;

use App\Enums\CompanyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateCompanyRequest',
    required: ['companyName', 'sector', 'email', 'password', 'partnershipDate'],
    properties: [
        new OA\Property(property: 'companyName', type: 'string'),
        new OA\Property(property: 'sector', type: 'string'),
        new OA\Property(property: 'about', type: 'string', nullable: true),
        new OA\Property(property: 'creationDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'companySize', type: 'string', nullable: true),
        new OA\Property(property: 'website', type: 'string', nullable: true),
        new OA\Property(property: 'socialLinks', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'country', type: 'string', nullable: true),
        new OA\Property(property: 'headquarters', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'password', type: 'string', format: 'password'),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'SUSPENDED'], nullable: true),
        new OA\Property(property: 'partnershipDate', type: 'string', format: 'date'),
        new OA\Property(property: 'logo', type: 'string', format: 'binary', nullable: true, description: 'image/png, jpeg, jpg ou webp'),
        new OA\Property(property: 'coverImage', type: 'string', format: 'binary', nullable: true, description: 'image/png, jpeg, jpg ou webp'),
    ]
)]
class CreateCompanyRequest extends FormRequest
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
        return [
            'companyName' => ['required', 'string'],
            'sector' => ['required', 'string'],
            'about' => ['nullable', 'string'],
            'creationDate' => ['nullable', 'date'],
            'companySize' => ['nullable', 'string'],
            'website' => ['nullable', 'string'],
            // Peut arriver en JSON string (multipart) ou en tableau (JSON body) —
            // le décodage/la validation de forme se fait dans le contrôleur.
            'socialLinks' => ['nullable'],
            'country' => ['nullable', 'string'],
            'headquarters' => ['nullable', 'string'],
            'location' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            // Optionnels côté DTO Nest mais exigés par le service : on encode
            // directement en required ici pour produire le même 400.
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
            'status' => ['nullable', Rule::enum(CompanyStatus::class)],
            'partnershipDate' => ['required', 'date'],
            'logo' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/jpg,image/webp'],
            'coverImage' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/jpg,image/webp'],
        ];
    }
}
