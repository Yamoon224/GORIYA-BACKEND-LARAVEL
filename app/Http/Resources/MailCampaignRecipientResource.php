<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MailCampaignRecipientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'errorMessage' => $this->error_message,
            'sentAt' => $this->sent_at,
            'partner' => [
                'id' => $this->partner?->id,
                'companyName' => $this->partner?->company_name,
                'email' => $this->partner?->email,
            ],
        ];
    }
}
