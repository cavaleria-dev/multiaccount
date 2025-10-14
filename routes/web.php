<?php

use Illuminate\Support\Facades\Route;

// SPA fallback для Vue Router
// ВАЖНО: Паттерн '^(?!api).*' исключает /api/* роуты
// Без этого исключения Laravel будет отдавать HTML для API endpoints вместо JSON
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api).*');