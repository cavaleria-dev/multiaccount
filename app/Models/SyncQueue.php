<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncQueue extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'sync_queue';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'account_id',
        'entity_type',
        'entity_id',
        'operation',
        'priority',
        'status',
        'payload',
        'attempts',
        'max_attempts',
        'error',
        'rate_limit_info',
        'scheduled_at',
        'started_at',
        'completed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'payload' => 'array',
        'rate_limit_info' => 'array',
        'priority' => 'integer',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the account that owns the sync queue item.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /**
     * Check if the task is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the task is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if the task is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the task has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the task can be retried.
     */
    public function canRetry(): bool
    {
        return $this->attempts < $this->max_attempts;
    }
}
