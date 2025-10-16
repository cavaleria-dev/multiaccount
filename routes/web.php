<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ApiLogsController;

// Админ-панель для мониторинга API
Route::prefix('admin')->name('admin.')->group(function () {
    // Роуты авторизации (без middleware)
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login']);

    // Защищенные роуты админ-панели
    Route::middleware(\App\Http\Middleware\AdminAuth::class)->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        // Логи API
        Route::get('logs', [ApiLogsController::class, 'index'])->name('logs.index');
        Route::get('logs/{id}', [ApiLogsController::class, 'show'])->name('logs.show');

        // Статистика
        Route::get('statistics', [ApiLogsController::class, 'statistics'])->name('statistics');
    });
});

// SPA fallback для Vue Router
// ВАЖНО: Паттерн '^(?!api|admin).*' исключает /api/* и /admin/* роуты
// Без этого исключения Laravel будет отдавать HTML для API endpoints вместо JSON
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api|admin).*');