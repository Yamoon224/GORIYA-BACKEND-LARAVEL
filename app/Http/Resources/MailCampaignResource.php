<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MailCampaign',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'subject', type: 'string'),
        new OA\Property(property: 'bodyHtml', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'sending', 'sent', 'failed']),
        new OA\Property(property: 'targetFilters', type: 'object', nullable: true),
        new OA\Property(property: 'totalRecipients', type: 'integer'),
        new OA\Property(property: 'sentCount', type: 'integer'),
        new OA\Property(property: 'failedCount', type: 'integer'),
        new OA\Property(property: 'sentAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class MailCampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subject' => $this->subject,
            'bodyHtml' => $this->body_html,
            'status' => $this->status->value,
            'targetFilters' => $this->target_filters,
            'totalRecipients' => $this->total_recipients,
            'sentCount' => $this->sent_count,
            'failedCount' => $this->failed_count,
            'sentAt' => $this->sent_at,
            'createdAt' => $this->created_at,
        ];
    }
}
