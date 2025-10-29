<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * WebhookHealthStat Model
 *
 * Represents health monitoring statistics for a webhook
 *
 * @property int $id
 * @property string $account_id UUID of the account
 * @property string|null $webhook_id МойСклад webhook ID
 * @property string $entity_type Entity type (product, service, variant, etc.)
 * @property string $action Action type (CREATE, UPDATE, DELETE)
 * @property bool $is_active Is webhook active and healthy
 * @property \Carbon\Carbon|null $last_check_at Last health check timestamp
 * @property string|null $error_message Last error message if any
 * @property int $check_attempts Number of failed check attempts
 * @property \Carbon\Carbon|null $last_success_at Last successful check timestamp
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Account $account
 * @property-read string $health_status Human-readable health status
 */
class WebhookHealthStat extends Model
{
    protected $table = 'webhook_health';

    protected $fillable = [
        'account_id',
        'webhook_id',
        'entity_type',
        'action',
        'is_active',
        'last_check_at',
        'error_message',
        'check_attempts',
        'last_success_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'check_attempts' => 'integer',
        'last_check_at' => 'datetime',
        'last_success_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the account that owns this health stat
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /**
     * Scope: Only unhealthy webhooks
     */
    public function scopeUnhealthy(Builder $query): Builder
    {
        return $query->where('is_active', false)
                     ->orWhere('check_attempts', '>', 0);
    }

    /**
     * Scope: Only healthy webhooks
     */
    public function scopeHealthy(Builder $query): Builder
    {
        return $query->where('is_active', true)
                     ->where('check_attempts', 0);
    }

    /**
     * Scope: Filter by account
     */
    public function scopeByAccount(Builder $query, string $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Scope: Filter by date range
     */
    public function scopeDateRange(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('last_check_at', [$startDate, $endDate]);
    }

    /**
     * Scope: Recent checks (last 24 hours)
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('last_check_at', '>=', now()->subDay());
    }

    /**
     * Get human-readable health status
     */
    public function getHealthStatusAttribute(): string
    {
        if ($this->is_active && $this->check_attempts === 0) {
            return 'healthy';
        }

        if ($this->is_active && $this->check_attempts > 0) {
            return 'degraded';
        }

        if (!$this->is_active && $this->check_attempts < 3) {
            return 'warning';
        }

        return 'critical';
    }

    /**
     * Get status color for UI
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->health_status) {
            'healthy' => 'green',
            'degraded' => 'yellow',
            'warning' => 'orange',
            'critical' => 'red',
            default => 'gray',
        };
    }

    /**
     * Mark as healthy (successful check)
     */
    public function markAsHealthy(): void
    {
        $this->update([
            'is_active' => true,
            'last_check_at' => now(),
            'last_success_at' => now(),
            'check_attempts' => 0,
            'error_message' => null,
        ]);
    }

    /**
     * Mark as unhealthy (failed check)
     */
    public function markAsUnhealthy(string $errorMessage): void
    {
        $this->increment('check_attempts');
        $this->update([
            'is_active' => false,
            'last_check_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Check if webhook needs attention (multiple failed checks)
     */
    public function needsAttention(): bool
    {
        return $this->check_attempts >= 3 || !$this->is_active;
    }

    /**
     * Get identifier for this webhook health stat
     */
    public function getIdentifierAttribute(): string
    {
        return "{$this->entity_type}:{$this->action}";
    }
}
