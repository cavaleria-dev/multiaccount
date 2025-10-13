<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildAccount extends Model
{
    protected $table = 'child_accounts';

    protected $fillable = [
        'parent_account_id',
        'child_account_id',
        'is_active',
        'linked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'linked_at' => 'datetime',
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
