<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Панель типографии
    |--------------------------------------------------------------------------
    |
    | Логин и пароль задаются только через .env. Пароль можно хранить либо
    | открытым текстом (ADMIN_PASSWORD), либо bcrypt-хешем
    | (ADMIN_PASSWORD_HASH) — хеш имеет приоритет.
    |
    */

    'login' => env('ADMIN_LOGIN', 'admin'),

    'password' => env('ADMIN_PASSWORD'),

    'password_hash' => env('ADMIN_PASSWORD_HASH'),

    // Ключи, под которыми факт входа лежит в сессии
    'session_key' => 'admin.authenticated',
    'session_login_key' => 'admin.login',

];