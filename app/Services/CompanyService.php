<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\Company;
use App\Models\User;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mirroir de backend/src/companies/companies.service.ts. Utilisé par les
 * contrôleurs Api (auto-inscription entreprise, validée) et Admin (création
 * côté admin, non validée par un Form Request — parité NestJS).
 */
class CompanyService
{
    use HandlesUniqueViolations, MapsFieldsToColumns;

    private const ALLOWED_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];

    private const UNIQUE_MESSAGES = [
        'email' => 'Cette adresse email est déjà utilisée',
        'name' => "Le nom de l'entreprise est déjà utilisé",
        'phone' => 'Ce numéro de téléphone est déjà utilisé',
    ];

    public function __construct(
        private readonly CompanyRepositoryInterface $companyRepository,
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * @param  array{logo?: UploadedFile, coverImage?: UploadedFile}  $files
     * @return array{company: Company, user: User, accessToken: string}
     */
    public function create(array $data, array $files = []): array
    {
        $socialLinks = $this->decodeSocialLinks($data['socialLinks'] ?? null);

        try {
            return DB::transaction(function () use ($data, $files, $socialLinks) {
                $companyPayload = [
                    'name' => $data['companyName'],
                    'sector' => $data['sector'],
                    'about' => $data['about'] ?? null,
                    'creation_date' => $data['creationDate'] ?? null,
                    'company_size' => $data['companySize'] ?? null,
                    'website' => $data['website'] ?? null,
                    'social_links' => $socialLinks,
                    'country' => $data['country'] ?? null,
                    'headquarters' => $data['headquarters'] ?? null,
                    'location' => $data['location'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'],
                    'status' => $data['status'] ?? CompanyStatus::ACTIVE->value,
                    'partnership_date' => $data['partnershipDate'],
                ];

                if (! empty($files['logo'])) {
                    $companyPayload['logo'] = $this->storeCompanyFile($files['logo']);
                }
                if (! empty($files['coverImage'])) {
                    $companyPayload['cover_image'] = $this->storeCompanyFile($files['coverImage']);
                }

                $company = $this->companyRepository->create($companyPayload);

                $user = $this->userRepository->create([
                    'name' => $data['companyName'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'role' => UserRole::ENTERPRISE->value,
                    'status' => UserStatus::ACTIVE->value,
                    'company_id' => $company->id,
                    'registration_date' => now(),
                ]);

                $accessToken = auth('api')->login($user);

                return ['company' => $company, 'user' => $user, 'accessToken' => $accessToken];
            });
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, self::UNIQUE_MESSAGES);
        }
    }

    /**
     * @param  array{logo?: UploadedFile, coverImage?: UploadedFile}  $files
     */
    public function update(Company $company, array $data, array $files = []): Company
    {
        $mapped = [];

        if (! empty($files['logo'])) {
            if ($company->logo) {
                $this->deleteCompanyFile($company->logo);
            }
            $mapped['logo'] = $this->storeCompanyFile($files['logo']);
        }

        if (! empty($files['coverImage'])) {
            if ($company->cover_image) {
                $this->deleteCompanyFile($company->cover_image);
            }
            $mapped['cover_image'] = $this->storeCompanyFile($files['coverImage']);
        }

        if (array_key_exists('socialLinks', $data)) {
            $mapped['social_links'] = $this->decodeSocialLinks($data['socialLinks']);
        }

        if (array_key_exists('companyName', $data)) {
            $mapped['name'] = $data['companyName'];
        }

        $mapped += $this->mapFields($data, [
            'sector' => 'sector',
            'about' => 'about',
            'website' => 'website',
            'companySize' => 'company_size',
            'country' => 'country',
            'headquarters' => 'headquarters',
            'location' => 'location',
            'phone' => 'phone',
            'email' => 'email',
            'status' => 'status',
            'creationDate' => 'creation_date',
            'partnershipDate' => 'partnership_date',
        ]);

        try {
            $this->companyRepository->update($company, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, self::UNIQUE_MESSAGES);
        }

        return $company->fresh();
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->companyRepository->paginate($page, $limit, $filters);
    }

    /**
     * Secteurs distincts (avec nombre d'entreprises), pour alimenter le
     * filtre "Secteur d'activité" public — évite une liste de secteurs
     * codée en dur côté frontend qui ne correspond pas forcément aux
     * valeurs réelles en base.
     *
     * @return list<array{name: string, count: int}>
     */
    public function sectors(): array
    {
        return Company::query()
            ->whereNotNull('sector')
            ->where('sector', '!=', '')
            ->selectRaw('sector as name, count(*) as count')
            ->groupBy('sector')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->count])
            ->all();
    }

    public function remove(Company $company): void
    {
        if ($company->logo) {
            $this->deleteCompanyFile($company->logo);
        }
        if ($company->cover_image) {
            $this->deleteCompanyFile($company->cover_image);
        }

        $this->companyRepository->delete($company);
    }

    private function decodeSocialLinks(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            abort(400, 'socialLinks mal formé');
        }

        return $decoded ?? [];
    }

    private function storeCompanyFile(UploadedFile $file): string
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_IMAGE_TYPES, true)) {
            abort(400, 'Unsupported image type');
        }

        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        Storage::disk('public')->putFileAs('companies', $file, $filename);

        return "/companies/{$filename}";
    }

    private function deleteCompanyFile(string $path): void
    {
        Storage::disk('public')->delete('companies/'.basename($path));
    }
}
