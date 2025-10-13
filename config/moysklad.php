<?php

return [
    // UUID вашего приложения из ЛК разработчика
    'app_id' => env('MOYSKLAD_APP_ID', ''),
    
    // Секретный ключ (Secret Key) из ЛК разработчика
    'secret_key' => env('MOYSKLAD_SECRET_KEY', ''),
    
    // URL для работы с API
    'api_url' => env('MOYSKLAD_API_URL', 'https://api.moysklad.ru/api/remap/1.2'),
    
    // Таймауты
    'timeout' => env('MOYSKLAD_TIMEOUT', 30),
    'retry_times' => env('MOYSKLAD_RETRY_TIMES', 3),
    
    // URL вебхука для получения событий
    'webhook_url' => env('APP_URL') . '/api/webhooks/moysklad',
];
