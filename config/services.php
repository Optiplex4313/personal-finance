<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    // Servicio OCR: el paquete .celaut.bee lo sube el usuario (OcrProviderManager) y lo
    // lanza el controlador local del entrypoint. Contrato en docs/ocr-service.md.
    'ocr' => [
        // Override: URL base de un servicio OCR ya levantado (desarrollo con Docker, tests).
        'url' => env('OCR_SERVICE_URL'),
        // Controlador local de pf-entrypoint que lanza/para los servicios en el nodo Celaut.
        'control_url' => env('PF_CONTROL_URL'),
        // Segundos. Una petición OCR; debe quedar por debajo del retry_after de la cola.
        'timeout' => (int) env('OCR_TIMEOUT', 1200),
        // Segundos. Lanzar el servicio: descarga de bloques y arranque (modelos pesados).
        'start_timeout' => (int) env('OCR_START_TIMEOUT', 900),
    ],

];
