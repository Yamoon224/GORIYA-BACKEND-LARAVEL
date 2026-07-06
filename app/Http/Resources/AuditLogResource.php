<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AuditLog',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'userId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'userName', type: 'string', nullable: true),
        new OA\Property(property: 'userEmail', type: 'string', nullable: true),
        new OA\Property(property: 'userRole', type: 'string', nullable: true),
        new OA\Property(property: 'action', type: 'string', example: 'updated'),
        new OA\Property(property: 'auditableType', type: 'string', nullable: true),
        new OA\Property(property: 'auditableId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'oldValues', type: 'object', nullable: true),
        new OA\Property(property: 'newValues', type: 'object', nullable: true),
        new OA\Property(property: 'url', type: 'string', nullable: true),
        new OA\Property(property: 'method', type: 'string', nullable: true),
        new OA\Property(property: 'ipAddress', type: 'string', nullable: true),
        new OA\Property(property: 'userAgent', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'userName' => $this->user_name,
            'userEmail' => $this->user_email,
            'userRole' => $this->user_role,
            'action' => $this->action,
            'auditableType' => $this->auditable_type,
            'auditableId' => $this->auditable_id,
            'oldValues' => $this->old_values,
            'newValues' => $this->new_values,
            'url' => $this->url,
            'method' => $this->method,
            'ipAddress' => $this->ip_address,
            'userAgent' => $this->user_agent,
            'createdAt' => $this->created_at,
        ];
    }
}
