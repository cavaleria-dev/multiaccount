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
    'vendor_api_url' => env('MOYSKLAD_VENDOR_API_URL', 'https://api.moysklad.ru/api/vendor/1.0'),

    // Таймауты
    'timeout' => env('MOYSKLAD_TIMEOUT', 30),
    'retry_times' => env('MOYSKLAD_RETRY_TIMES', 3),

    // URL вебхука для получения событий
    'webhook_url' => env('APP_URL') . '/api/webhooks/moysklad',
];
