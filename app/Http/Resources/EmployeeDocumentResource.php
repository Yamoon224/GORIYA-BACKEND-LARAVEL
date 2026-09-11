<?php

namespace App\Http\Resources;

use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EmployeeDocument',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'source', type: 'string', enum: ['UPLOAD', 'GENERATED', 'CONTRACT', 'PAYSLIP'], description: 'CONTRACT et PAYSLIP : documents d\'autres modules, en lecture seule'),
        new OA\Property(property: 'category', type: 'string'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'fileName', type: 'string'),
        new OA\Property(property: 'mimeType', type: 'string', nullable: true),
        new OA\Property(property: 'size', type: 'integer', nullable: true),
        new OA\Property(property: 'issuedAt', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'expiresAt', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'employee', type: 'object', nullable: true, description: "Nul pour un document d'entreprise"),
        new OA\Property(property: 'template', type: 'string', nullable: true),
        new OA\Property(property: 'hrRequestId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'downloadPath', type: 'string', description: "Chemin d'API du téléchargement"),
        new OA\Property(property: 'editable', type: 'boolean'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class EmployeeDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::fromDocument($this->resource);
    }

    /**
     * Forme commune à tous les documents de la liste, y compris ceux d'autres
     * modules (contrats, bulletins) qu'EmployeeDocumentService y ajoute.
     *
     * @return array<string, mixed>
     */
    public static function fromDocument(EmployeeDocument $document): array
    {
        return [
            'id' => $document->id,
            'source' => $document->source,
            'category' => $document->category?->value,
            'title' => $document->title,
            'description' => $document->description,
            'fileName' => $document->file_name,
            'mimeType' => $document->mime_type,
            'size' => $document->size,
            'issuedAt' => $document->issued_at?->toDateString(),
            'expiresAt' => $document->expires_at?->toDateString(),
            'employee' => $document->employee ? EmployeeResource::summary($document->employee) : null,
            'uploadedByName' => $document->uploader?->name,
            'template' => $document->template?->value,
            'hrRequestId' => $document->hr_request_id,
            // Jamais le chemin de stockage : l'API revérifie l'entreprise à chaque téléchargement.
            'downloadPath' => "/hr-documents/{$document->id}/download",
            'editable' => true,
            'createdAt' => $document->created_at?->toIso8601String(),
        ];
    }
}
