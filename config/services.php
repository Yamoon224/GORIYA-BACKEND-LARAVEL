<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // Connexion / inscription via Google (Google Identity Services).
    // GOOGLE_CLIENT_IDS : liste séparée par des virgules des « Client ID »
    // OAuth 2.0 (type Web) autorisés — un par front qui envoie un jeton
    // (candidats + espace entreprise). Le backend vérifie que l'`aud` du
    // jeton Google fait partie de cette liste avant d'ouvrir une session.
    'google' => [
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_CLIENT_IDS', (string) env('GOOGLE_CLIENT_ID', '')))
        ))),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    'kkiapay' => [
        'public_key' => env('KKIAPAY_PUBLIC_KEY'),
        'private_key' => env('KKIAPAY_PRIVATE_KEY'),
        'secret' => env('KKIAPAY_SECRET'),
        'sandbox' => env('KKIAPAY_SANDBOX', true),
    ],

    'wave' => [
        'key' => env('WAVE_API_KEY'),
    ],

    // Paiement Pro (agrégateur Côte d'Ivoire : Mobile Money OM/MTN/Moov, carte,
    // PayPal). Flux "session hébergée" comme Wave/Stripe — voir
    // PaiementProService. Le résultat définitif arrive via la notification
    // serveur-à-serveur sur /api/webhooks/paiementpro (pas d'API de statut).
    // currency_code 952 = XOF. channel vide => page de choix du moyen de
    // paiement hébergée par Paiement Pro.
    'paiementpro' => [
        'merchant_id' => env('PAIEMENTPRO_MERCHANT_ID'),
        'base_url' => env('PAIEMENTPRO_BASE_URL', 'https://www.paiementpro.net'),
        'currency_code' => env('PAIEMENTPRO_CURRENCY_CODE', '952'),
        'channel' => env('PAIEMENTPRO_CHANNEL'),
        'default_phone' => env('PAIEMENTPRO_DEFAULT_PHONE', '0000000000'),
        'notification_token' => env('PAIEMENTPRO_NOTIFICATION_TOKEN'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Push notifications (app mobile). Voir FcmPushNotificationService —
    // sans compte de service configuré, no-op silencieux (log seulement).
    'fcm' => [
        'service_account_path' => env('FCM_SERVICE_ACCOUNT_PATH'),
        'project_id' => env('FCM_PROJECT_ID'),
    ],

    // Studio IA — Avatar animé (Pitch Goriya). Voir DIdAvatarService.
    'd_id' => [
        'api_key' => env('D_ID_API_KEY'),
        'base_url' => env('D_ID_BASE_URL', 'https://api.d-id.com'),
    ],

    // GORIYA Call — visioconférence (lunion.meet, SFU self-hosted Lunion-Lab,
    // https://meet.lunion-lab.com/docs). Voir LunionMeetService. webhook_secret
    // est le secret configuré côté dashboard lunion.meet pour cet endpoint
    // (distinct de api_key), utilisé pour vérifier X-Lunion-Signature.
    'lunion_meet' => [
        'api_key' => env('LUNION_MEET_API_KEY'),
        'base_url' => env('LUNION_MEET_BASE_URL', 'https://meet.lunion-lab.com/api/v1'),
        'webhook_secret' => env('LUNION_MEET_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Liste des gateways réellement actifs en production, résolue par
    | PaymentGatewayManager. Wave et Stripe sont implémentés (voir
    | WaveService/StripeService) mais volontairement absents de la liste par
    | défaut tant que le besoin business n'est pas confirmé — activables sans
    | déploiement de code via PAYMENT_ENABLED_GATEWAYS="kkiapay,stripe".
    |
    */
    'payment' => [
        'enabled_gateways' => array_filter(explode(',', env('PAYMENT_ENABLED_GATEWAYS', 'kkiapay'))),
        'default_gateway' => env('PAYMENT_DEFAULT_GATEWAY', 'kkiapay'),
    ],

];
