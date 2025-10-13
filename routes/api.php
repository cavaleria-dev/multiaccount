<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MoySkladController;
use App\Http\Controllers\Api\WebhookController;

// Vendor API для установки приложения МойСклад
Route::prefix('moysklad/vendor/1.0')->group(function () {
    Route::put('apps/{appId}/{accountId}', [MoySkladController::class, 'install']);
    Route::delete('apps/{appId}/{accountId}', [MoySkladController::class, 'uninstall']);
    Route::get('apps/{appId}/{accountId}/status', [MoySkladController::class, 'status']);
});

// Обновление статуса из iframe
Route::post('apps/update-status', [MoySkladController::class, 'updateStatus']);

// Вебхуки
Route::post('webhooks/moysklad', [WebhookController::class, 'handle']);

// Контекст пользователя
Route::get('context/{contextKey}', [MoySkladController::class, 'getContext']);