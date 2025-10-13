<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MoySkladController;
use App\Http\Controllers\Api\WebhookController;

// Vendor API для МойСклад
Route::prefix('moysklad/vendor/1.0')->group(function () {
    // ВАЖНО: status ПЕРВЫМ, чтобы не конфликтовал с другими роутами
    Route::get('apps/{appId}/{accountId}/status', [MoySkladController::class, 'status']);

    // Остальные роуты
    Route::put('apps/{appId}/{accountId}', [MoySkladController::class, 'install']);
    Route::delete('apps/{appId}/{accountId}', [MoySkladController::class, 'uninstall']);
});

// Internal API
Route::post('apps/update-status', [MoySkladController::class, 'updateStatus']);
Route::post('webhooks/moysklad', [WebhookController::class, 'handle']);
Route::get('context/{contextKey}', [MoySkladController::class, 'getContext']);