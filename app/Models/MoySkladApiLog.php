<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MoySkladApiLog extends Model
{
    use HasFactory;

    protected $table = 'moysklad_api_logs';

    protected $fillable = [
        'account_id',
        'direction',
        'related_account_id',
        'entity_type',
        'entity_id',
        'operation_type',
        'operation_result',
        'method',
        'endpoint',
        'request_params',
        'request_payload',
        'response_status',
        'response_body',
        'error_message',
        'rate_limit_info',
        'duration_ms',
    ];

    protected $casts = [
        'request_params' => 'array',
        'request_payload' => 'array',
        'response_body' => 'array',
        'rate_limit_info' => 'array',
        'duration_ms' => 'integer',
        'response_status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope для получения только ошибок (статус >= 400)
     */
    public function scopeErrors($query)
    {
        return $query->where('response_status', '>=', 400);
    }

    /**
     * Scope для получения логов конкретного аккаунта
     */
    public function scopeForAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Scope для получения логов конкретного типа сущности
     */
    public function scopeForEntityType($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }

    /**
     * Scope для получения логов с определенным статусом
     */
    public function scopeWithStatus($query, int $status)
    {
        return $query->where('response_status', $status);
    }

    /**
     * Scope для получения логов за период
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Получить связь с аккаунтом
     */
    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /**
     * Получить связь со связанным аккаунтом
     */
    public function relatedAccount()
    {
        return $this->belongsTo(Account::class, 'related_account_id', 'account_id');
    }

    /**
     * Проверить, является ли запрос ошибкой
     */
    public function isError(): bool
    {
        return $this->response_status >= 400;
    }

    /**
     * Проверить, является ли это rate limit ошибкой
     */
    public function isRateLimitError(): bool
    {
        return $this->response_status === 429;
    }

    /**
     * Получить краткое описание ошибки
     */
    public function getErrorSummary(): ?string
    {
        if (!$this->isError()) {
            return null;
        }

        if ($this->error_message) {
            return $this->error_message;
        }

        if ($this->response_body && isset($this->response_body['errors'])) {
            $errors = $this->response_body['errors'];
            if (is_array($errors) && count($errors) > 0) {
                return $errors[0]['error'] ?? 'Unknown error';
            }
        }

        return "HTTP {$this->response_status}";
    }
}
