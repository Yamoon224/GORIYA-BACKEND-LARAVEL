<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Enums\JobExperienceType;
use App\Enums\JobStatus;
use App\Enums\JobType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobOffer extends Model
{
    use HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'location',
        'salary',
        'type',
        'experience',
        'description',
        'benefits',
        'requirements',
        'status',
        'publish_date',
        'end_date',
        'applicants',
        'company_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => JobType::class,
            'experience' => JobExperienceType::class,
            'status' => JobStatus::class,
            'publish_date' => 'date',
            'end_date' => 'date',
            'requirements' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function candidatures(): HasMany
    {
        return $this->hasMany(Candidature::class);
    }
}
