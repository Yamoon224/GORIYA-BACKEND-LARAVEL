<?php

namespace App\Services;

use App\Enums\PotentialPartnerStatus;
use App\Models\PotentialPartner;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * CRUD + recherche du module Potentiels Partenaires. L'import en masse (CSV)
 * vit dans PotentialPartnerImportService — volumétrie et besoins de
 * performance différents d'un CRUD classique.
 */
class PotentialPartnerService
{
    /**
     * @param  array{search?: ?string, sector?: ?string, city?: ?string, companySize?: ?string, status?: ?string, hasEmail?: ?bool}  $filters
     */
    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->applyFilters(PotentialPartner::query(), $filters)
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Partenaires effectivement joignables (email valide, pas désabonné, pas
     * en do_not_contact) correspondant aux filtres — utilisé par
     * MailCampaignService pour résoudre les destinataires d'une campagne à
     * partir des mêmes filtres que la liste admin.
     *
     * @param  array{search?: ?string, sector?: ?string, city?: ?string, companySize?: ?string, status?: ?string}  $filters
     * @return Collection<int, PotentialPartner>
     */
    public function reachable(array $filters = []): Collection
    {
        return $this->applyFilters(PotentialPartner::query(), $filters)
            ->where('email_valid', true)
            ->whereNull('unsubscribed_at')
            ->where('status', '!=', PotentialPartnerStatus::DO_NOT_CONTACT->value)
            ->get();
    }

    /**
     * @param  array{search?: ?string, sector?: ?string, city?: ?string, companySize?: ?string, status?: ?string, hasEmail?: ?bool}  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('ncc', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['sector'])) {
            $query->where('sector', $filters['sector']);
        }

        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        if (! empty($filters['companySize'])) {
            $query->where('company_size', $filters['companySize']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('hasEmail', $filters) && $filters['hasEmail'] !== null) {
            $query->where('email_valid', (bool) $filters['hasEmail']);
        }

        return $query;
    }

    /**
     * @return array{total: int, new: int, contacted: int, interested: int, converted: int, withValidEmail: int}
     */
    public function stats(): array
    {
        return [
            'total' => PotentialPartner::count(),
            'new' => PotentialPartner::where('status', PotentialPartnerStatus::NEW)->count(),
            'contacted' => PotentialPartner::where('status', PotentialPartnerStatus::CONTACTED)->count(),
            'interested' => PotentialPartner::where('status', PotentialPartnerStatus::INTERESTED)->count(),
            'converted' => PotentialPartner::where('status', PotentialPartnerStatus::CONVERTED)->count(),
            'withValidEmail' => PotentialPartner::where('email_valid', true)->whereNull('unsubscribed_at')->count(),
        ];
    }

    /**
     * @return list<string>
     */
    public function sectors(): array
    {
        return $this->distinctColumn('sector');
    }

    /**
     * @return list<string>
     */
    public function cities(): array
    {
        return $this->distinctColumn('city');
    }

    /**
     * @return list<string>
     */
    private function distinctColumn(string $column): array
    {
        return PotentialPartner::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }

    public function create(array $data): PotentialPartner
    {
        $email = isset($data['email']) ? Str::lower(trim($data['email'])) : null;

        return PotentialPartner::create([
            'ncc' => $data['ncc'] ?? null,
            'company_name' => $data['companyName'],
            'email' => $email ?: null,
            'email_valid' => $email ? filter_var($email, FILTER_VALIDATE_EMAIL) !== false : false,
            'contact_name' => $data['contactName'] ?? null,
            'contact_phone' => $data['contactPhone'] ?? null,
            'sector' => $data['sector'] ?? null,
            'activity_label' => $data['activityLabel'] ?? null,
            'city' => $data['city'] ?? null,
            'commune' => $data['commune'] ?? null,
            'address' => $data['address'] ?? null,
            'company_size' => $data['companySize'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? PotentialPartnerStatus::NEW->value,
            'source' => 'manual',
        ]);
    }

    public function update(PotentialPartner $partner, array $data): PotentialPartner
    {
        $mapped = [];

        $fieldMap = [
            'ncc' => 'ncc',
            'companyName' => 'company_name',
            'contactName' => 'contact_name',
            'contactPhone' => 'contact_phone',
            'sector' => 'sector',
            'activityLabel' => 'activity_label',
            'city' => 'city',
            'commune' => 'commune',
            'address' => 'address',
            'companySize' => 'company_size',
            'notes' => 'notes',
            'status' => 'status',
        ];

        foreach ($fieldMap as $input => $column) {
            if (array_key_exists($input, $data)) {
                $mapped[$column] = $data[$input];
            }
        }

        if (array_key_exists('email', $data)) {
            $email = $data['email'] ? Str::lower(trim($data['email'])) : null;
            $mapped['email'] = $email;
            $mapped['email_valid'] = $email ? filter_var($email, FILTER_VALIDATE_EMAIL) !== false : false;
        }

        $partner->update($mapped);

        return $partner->fresh();
    }

    public function updateStatus(PotentialPartner $partner, string $status): PotentialPartner
    {
        $partner->update(['status' => $status]);

        return $partner->fresh();
    }

    public function delete(PotentialPartner $partner): void
    {
        $partner->delete();
    }

    public function unsubscribe(PotentialPartner $partner): void
    {
        $partner->update([
            'unsubscribed_at' => now(),
            'status' => PotentialPartnerStatus::DO_NOT_CONTACT->value,
        ]);
    }
}
