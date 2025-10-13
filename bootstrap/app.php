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
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Отключаем CSRF для API маршрутов (они уже под префиксом /api)
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // Добавляем CORS middleware для всех маршрутов
        $middleware->append(CorsMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();