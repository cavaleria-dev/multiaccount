<?php

namespace App\Exceptions;

use Exception;

/**
 * Исключение для обработки превышения rate limits МойСклад API
 */
class RateLimitException extends Exception
{
    /**
     * Время ожидания перед повтором (миллисекунды)
     */
    protected int $retryAfter;

    /**
     * Информация о rate limits из заголовков
     */
    protected array $rateLimitInfo;

    /**
     * Создать новый экземпляр исключения
     *
     * @param string $message Сообщение об ошибке
     * @param int $retryAfter Время ожидания перед повтором (миллисекунды)
     * @param array $rateLimitInfo Информация о rate limits
     * @param int $code Код ошибки
     * @param \Throwable|null $previous Предыдущее исключение
     */
    public function __construct(
        string $message = 'Rate limit exceeded',
        int $retryAfter = 1000,
        array $rateLimitInfo = [],
        int $code = 429,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->retryAfter = $retryAfter;
        $this->rateLimitInfo = $rateLimitInfo;
    }

    /**
     * Получить время ожидания перед повтором (миллисекунды)
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * Получить время ожидания перед повтором (секунды)
     */
    public function getRetryAfterSeconds(): int
    {
        return (int) ceil($this->retryAfter / 1000);
    }

    /**
     * Получить информацию о rate limits
     */
    public function getRateLimitInfo(): array
    {
        return $this->rateLimitInfo;
    }

    /**
     * Получить количество оставшихся запросов
     */
    public function getRemaining(): ?int
    {
        return $this->rateLimitInfo['remaining'] ?? null;
    }

    /**
     * Получить общий лимит запросов
     */
    public function getLimit(): ?int
    {
        return $this->rateLimitInfo['limit'] ?? null;
    }

    /**
     * Получить timestamp сброса счетчика
     */
    public function getResetTimestamp(): ?int
    {
        return $this->rateLimitInfo['reset'] ?? null;
    }
}
