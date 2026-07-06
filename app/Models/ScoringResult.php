<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Enums\ScoringStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScoringResult extends Model
{
    use HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'candidate_name',
        'candidate_email',
        'position',
        'overall_score',
        'criteria',
        'analysis_date',
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
            'status' => ScoringStatus::class,
            'analysis_date' => 'datetime',
            'criteria' => 'array',
        ];
    }
}
