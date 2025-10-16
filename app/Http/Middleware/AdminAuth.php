<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware для проверки авторизации администратора
 */
class AdminAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Проверить, авторизован ли администратор через guard
        if (!Auth::guard('admin')->check()) {
            return redirect()->route('admin.login')->with('error', 'Необходима авторизация');
        }

        // Получить текущего администратора
        $adminUser = Auth::guard('admin')->user();

        // Добавить данные администратора в request (для обратной совместимости)
        $request->merge(['admin_user' => $adminUser]);

        return $next($request);
    }
}
