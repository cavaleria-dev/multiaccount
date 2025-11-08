<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EntityUpdateLog Model
 *
 * Audit trail for partial entity updates triggered by webhooks
 * Universal for all entity types: product, service, variant, order, etc.
 *
 * @property int $id
 * @property string $main_account_id Main account UUID
 * @property string $child_account_id Child account UUID
 * @property string $entity_type Entity type (product, service, variant, etc.)
 * @property string $main_entity_id Entity ID in main account
 * @property string|null $child_entity_id Entity ID in child account
 * @property string $update_strategy Update strategy used (SKIP, FULL_SYNC, PRICES_ONLY, etc.)
 * @property array $updated_fields_received Raw updatedFields from webhook
 * @property array $fields_classified Classified fields (standard, custom_attributes, custom_price_types)
 * @property array $fields_applied Fields that were actually updated
 * @property array|null $fields_skipped Fields filtered by sync_settings
 * @property string|null $webhook_request_id Webhook request ID for linking
 * @property int|null $sync_queue_id Sync queue task ID for linking
 * @property string $status Status: processing, completed, failed
 * @property string|null $error_message Error message if failed
 * @property int|null $processing_time_ms Processing time in milliseconds
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Account $mainAccount
 * @property-read Account $childAccount
 * @property-read WebhookLog|null $webhookLog
 * @property-read SyncQueue|null $syncTask
 */
class EntityUpdateLog extends Model
{
    protected $table = 'entity_update_logs';

    protected $fillable = [
        'main_account_id',
        'child_account_id',
        'entity_type',
        'main_entity_id',
        'child_entity_id',
        'update_strategy',
        'updated_fields_received',
        'fields_classified',
        'fields_applied',
        'fields_skipped',
        'webhook_request_id',
        'sync_queue_id',
        'status',
        'error_message',
        'processing_time_ms',
    ];

    protected $casts = [
        'updated_fields_received' => 'array',
        'fields_classified' => 'array',
        'fields_applied' => 'array',
        'fields_skipped' => 'array',
        'processing_time_ms' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the main account that owns this update log
     */
    public function mainAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'main_account_id', 'account_id');
    }

    /**
     * Get the child account that owns this update log
     */
    public function childAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'child_account_id', 'account_id');
    }

    /**
     * Get the webhook log that triggered this update
     */
    public function webhookLog(): BelongsTo
    {
        return $this->belongsTo(WebhookLog::class, 'webhook_request_id', 'request_id');
    }

    /**
     * Get the sync task associated with this update
     */
    public function syncTask(): BelongsTo
    {
        return $this->belongsTo(SyncQueue::class, 'sync_queue_id', 'id');
    }

    /**
     * Check if update was successful
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if update failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if update is still processing
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Mark update as completed
     */
    public function markAsCompleted(array $appliedFields, int $processingTimeMs): void
    {
        $this->update([
            'status' => 'completed',
            'fields_applied' => $appliedFields,
            'processing_time_ms' => $processingTimeMs,
            'error_message' => null,
        ]);
    }

    /**
     * Mark update as failed
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }

    /**
     * Get count of fields that were applied
     */
    public function getAppliedFieldsCountAttribute(): int
    {
        return is_array($this->fields_applied) ? count($this->fields_applied) : 0;
    }

    /**
     * Get count of fields that were skipped
     */
    public function getSkippedFieldsCountAttribute(): int
    {
        return is_array($this->fields_skipped) ? count($this->fields_skipped) : 0;
    }

    /**
     * Get human-readable status
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'processing' => 'Обрабатывается',
            'completed' => 'Завершён',
            'failed' => 'Ошибка',
            default => $this->status,
        };
    }

    /**
     * Get human-readable strategy name
     */
    public function getStrategyLabelAttribute(): string
    {
        return match($this->update_strategy) {
            'SKIP' => 'Пропущено',
            'FULL_SYNC' => 'Полная синхронизация',
            'PRICES_ONLY' => 'Только цены',
            'ATTRIBUTES_ONLY' => 'Только атрибуты',
            'BASE_FIELDS_ONLY' => 'Только базовые поля',
            'MIXED_SIMPLE' => 'Смешанное обновление',
            default => $this->update_strategy,
        };
    }
}
