<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ApiLogsController;

// Админ-панель для мониторинга API
Route::prefix('admin')->name('admin.')->group(function () {
    // Роуты авторизации (без middleware)
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login']);

    // Корневой роут /admin - умный редирект
    Route::get('/', function () {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.logs.index');
        }
        return redirect()->route('admin.login');
    })->name('dashboard');

    // Защищенные роуты админ-панели
    Route::middleware('admin.auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        // Логи API
        Route::get('logs', [ApiLogsController::class, 'index'])->name('logs.index');
        Route::get('logs/{id}', [ApiLogsController::class, 'show'])->name('logs.show');

        // Статистика
        Route::get('statistics', [ApiLogsController::class, 'statistics'])->name('statistics');

        // Очереди синхронизации
        Route::prefix('queue')->name('queue.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\QueueController::class, 'dashboard'])->name('dashboard');
            Route::get('/tasks', [\App\Http\Controllers\Admin\QueueController::class, 'index'])->name('tasks');
            Route::get('/tasks/{id}', [\App\Http\Controllers\Admin\QueueController::class, 'show'])->name('tasks.show');
            Route::post('/tasks/{id}/retry', [\App\Http\Controllers\Admin\QueueController::class, 'retry'])->name('tasks.retry');
            Route::delete('/tasks/{id}', [\App\Http\Controllers\Admin\QueueController::class, 'delete'])->name('tasks.delete');
            Route::get('/rate-limits', [\App\Http\Controllers\Admin\QueueController::class, 'rateLimits'])->name('rate-limits');
        });
    });
});

// SPA fallback для Vue Router
// ВАЖНО: Паттерн '^(?!api|admin).*' исключает /api/* и /admin/* роуты
// Без этого исключения Laravel будет отдавать HTML для API endpoints вместо JSON
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api|admin).*');