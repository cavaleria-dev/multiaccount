<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для отслеживания rate limits МойСклад API между запросами
 *
 * Сохраняет информацию о rate limits в Redis/Cache чтобы разные workers
 * и разные запросы знали о текущем состоянии лимитов для каждого account.
 *
 * Критично для предотвращения превышения лимитов ГЛАВНОГО аккаунта
 * при синхронизации на множество дочерних аккаунтов.
 *
 * Каждый main account имеет независимый rate limit (45 req/min),
 * этот сервис отслеживает каждый account отдельно.
 */
class RateLimitTracker
{
    /**
     * TTL для cache записей (в секундах)
     * Должен быть больше X-Lognex-Retry-TimeInterval МойСклад (обычно 60 сек)
     */
    const CACHE_TTL = 120;

    /**
     * Минимальный запас для безопасного продолжения (requests remaining)
     */
    const SAFETY_THRESHOLD = 5;

    /**
     * Проверить доступность rate limit для account
     *
     * @param string $accountId UUID аккаунта
     * @param int $cost Стоимость операции (количество запросов)
     * @return array ['available' => bool, 'remaining' => int|null, 'retry_after' => int]
     */
    public function checkAvailability(string $accountId, int $cost = 1): array
    {
        $cacheKey = "rate_limit:{$accountId}";
        $rateLimitData = Cache::get($cacheKey);

        if (!$rateLimitData) {
            // Нет данных в кеше → предполагаем что лимит доступен
            return [
                'available' => true,
                'remaining' => null,
                'retry_after' => 0
            ];
        }

        $remaining = $rateLimitData['remaining'] ?? null;
        $reset = $rateLimitData['reset'] ?? null;

        if ($remaining === null) {
            return ['available' => true, 'remaining' => null, 'retry_after' => 0];
        }

        // Проверить: достаточно ли оставшихся запросов?
        // Добавляем safety threshold чтобы не исчерпать лимит полностью
        $available = $remaining >= ($cost + self::SAFETY_THRESHOLD);

        // Вычислить retry_after если лимит исчерпан
        $retryAfter = 0;
        if (!$available && $reset) {
            $retryAfter = max(0, $reset - time());
        }

        Log::debug('Rate limit check', [
            'account_id' => substr($accountId, 0, 8) . '...',
            'cost' => $cost,
            'remaining' => $remaining,
            'available' => $available,
            'retry_after' => $retryAfter
        ]);

        return [
            'available' => $available,
            'remaining' => $remaining,
            'retry_after' => $retryAfter
        ];
    }

    /**
     * Обновить rate limit info из заголовков ответа API
     *
     * @param string $accountId UUID аккаунта
     * @param array $rateLimitInfo Информация из RateLimitHandler
     */
    public function updateFromResponse(string $accountId, array $rateLimitInfo): void
    {
        if (empty($rateLimitInfo['remaining']) && empty($rateLimitInfo['limit'])) {
            // Нет информации о rate limits в ответе
            return;
        }

        $cacheKey = "rate_limit:{$accountId}";

        $data = [
            'limit' => $rateLimitInfo['limit'] ?? null,
            'remaining' => $rateLimitInfo['remaining'] ?? null,
            'reset' => $rateLimitInfo['reset'] ?? null,
            'retry_interval' => $rateLimitInfo['retry_interval'] ?? null,
            'last_updated' => time()
        ];

        Cache::put($cacheKey, $data, self::CACHE_TTL);

        Log::debug('Rate limit updated from response', [
            'account_id' => substr($accountId, 0, 8) . '...',
            'remaining' => $data['remaining'],
            'limit' => $data['limit']
        ]);
    }

    /**
     * Вычислить совокупную стоимость batch операции
     *
     * @param string $entityType Тип сущности (product, service, etc.)
     * @param int $entityCount Количество сущностей
     * @param bool $includeVariants Включить загрузку вариантов?
     * @return int Количество запросов к MAIN account
     */
    public function estimateBatchCost(
        string $entityType,
        int $entityCount,
        bool $includeVariants = false
    ): int {
        // Pre-cache dependencies (к MAIN account):
        // - Атрибуты: ~3 запроса (product, variant, etc.)
        // - Типы цен: ~2 запроса

        $cost = 5; // Базовая стоимость

        if ($entityType === 'product' && $includeVariants) {
            // Для каждого товара может понадобиться загрузка вариантов
            // МойСклад API: max 100 вариантов за запрос
            $cost += (int)ceil($entityCount / 100);
        }

        // Загрузка самих сущностей из main account (фильтрация)
        // Обычно: 1 запрос на 100 сущностей
        $cost += (int)ceil($entityCount / 100);

        return $cost;
    }

    /**
     * Получить текущее состояние rate limit для account
     *
     * @param string $accountId UUID аккаунта
     * @return array|null Данные о rate limit или null если нет информации
     */
    public function getCurrentState(string $accountId): ?array
    {
        $cacheKey = "rate_limit:{$accountId}";
        return Cache::get($cacheKey);
    }

    /**
     * Очистить информацию о rate limit для account
     * Используется при тестировании или при сбросе состояния
     *
     * @param string $accountId UUID аккаунта
     */
    public function clear(string $accountId): void
    {
        $cacheKey = "rate_limit:{$accountId}";
        Cache::forget($cacheKey);

        Log::info('Rate limit cache cleared', [
            'account_id' => substr($accountId, 0, 8) . '...'
        ]);
    }
}
