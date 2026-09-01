<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubscribeNewsletterRequest;
use App\Models\NewsletterSubscriber;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Newsletter', description: 'Inscription à la newsletter Goriya')]
class NewsletterController extends Controller
{
    #[OA\Post(
        path: '/newsletter/subscribe',
        tags: ['Newsletter'],
        summary: 'Inscrit une adresse e-mail à la newsletter (idempotent)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/SubscribeNewsletterRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Inscription enregistrée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'alreadySubscribed', type: 'boolean'),
                ])
            ),
        ]
    )]
    public function subscribe(SubscribeNewsletterRequest $request)
    {
        $data = $request->validated();
        $email = Str::lower(trim($data['email']));

        $subscriber = NewsletterSubscriber::firstOrNew(['email' => $email]);
        $alreadySubscribed = $subscriber->exists && $subscriber->unsubscribed_at === null;

        $subscriber->unsubscribed_at = null;
        if (! $subscriber->exists) {
            $subscriber->source = $data['source'] ?? null;
        }
        $subscriber->save();

        return response()->json([
            'message' => $alreadySubscribed
                ? 'Cette adresse est déjà inscrite à la newsletter.'
                : 'Merci ! Ton inscription à la newsletter est confirmée.',
            'alreadySubscribed' => $alreadySubscribed,
        ]);
    }
}
