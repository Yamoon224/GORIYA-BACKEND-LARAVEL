<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Note d'un recruteur sur un candidat, invisible du candidat. */
class RecruitmentNote extends Model
{
    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'candidature_id',
        'body',
        'created_by',
    ];

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
