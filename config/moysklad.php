<?php

return [
    // UUID вашего приложения из ЛК разработчика
    'app_id' => env('MOYSKLAD_APP_ID', ''),

    // Уникальный идентификатор приложения (appUid)
    'app_uid' => env('MOYSKLAD_APP_UID', ''),

    // Секретный ключ (Secret Key) из ЛК разработчика
    'secret_key' => env('MOYSKLAD_SECRET_KEY', ''),

    // URL для работы с JSON API 1.2
    'api_url' => env('MOYSKLAD_API_URL', 'https://api.moysklad.ru/api/remap/1.2'),

    // URL для работы с Vendor API 1.0
    'vendor_api_url' => env('MOYSKLAD_VENDOR_API_URL', 'https://apps-api.moysklad.ru/api/vendor/1.0'),

    // Таймауты
    'timeout' => env('MOYSKLAD_TIMEOUT', 30),
    'retry_times' => env('MOYSKLAD_RETRY_TIMES', 3),

    // URL вебхука для получения событий
    'webhook_url' => env('MOYSKLAD_WEBHOOK_URL', env('APP_URL') . '/api/webhooks/moysklad'),

    // Webhook system settings
    'webhook' => [
        // Job timeout для ProcessWebhookJob (секунды)
        'job_timeout' => env('MOYSKLAD_WEBHOOK_JOB_TIMEOUT', 120),

        // Количество попыток обработки webhook
        'job_retry_attempts' => env('MOYSKLAD_WEBHOOK_JOB_RETRY_ATTEMPTS', 3),

        // Таймаут для SetupAccountWebhooksJob (секунды)
        'setup_job_timeout' => env('MOYSKLAD_WEBHOOK_SETUP_JOB_TIMEOUT', 180),

        // Таймаут для CheckWebhookHealthJob (секунды)
        'health_check_timeout' => env('MOYSKLAD_WEBHOOK_HEALTH_CHECK_TIMEOUT', 300),

        // Целевое время обработки webhook в контроллере (миллисекунды)
        'fast_response_target_ms' => env('MOYSKLAD_WEBHOOK_FAST_RESPONSE_MS', 50),

        // Процент ошибок для определения unhealthy webhook
        'unhealthy_threshold_percent' => env('MOYSKLAD_WEBHOOK_UNHEALTHY_THRESHOLD', 10),

        // Количество неудачных health checks перед auto-healing
        'auto_heal_threshold' => env('MOYSKLAD_WEBHOOK_AUTO_HEAL_THRESHOLD', 3),

        // Включить auto-healing при периодических проверках
        'auto_heal_enabled' => env('MOYSKLAD_WEBHOOK_AUTO_HEAL_ENABLED', true),
    ],
];
