<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Enums\RecruitmentStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Passage d'une étape à l'autre du pipeline, daté et signé. `from_stage` est
 * nul pour une candidature qui n'avait jamais été déplacée.
 */
class RecruitmentStageEvent extends Model
{
    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'candidature_id',
        'from_stage',
        'to_stage',
        'comment',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_stage' => RecruitmentStage::class,
            'to_stage' => RecruitmentStage::class,
        ];
    }

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
