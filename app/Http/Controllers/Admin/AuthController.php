<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        if (session()->has('admin_user_id')) {
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

        // Найти пользователя
        $user = AdminUser::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
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

        session()->put('admin_user_id', $user->id);
        session()->put('admin_user_name', $user->name);
        session()->regenerate();

        Log::info('Admin user logged in', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.logs.index');
    }

    /**
     * Выход
     */
    public function logout(Request $request)
    {
        $userId = session('admin_user_id');

        Log::info('Admin user logged out', [
            'user_id' => $userId,
            'ip' => $request->ip(),
        ]);

        session()->forget('admin_user_id');
        session()->forget('admin_user_name');
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'Вы успешно вышли из системы');
    }
}
