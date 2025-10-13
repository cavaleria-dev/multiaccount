<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Обработчик rate limits МойСклад API
 *
 * Извлекает информацию о лимитах из заголовков ответа API:
 * - X-RateLimit-Limit: общий лимит запросов
 * - X-RateLimit-Remaining: оставшееся количество запросов
 * - X-Lognex-Retry-TimeInterval: интервал времени для лимита
 * - X-Lognex-Reset: время сброса счетчика (timestamp)
 * - X-Lognex-Retry-After: время ожидания перед повтором при 429
 */
class RateLimitHandler
{
    /**
     * Извлечь информацию о rate limits из headers ответа
     *
     * @param array $headers Заголовки ответа от МойСклад API
     * @return array Информация о rate limits
     */
    public function extractFromHeaders(array $headers): array
    {
        $rateLimitInfo = [
            'limit' => null,
            'remaining' => null,
            'reset' => null,
            'retry_interval' => null,
            'retry_after' => null,
        ];

        try {
            // X-RateLimit-Limit
            if (isset($headers['X-RateLimit-Limit'])) {
                $rateLimitInfo['limit'] = (int) $this->getHeaderValue($headers['X-RateLimit-Limit']);
            }

            // X-RateLimit-Remaining
            if (isset($headers['X-RateLimit-Remaining'])) {
                $rateLimitInfo['remaining'] = (int) $this->getHeaderValue($headers['X-RateLimit-Remaining']);
            }

            // X-Lognex-Reset (timestamp в миллисекундах)
            if (isset($headers['X-Lognex-Reset'])) {
                $resetMs = (int) $this->getHeaderValue($headers['X-Lognex-Reset']);
                $rateLimitInfo['reset'] = $resetMs > 0 ? (int) ($resetMs / 1000) : null;
            }

            // X-Lognex-Retry-TimeInterval (миллисекунды)
            if (isset($headers['X-Lognex-Retry-TimeInterval'])) {
                $rateLimitInfo['retry_interval'] = (int) $this->getHeaderValue($headers['X-Lognex-Retry-TimeInterval']);
            }

            // X-Lognex-Retry-After (миллисекунды) - используется при 429 ошибке
            if (isset($headers['X-Lognex-Retry-After'])) {
                $rateLimitInfo['retry_after'] = (int) $this->getHeaderValue($headers['X-Lognex-Retry-After']);
            }

            Log::debug('Rate limit info extracted', $rateLimitInfo);

        } catch (\Exception $e) {
            Log::warning('Failed to extract rate limit info from headers', [
                'error' => $e->getMessage(),
                'headers' => $headers
            ]);
        }

        return $rateLimitInfo;
    }

    /**
     * Получить значение заголовка (может быть массивом или строкой)
     *
     * @param mixed $headerValue
     * @return string
     */
    protected function getHeaderValue($headerValue): string
    {
        if (is_array($headerValue)) {
            return $headerValue[0] ?? '';
        }

        return (string) $headerValue;
    }

    /**
     * Проверить, нужно ли добавить задачу в очередь вместо немедленного выполнения
     *
     * @param array $rateLimitInfo Информация о rate limits
     * @param int $threshold Порог оставшихся запросов (по умолчанию 10)
     * @return bool
     */
    public function shouldQueue(array $rateLimitInfo, int $threshold = 10): bool
    {
        if (!isset($rateLimitInfo['remaining']) || $rateLimitInfo['remaining'] === null) {
            // Если нет информации о лимитах, можно выполнять
            return false;
        }

        // Если осталось меньше threshold запросов, добавить в очередь
        return $rateLimitInfo['remaining'] < $threshold;
    }

    /**
     * Вычислить задержку в секундах для постановки задачи в очередь
     *
     * @param array $rateLimitInfo Информация о rate limits
     * @return int Задержка в секундах
     */
    public function calculateDelay(array $rateLimitInfo): int
    {
        // Если есть retry_after (при 429), использовать его
        if (isset($rateLimitInfo['retry_after']) && $rateLimitInfo['retry_after'] > 0) {
            // retry_after в миллисекундах, конвертируем в секунды
            return (int) ceil($rateLimitInfo['retry_after'] / 1000);
        }

        // Если есть reset timestamp, вычислить задержку до него
        if (isset($rateLimitInfo['reset']) && $rateLimitInfo['reset'] > 0) {
            $delay = $rateLimitInfo['reset'] - time();
            return max(0, $delay);
        }

        // По умолчанию 60 секунд
        return 60;
    }

    /**
     * Проверить, достигнут ли критический лимит (нужна задержка)
     *
     * @param array $rateLimitInfo Информация о rate limits
     * @return bool
     */
    public function isCriticalLimit(array $rateLimitInfo): bool
    {
        if (!isset($rateLimitInfo['remaining']) || $rateLimitInfo['remaining'] === null) {
            return false;
        }

        // Критический лимит: 0 или 1 запрос
        return $rateLimitInfo['remaining'] <= 1;
    }

    /**
     * Получить процент использования лимита
     *
     * @param array $rateLimitInfo Информация о rate limits
     * @return float|null Процент использования (0-100) или null если нет данных
     */
    public function getUsagePercent(array $rateLimitInfo): ?float
    {
        if (!isset($rateLimitInfo['limit'], $rateLimitInfo['remaining'])) {
            return null;
        }

        if ($rateLimitInfo['limit'] === 0) {
            return 100.0;
        }

        $used = $rateLimitInfo['limit'] - $rateLimitInfo['remaining'];
        return ($used / $rateLimitInfo['limit']) * 100;
    }
}
