<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MyInterviewResource;
use App\Services\RecruitmentService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'MyInterviews', description: "Entretiens du candidat authentifié, tous recruteurs confondus")]
class MyInterviewsController extends Controller
{
    public function __construct(private readonly RecruitmentService $recruitment) {}

    #[OA\Get(
        path: '/me/interviews',
        tags: ['MyInterviews'],
        summary: "Liste les entretiens (GORIYA Meet ou présentiel) planifiés pour le candidat authentifié",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Entretiens', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/MyInterview'))),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index(Request $request)
    {
        return MyInterviewResource::collection($this->recruitment->listInterviewsForUser($request->user()->id));
    }
}
