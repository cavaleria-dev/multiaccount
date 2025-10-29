<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware для валидации UUID параметров в маршрутах
 *
 * Проверяет все route parameters оканчивающиеся на 'Id' или '_id'
 * и возвращает детальное сообщение об ошибке если UUID невалиден.
 */
class ValidateUuidParameters
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Получить все route parameters
        $routeParameters = $request->route()?->parameters() ?? [];

        // Проверить каждый параметр оканчивающийся на 'Id' или '_id'
        foreach ($routeParameters as $paramName => $paramValue) {
            // Проверить если параметр выглядит как ID field
            if ($this->isIdParameter($paramName)) {
                // Проверить что значение является валидным UUID
                if (!$this->isValidUuid($paramValue)) {
                    return response()->json([
                        'error' => 'Invalid UUID format',
                        'message' => "Parameter '{$paramName}' must be a valid UUID",
                        'parameter' => $paramName,
                        'value' => $paramValue,
                        'expected_format' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'
                    ], 400);
                }
            }
        }

        return $next($request);
    }

    /**
     * Проверить является ли параметр ID полем
     */
    protected function isIdParameter(string $paramName): bool
    {
        return Str::endsWith($paramName, 'Id') || Str::endsWith($paramName, '_id');
    }

    /**
     * Проверить валидность UUID
     */
    protected function isValidUuid(string $uuid): bool
    {
        // UUID v4 pattern (можно использовать для всех версий UUID)
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        return (bool) preg_match($pattern, $uuid);
    }
}
