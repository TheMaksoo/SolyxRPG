<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Sanctum's SPA cookie auth requires the server to echo back a specific origin (never '*') and
    // set Access-Control-Allow-Credentials — without both, browsers silently refuse to persist/send
    // the session cookie on any cross-origin request (e.g. the Vite dev server on its own port),
    // which is exactly what was causing every request to look like a brand-new anonymous visitor.
    'allowed_origins' => array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173,http://127.0.0.1:5173,http://localhost:8000,http://127.0.0.1:8000,http://solyxrpg.test:8000,http://solyxrpg.test'
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
