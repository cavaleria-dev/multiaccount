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

// Debug endpoint - для диагностики логов
Route::get('debug/test-log', function () {
    try {
        $logFile = storage_path('logs/laravel.log');
        $testTime = now()->format('Y-m-d H:i:s');

        // Получаем пользователя PHP безопасно
        $phpUser = 'unknown';
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $userInfo = posix_getpwuid(posix_geteuid());
            $phpUser = $userInfo['name'] ?? 'unknown';
        }

        \Log::info('=== TEST LOG MESSAGE ===', [
            'time' => $testTime,
            'timezone' => config('app.timezone'),
            'user' => get_current_user(),
            'php_user' => $phpUser,
        ]);

        return response()->json([
            'message' => 'Test log written',
            'time' => $testTime,
            'timezone' => config('app.timezone'),
            'log_file' => $logFile,
            'log_exists' => file_exists($logFile),
            'log_writable' => is_writable($logFile),
            'log_size' => file_exists($logFile) ? filesize($logFile) : 0,
            'storage_writable' => is_writable(storage_path('logs')),
            'current_user' => get_current_user(),
            'php_user' => $phpUser,
            'log_channel' => config('logging.default'),
            'permissions' => file_exists($logFile) ? substr(sprintf('%o', fileperms($logFile)), -4) : 'N/A',
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});