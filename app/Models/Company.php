<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'sector',
        'logo',
        'cover_image',
        'about',
        'website',
        'creation_date',
        'partnership_date',
        'company_size',
        'social_links',
        'country',
        'headquarters',
        'location',
        'phone',
        'email',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'creation_date' => 'date',
            'partnership_date' => 'date',
            'social_links' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function jobOffers(): HasMany
    {
        return $this->hasMany(JobOffer::class);
    }
}
