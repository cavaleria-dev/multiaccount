<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MoySkladController;
use App\Http\Controllers\Api\WebhookController;

// Vendor API для установки приложения МойСклад
// {appId} - UUID вашего приложения из ЛК разработчика
// {accountId} - UUID аккаунта пользователя МойСклад
Route::prefix('moysklad/vendor/1.0')->group(function () {
    // Установка/активация приложения
    Route::put('apps/{appId}/{accountId}', [MoySkladController::class, 'install']);

    // Удаление/приостановка приложения
    Route::delete('apps/{appId}/{accountId}', [MoySkladController::class, 'uninstall']);

    // Проверка статуса приложения
    Route::get('apps/{appId}/{accountId}/status', [MoySkladController::class, 'status']);
});

// Обновление статуса из iframe (после настройки пользователем)
Route::post('apps/status', [MoySkladController::class, 'updateStatus']);

// Webhook endpoints для получения событий от МойСклад
Route::post('webhooks/moysklad', [WebhookController::class, 'handle']);

// Получение контекста пользователя для iframe/виджетов
Route::get('context/{contextKey}', [MoySkladController::class, 'getContext']);
