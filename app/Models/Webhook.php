<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Webhook Model
 *
 * Represents a webhook installed in МойСклад for an account
 *
 * @property int $id
 * @property string $account_id UUID of the account
 * @property string|null $moysklad_webhook_id Webhook ID from МойСклад API
 * @property string $account_type 'main' or 'child'
 * @property string $entity_type Entity type (product, service, variant, etc.)
 * @property string $action Action type (CREATE, UPDATE, DELETE)
 * @property string|null $diff_type Diff type for UPDATE (always 'FIELDS')
 * @property bool $enabled Is webhook enabled
 * @property string $url Webhook URL (our endpoint)
 * @property \Carbon\Carbon|null $last_triggered_at When webhook was last triggered
 * @property int $total_received Total webhooks received
 * @property int $total_failed Total webhooks failed
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Account $account
 * @property-read \Illuminate\Database\Eloquent\Collection<WebhookLog> $logs
 * @property-read \Illuminate\Database\Eloquent\Collection<WebhookHealthStat> $healthStats
 */
class Webhook extends Model
{
    protected $table = 'webhooks';

    protected $fillable = [
        'account_id',
        'moysklad_webhook_id',
        'account_type',
        'entity_type',
        'action',
        'diff_type',
        'enabled',
        'url',
        'last_triggered_at',
        'total_received',
        'total_failed',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_triggered_at' => 'datetime',
        'total_received' => 'integer',
        'total_failed' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the account that owns this webhook
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /**
     * Get all logs for this webhook
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WebhookLog::class, 'webhook_id', 'id');
    }

    /**
     * Get health stats for this webhook
     */
    public function healthStats(): HasMany
    {
        return $this->hasMany(WebhookHealthStat::class, 'webhook_id', 'moysklad_webhook_id');
    }

    /**
     * Scope: Only active webhooks
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope: Filter by account type
     */
    public function scopeByAccountType(Builder $query, string $accountType): Builder
    {
        return $query->where('account_type', $accountType);
    }

    /**
     * Scope: Filter by entity type
     */
    public function scopeByEntityType(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    /**
     * Scope: Filter by account
     */
    public function scopeByAccount(Builder $query, string $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Increment received counter and update last triggered
     */
    public function incrementReceived(): void
    {
        $this->increment('total_received');
        $this->update(['last_triggered_at' => now()]);
    }

    /**
     * Increment failed counter
     */
    public function incrementFailed(): void
    {
        $this->increment('total_failed');
    }

    /**
     * Update last triggered timestamp
     */
    public function updateLastTriggered(): void
    {
        $this->update(['last_triggered_at' => now()]);
    }

    /**
     * Get human-readable webhook identifier
     */
    public function getIdentifierAttribute(): string
    {
        return "{$this->entity_type}:{$this->action}";
    }

    /**
     * Check if webhook is healthy (failure rate < 10%)
     */
    public function getIsHealthyAttribute(): bool
    {
        if ($this->total_received === 0) {
            return true; // No data yet
        }

        $failureRate = ($this->total_failed / $this->total_received) * 100;
        return $failureRate < 10;
    }
}
