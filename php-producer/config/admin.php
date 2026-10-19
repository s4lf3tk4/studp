<?php

return [

    'login' => env('ADMIN_LOGIN') ?: 'admin',

    'password' => env('ADMIN_PASSWORD'),

    'password_hash' => env('ADMIN_PASSWORD_HASH'),

    // Имя флага входа в сессии: смена значения отзывает все входы в панель
    'session_key' => env('ADMIN_SESSION_KEY') ?: 'admin.authenticated',

];