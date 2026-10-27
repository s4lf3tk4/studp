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
        'confirmation_code' => env('VK_CONFIRMATION_CODE'),
        'access_token' => env('VK_ACCESS_TOKEN'),
        'secret_key' => env('VK_SECRET_KEY'),
        'api_version' => env('VK_API_VERSION', '5.199'),
        'max_file_bytes' => (int) env('VK_MAX_FILE_BYTES', 30 * 1024 * 1024),
    ],
    'max' => [
        'access_token' => env('MAX_ACCESS_TOKEN'),
        'webhook_secret' => env('MAX_WEBHOOK_SECRET'),
        'base_url' => env('MAX_API_URL', 'https://platform-api2.max.ru'),
        'max_file_bytes' => (int) env('MAX_MAX_FILE_BYTES', 30 * 1024 * 1024),
    ],
    'payments' => [
        'unit_price' => (int) env('PAYMENT_UNIT_PRICE', 200),   // копейки за страницу
        'public_url' => env('PAYMENT_PUBLIC_URL', env('APP_URL')),
        'provider' => env('PAYMENT_PROVIDER', 'stub'),          // stub | yookassa
        'webhook_url' => env('PAYMENT_WEBHOOK_URL', 'http://webserver/api/payments'),
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET', ''),  // пусто — проверка выключена
        'return_url' => env('PAYMENT_RETURN_URL', env('APP_URL')),
        'yookassa' => [
            'shop_id' => env('YOOKASSA_SHOP_ID'),
            'secret_key' => env('YOOKASSA_SECRET_KEY'),
            'vat_code' => (int) env('YOOKASSA_VAT_CODE', 1),
            'tax_system_code' => env('YOOKASSA_TAX_SYSTEM_CODE'),
            'payment_subject' => env('YOOKASSA_PAYMENT_SUBJECT', 'service'),
            'payment_mode' => env('YOOKASSA_PAYMENT_MODE', 'full_payment'),
        ],
    ],
    'documents' => [
        'download_timeout' => (int) env('DOWNLOAD_TIMEOUT_SECONDS', 150),
    ],

];
