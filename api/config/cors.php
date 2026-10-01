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

    // ブラウザから直接叩くのはログイン・ログアウト・CSRF Cookie だけ。api/* は Next サーバ経由が基本
    'paths' => ['api/*', 'login', 'logout', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // 画面（Next.js）のオリジンだけを許可する。'*' にすると Cookie つきの要求が通せず、かつ広すぎる
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3002')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Sanctum の SPA 認証は Cookie を使うので true
    'supports_credentials' => true,

];
