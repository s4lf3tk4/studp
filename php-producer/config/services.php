<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    //     'telegram' => [
    //     // Токен бота — понадобится, когда будем отвечать в Telegram
    //     'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    //     // Если задан, Telegram обязан присылать его в заголовке
    //     // X-Telegram-Bot-Api-Secret-Token (указывается при setWebhook)
    //     'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    // ],

    'vk' => [
        // Код подтверждения адреса Callback API (настройки сообщества → Callback API)
        'confirmation_code' => env('VK_CONFIRMATION_CODE'),
        // Токен доступа сообщества: нужен для users.get / groups.getById,
        // потому что VK не присылает имя отправителя в самом событии
        'access_token' => env('VK_ACCESS_TOKEN'),
        // Если задан, VK обязан присылать его в поле secret каждого события
        'secret_key' => env('VK_SECRET_KEY'),
        'api_version' => env('VK_API_VERSION', '5.199'),
    ],
    'max' => [
        // Токен бота: business.max.ru → Чат-боты → бот → ⋮ → Настройки
        'access_token' => env('MAX_ACCESS_TOKEN'),
        // Секрет вебхука: MAX пришлёт его в заголовке X-Max-Bot-Api-Secret
        'webhook_secret' => env('MAX_WEBHOOK_SECRET'),
        'base_url' => env('MAX_API_URL', 'https://platform-api2.max.ru'),
    ],
    'payments' => [
        'unit_price' => (int) env('PAYMENT_UNIT_PRICE', 200),   // копейки за страницу
        'public_url' => env('PAYMENT_PUBLIC_URL', env('APP_URL')),
        'provider' => env('PAYMENT_PROVIDER', 'stub'),
    ],

];
