<?php

use App\Support\FrontendOrigins;

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Allow the Plasma Controller SPA to call /api/* with a Google ID token
    | in the Authorization header. FRONTEND_URL may be a single origin or a
    | comma-separated list (e.g. local Vite + production).
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => FrontendOrigins::parse(env('FRONTEND_URL')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
