<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Enums\CVStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CvAnalysis extends Model
{
    use HasFactory, HasUuid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cv_analysis';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'filename',
        'analysis_score',
        'recommendations',
        'upload_date',
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
            'status' => CVStatus::class,
            'upload_date' => 'datetime',
            'recommendations' => 'array',
        ];
    }
}
