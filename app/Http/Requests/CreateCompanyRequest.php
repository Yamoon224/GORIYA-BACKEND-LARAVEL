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
            //
            // `email` + `unique:users` : sans ça, un email déjà pris n'était
            // détecté qu'au moment de l'INSERT (violation d'unicité traduite
            // par HandlesUniqueViolations), après création de la company dans
            // la transaction — et un email mal formé passait jusqu'à l'envoi
            // du code OTP, qui échouait silencieusement.
            'email' => ['required', 'string', 'email:filter', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'status' => ['nullable', Rule::enum(CompanyStatus::class)],
            'partnershipDate' => ['required', 'date'],
            'logo' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/jpg,image/webp'],
            'coverImage' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/jpg,image/webp'],
        ];
    }

    /**
     * Messages métier explicites : le rendu d'exception global ne renvoie que
     * le PREMIER message de validation (voir bootstrap/app.php), c'est donc
     * lui que l'utilisateur voit dans le toast du formulaire d'inscription.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'companyName.required' => "Le nom de l'entreprise est obligatoire.",
            'sector.required' => "Le secteur d'activité est obligatoire.",
            'email.required' => "L'email professionnel est obligatoire.",
            'email.email' => "L'email professionnel n'est pas une adresse valide.",
            'email.unique' => 'Cette adresse email est déjà utilisée par un compte existant.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'creationDate.date' => "La date de création n'est pas une date valide.",
            'logo.mimetypes' => 'Le logo doit être une image PNG, JPEG, JPG ou WEBP.',
            'coverImage.mimetypes' => "L'image de couverture doit être une image PNG, JPEG, JPG ou WEBP.",
        ];
    }
}
