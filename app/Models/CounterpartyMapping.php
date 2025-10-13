<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounterpartyMapping extends Model
{
    protected $table = 'counterparty_mappings';

    protected $fillable = [
        'parent_account_id',
        'child_account_id',
        'parent_counterparty_id',
        'child_counterparty_id',
        'counterparty_name',
        'counterparty_inn',
        'is_stub',
    ];

    protected $casts = [
        'is_stub' => 'boolean',
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
