<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MoySkladController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Api\ContextController;
use App\Http\Middleware\LogMoySkladRequests;

// Vendor API для МойСклад
Route::prefix('moysklad/vendor/1.0')->middleware(LogMoySkladRequests::class)->group(function () {
    // ВАЖНО: status ПЕРВЫМ, чтобы не конфликтовал с другими роутами
    Route::get('apps/{appId}/{accountId}/status', [MoySkladController::class, 'status']);

    // Остальные роуты
    Route::put('apps/{appId}/{accountId}', [MoySkladController::class, 'install']);
    Route::delete('apps/{appId}/{accountId}', [MoySkladController::class, 'uninstall']);
});

// Internal API
Route::post('apps/update-status', [MoySkladController::class, 'updateStatus']);

// Webhook endpoints
Route::post('webhooks/moysklad', [WebhookController::class, 'handle']);

// Context API
Route::post('context', [ContextController::class, 'getContext']);
Route::get('stats', [ContextController::class, 'getStats']);

// Debug endpoint - для проверки что запрос доходит
Route::post('debug/context-test', function (\Illuminate\Http\Request $request) {
    \Log::info('Debug context test endpoint called', [
        'method' => $request->method(),
        'headers' => $request->headers->all(),
        'body' => $request->all(),
        'ip' => $request->ip(),
        'url' => $request->fullUrl()
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Request received successfully',
        'data' => [
            'method' => $request->method(),
            'contextKey' => $request->input('contextKey'),
            'headers' => $request->headers->all()
        ]
    ]);
});

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

        // Пытаемся записать лог разными способами
        \Log::info('=== TEST LOG MESSAGE ===', [
            'time' => $testTime,
            'timezone' => config('app.timezone'),
            'user' => get_current_user(),
            'php_user' => $phpUser,
        ]);

        // Попробуем записать напрямую в файл
        $directWrite = false;
        $directWriteError = null;
        try {
            file_put_contents($logFile, "[{$testTime}] DIRECT WRITE TEST\n", FILE_APPEND);
            $directWrite = true;
        } catch (\Exception $e) {
            $directWriteError = $e->getMessage();
        }

        return response()->json([
            'message' => 'Test log written',
            'time' => $testTime,
            'timezone' => config('app.timezone'),
            'env' => config('app.env'),
            'debug' => config('app.debug'),
            'log_file' => $logFile,
            'log_exists' => file_exists($logFile),
            'log_writable' => is_writable($logFile),
            'log_size_before' => file_exists($logFile) ? filesize($logFile) : 0,
            'storage_writable' => is_writable(storage_path('logs')),
            'current_user' => get_current_user(),
            'php_user' => $phpUser,
            'log_channel' => config('logging.default'),
            'log_level' => config('logging.channels.' . config('logging.default') . '.level'),
            'log_driver' => config('logging.channels.' . config('logging.default') . '.driver'),
            'log_path' => config('logging.channels.single.path', 'not set'),
            'permissions' => file_exists($logFile) ? substr(sprintf('%o', fileperms($logFile)), -4) : 'N/A',
            'direct_write' => $directWrite,
            'direct_write_error' => $directWriteError,
            'log_size_after' => file_exists($logFile) ? filesize($logFile) : 0,
            'cached_config' => file_exists(base_path('bootstrap/cache/config.php')),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});