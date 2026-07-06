<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/admin/admin-response.ts. N'utiliser QUE depuis les
 * contrôleurs Admin — les contrôleurs Api renvoient du JSON brut (parité avec
 * le comportement actuel du backend NestJS, voir Section 3 du plan).
 */
#[OA\Schema(
    schema: 'PaginationMeta',
    properties: [
        new OA\Property(property: 'total', type: 'integer', example: 42),
        new OA\Property(property: 'page', type: 'integer', example: 1),
        new OA\Property(property: 'limit', type: 'integer', example: 10),
        new OA\Property(property: 'totalPages', type: 'integer', example: 5),
    ]
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Resource not found'),
    ]
)]
class ApiResponse
{
    public static function success(mixed $data, ?string $message = null, int $status = 200): JsonResponse
    {
        $payload = ['success' => true, 'data' => $data];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status);
    }

    public static function paginated(LengthAwarePaginator $paginator): JsonResponse
    {
        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'totalPages' => $paginator->lastPage(),
            ],
        ]);
    }
}
