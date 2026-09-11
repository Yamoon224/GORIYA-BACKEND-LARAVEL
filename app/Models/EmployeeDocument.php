<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\EmployeeDocumentCategory;
use App\Enums\HrDocumentTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document RH rangé par l'entreprise : rattaché à un employé, ou à
 * l'entreprise elle-même quand `employee_id` est nul.
 */
class EmployeeDocument extends Model
{
    use Auditable, HasUuid;

    public const SOURCE_UPLOAD = 'UPLOAD';

    public const SOURCE_GENERATED = 'GENERATED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'category',
        'title',
        'description',
        'file_path',
        'file_name',
        'mime_type',
        'size',
        'issued_at',
        'expires_at',
        'source',
        'template',
        'hr_request_id',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => EmployeeDocumentCategory::class,
            'template' => HrDocumentTemplate::class,
            'issued_at' => 'date',
            'expires_at' => 'date',
            'size' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function hrRequest(): BelongsTo
    {
        return $this->belongsTo(HrRequest::class);
    }
}
