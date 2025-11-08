<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * WebhookLog Model
 *
 * Represents a log entry for a received webhook from МойСклад
 *
 * @property int $id
 * @property string $request_id МойСклад requestId (unique, for idempotency)
 * @property string $account_id UUID of the account
 * @property int|null $webhook_id Foreign key to webhooks table
 * @property string $entity_type Entity type (product, service, variant, etc.)
 * @property string $action Action type (CREATE, UPDATE, DELETE)
 * @property array $payload Full webhook payload from МойСклад
 * @property array|null $updated_fields Array of field names that were updated (from МойСклад updatedFields)
 * @property string $status Processing status (pending, processing, completed, failed)
 * @property \Carbon\Carbon|null $processed_at When webhook was processed
 * @property string|null $error_message Error details if failed
 * @property int|null $processing_time_ms Time taken to process (milliseconds)
 * @property int $events_count Number of events in this webhook
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Account $account
 * @property-read Webhook|null $webhook
 */
class WebhookLog extends Model
{
    protected $table = 'webhook_logs';

    protected $fillable = [
        'request_id',
        'account_id',
        'webhook_id',
        'entity_type',
        'action',
        'payload',
        'updated_fields',
        'status',
        'processed_at',
        'error_message',
        'processing_time_ms',
        'events_count',
    ];

    protected $casts = [
        'payload' => 'array',
        'updated_fields' => 'array',
        'processed_at' => 'datetime',
        'processing_time_ms' => 'integer',
        'events_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the account that owns this webhook log
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /**
     * Get the webhook this log belongs to
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class, 'webhook_id', 'id');
    }

    /**
     * Scope: Only pending logs
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: Only processing logs
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope: Only completed logs
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: Only failed logs
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope: Recent logs (last 24 hours)
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subDay());
    }

    /**
     * Scope: Filter by account
     */
    public function scopeByAccount(Builder $query, string $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Scope: Filter by status
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Mark webhook log as processing
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => 'processing',
        ]);
    }

    /**
     * Mark webhook log as completed
     */
    public function markAsCompleted(int $processingTimeMs): void
    {
        $this->update([
            'status' => 'completed',
            'processed_at' => now(),
            'processing_time_ms' => $processingTimeMs,
            'error_message' => null,
        ]);
    }

    /**
     * Mark webhook log as failed
     */
    public function markAsFailed(string $errorMessage, int $processingTimeMs = null): void
    {
        $this->update([
            'status' => 'failed',
            'processed_at' => now(),
            'error_message' => $errorMessage,
            'processing_time_ms' => $processingTimeMs,
        ]);
    }

    /**
     * Check if this webhook log is a duplicate (already exists)
     */
    public static function isDuplicate(string $requestId): bool
    {
        return static::where('request_id', $requestId)->exists();
    }

    /**
     * Get human-readable status
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Ожидает',
            'processing' => 'Обрабатывается',
            'completed' => 'Завершён',
            'failed' => 'Ошибка',
            default => $this->status,
        };
    }

    /**
     * Check if log is processable (pending or failed with few retries)
     */
    public function isProcessable(): bool
    {
        return $this->status === 'pending' || $this->status === 'processing';
    }
}
