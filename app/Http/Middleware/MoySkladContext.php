<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Middleware для извлечения контекста МойСклад из запроса
 *
 * Контекст может быть передан двумя способами:
 * 1. Через заголовок X-MoySkl ad-Context-Key (contextKey из iframe)
 * 2. Напрямую в теле запроса (для внутренних вызовов)
 */
class MoySkladContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\ResponseRedirect)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\ResponseRedirect
     */
    public function handle(Request $request, Closure $next)
    {
        // Получить contextKey из заголовка или тела запроса
        $contextKey = $request->header('X-MoySklad-Context-Key')
                   ?? $request->input('contextKey');

        if (!$contextKey) {
            return response()->json([
                'error' => 'Context key not provided',
                'message' => 'X-MoySklad-Context-Key header or contextKey parameter is required'
            ], 401);
        }

        // Получить контекст из кеша
        $contextData = Cache::get("moysklad_context:{$contextKey}");

        if (!$contextData) {
            return response()->json([
                'error' => 'Context not found or expired',
                'message' => 'Please reload the application'
            ], 401);
        }

        // Добавить контекст в request для использования в контроллерах
        $request->merge(['moysklad_context' => $contextData]);
        $request->attributes->set('moysklad_context', $contextData);

        return $next($request);
    }
}
