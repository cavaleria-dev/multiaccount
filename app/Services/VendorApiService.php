<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Сервис для работы с Vendor API МойСклад
 *
 * Документация: https://dev.moysklad.ru/doc/api/vendor/1.0/
 */
class VendorApiService
{
    protected string $vendorApiUrl;
    protected string $appUid;
    protected string $secretKey;

    public function __construct()
    {
        $this->vendorApiUrl = config('moysklad.vendor_api_url', 'https://api.moysklad.ru/api/vendor/1.0');
        $this->appUid = config('moysklad.app_uid');
        $this->secretKey = config('moysklad.secret_key');
    }

    /**
     * Генерация JWT токена для запросов к Vendor API
     *
     * @param string $appUid
     * @return string
     */
    protected function generateJWT(string $appUid): string
    {
        $now = time();

        $payload = [
            'sub' => $appUid,                  // appUid решения (из URL параметра)
            'iat' => $now,                     // Время генерации токена
            'exp' => $now + 120,               // Время жизни (2 минуты)
            'jti' => Str::uuid()->toString()   // Уникальный идентификатор токена
        ];

        Log::info('Генерация JWT токена для Vendor API', [
            'appUid' => $appUid,
            'jti' => $payload['jti']
        ]);

        return JWT::encode($payload, $this->secretKey, 'HS256');
    }

    /**
     * Получить контекст пользователя по contextKey
     *
     * @param string $contextKey
     * @param string $appUid
     * @return array|null
     */
    public function getContext(string $contextKey, string $appUid): ?array
    {
        try {
            $jwt = $this->generateJWT($appUid);

            $url = "{$this->vendorApiUrl}/context/{$contextKey}";

            Log::info('Запрос контекста к Vendor API', [
                'url' => $url,
                'contextKey' => substr($contextKey, 0, 20) . '...',
                'jwt_preview' => substr($jwt, 0, 30) . '...'
            ]);

            // Добавляем все необходимые заголовки включая Content-Type с charset
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $jwt,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept-Encoding' => 'gzip, deflate'
            ])->get($url);

            Log::info('Ответ от Vendor API', [
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body_preview' => substr($response->body(), 0, 200)
            ]);

            if ($response->failed()) {
                Log::error('Ошибка при получении контекста', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'headers' => $response->headers()
                ]);
                return null;
            }

            $data = $response->json();

            Log::info('Контекст успешно получен', [
                'data_keys' => array_keys($data),
                'data' => $data
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('Исключение при получении контекста', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Валидация JWT токена (для входящих запросов от МойСклад)
     *
     * @param string $token
     * @return object|null
     */
    public function validateJWT(string $token): ?object
    {
        try {
            $decoded = JWT::decode($token, $this->secretKey, ['HS256']);

            Log::info('JWT токен валидирован', [
                'sub' => $decoded->sub ?? null
            ]);

            return $decoded;
        } catch (\Exception $e) {
            Log::error('Ошибка валидации JWT', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
