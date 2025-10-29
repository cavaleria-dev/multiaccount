<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MoySkladController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\CorsMiddleware;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Регистрируем API маршруты без middleware аутентификации
            Route::middleware(['api', CorsMiddleware::class])
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Отключаем CSRF для API маршрутов (они уже под префиксом /api)
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // Отключаем дефолтную аутентификацию для API группы
        $middleware->group('api', [
            // Только базовые middleware, без аутентификации
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            // Валидация UUID параметров для всех API маршрутов
            \App\Http\Middleware\ValidateUuidParameters::class,
        ]);

        // Регистрируем алиасы для middleware
        $middleware->alias([
            'moysklad.context' => \App\Http\Middleware\MoySkladContext::class,
            'admin.auth' => \App\Http\Middleware\AdminAuth::class,
            'validate.uuid' => \App\Http\Middleware\ValidateUuidParameters::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();