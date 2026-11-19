<?php

return [
    // The React app uses Bearer tokens (not cookie sessions), so no stateful domains.
    'stateful' => [],

    'guard' => ['web'],

    // Tokens expire automatically (minutes). Prune old ones with `php artisan sanctum:prune-expired`.
    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 480),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'jc_'),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
