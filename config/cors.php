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

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Le navigateur memorise la verification CORS (requete OPTIONS) 2 heures, le maximum accepte
    // par Chrome : sans cela, chaque appel a l'API etait precede d'une verification, qui doublait
    // l'attente avec `php artisan serve` (une requete a la fois).
    'max_age' => 7200,

    'supports_credentials' => false,

];
