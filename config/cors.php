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

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Origines autorisées, séparées par des virgules (ex : gestion + site vitrine en production)
    'allowed_origins' => array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,http://localhost:3001'
    )))),

    // En développement : appli ouverte depuis un téléphone du réseau local (ex : http://192.168.1.230:3000)
    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^http://(192\.168|10|172\.(1[6-9]|2\d|3[01]))(\.\d{1,3}){2,3}(:\d+)?$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Le navigateur garde la réponse preflight (OPTIONS) en cache 2 h au lieu de la redemander à chaque requête
    'max_age' => 7200,

    'supports_credentials' => false,

];
