<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncStatistic extends Model
{
    protected $table = 'sync_statistics';

    protected $fillable = [
        'parent_account_id',
        'child_account_id',
        'date',
        'products_synced',
        'products_failed',
        'orders_synced',
        'orders_failed',
        'sync_duration_avg',
        'api_calls_count',
        'last_sync_at',
    ];

    protected $casts = [
        'date' => 'date',
        'products_synced' => 'integer',
        'products_failed' => 'integer',
        'orders_synced' => 'integer',
        'orders_failed' => 'integer',
        'sync_duration_avg' => 'integer',
        'api_calls_count' => 'integer',
        'last_sync_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_account_id', 'account_id');
    }

    public function childAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'child_account_id', 'account_id');
    }
}
