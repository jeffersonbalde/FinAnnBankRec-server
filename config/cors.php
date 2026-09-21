<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The React SPA (Vite dev server) calls this API from a different origin,
    | so the browser sends credentialed cross-origin requests. We allow the
    | frontend origin explicitly and permit cookies. In production the SPA is
    | usually served same-origin or behind a proxy, in which case CORS is not
    | exercised at all.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', (string) env(
        'FRONTEND_URLS',
        'http://localhost:5173,http://127.0.0.1:5173'
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
