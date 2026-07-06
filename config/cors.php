<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // Pas de préfixe /api (voir bootstrap/app.php) : toutes les routes sont concernées.
    'paths' => ['*'],

    'allowed_methods' => ['GET', 'HEAD', 'PUT', 'PATCH', 'POST', 'DELETE'],

    // Même liste que backend/src/main.ts
    'allowed_origins' => [
        'https://goriya-entreprise.vercel.app',
        'https://goriya-standard.vercel.app',
        'https://goriya-admin.vercel.app',
        'http://localhost:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
