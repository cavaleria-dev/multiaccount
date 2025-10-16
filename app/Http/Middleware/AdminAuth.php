<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        // Проверить, авторизован ли администратор
        if (!session()->has('admin_user_id')) {
            return redirect()->route('admin.login')->with('error', 'Необходима авторизация');
        }

        // Проверить, существует ли администратор
        $adminUser = \App\Models\AdminUser::find(session('admin_user_id'));
        if (!$adminUser) {
            session()->forget('admin_user_id');
            return redirect()->route('admin.login')->with('error', 'Пользователь не найден');
        }

        // Добавить данные администратора в request
        $request->merge(['admin_user' => $adminUser]);

        return $next($request);
    }
}
