<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Показать форму логина
     */
    public function showLogin()
    {
        // Если уже авторизован, перенаправить на дашборд
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.logs.index');
        }

        return view('admin.login');
    }

    /**
     * Обработать логин
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Rate limiting - максимум 5 попыток в минуту
        $key = 'login-attempts:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => ["Слишком много попыток входа. Попробуйте через {$seconds} секунд."],
            ]);
        }

        // Попытка аутентификации через guard
        $credentials = $request->only('email', 'password');

        if (!Auth::guard('admin')->attempt($credentials)) {
            RateLimiter::hit($key, 60);

            Log::warning('Failed admin login attempt', [
                'email' => $request->email,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['Неверный email или пароль.'],
            ]);
        }

        // Успешная авторизация
        RateLimiter::clear($key);

        // Регенерация сессии для безопасности
        $request->session()->regenerate();

        Log::info('Admin user logged in', [
            'user_id' => Auth::guard('admin')->id(),
            'email' => Auth::guard('admin')->user()->email,
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.logs.index');
    }

    /**
     * Выход
     */
    public function logout(Request $request)
    {
        $userId = Auth::guard('admin')->id();

        Log::info('Admin user logged out', [
            'user_id' => $userId,
            'ip' => $request->ip(),
        ]);

        // Выход через guard
        Auth::guard('admin')->logout();

        // Инвалидация сессии и регенерация CSRF токена
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'Вы успешно вышли из системы');
    }
}
