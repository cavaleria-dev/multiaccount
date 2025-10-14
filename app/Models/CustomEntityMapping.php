<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomEntityMapping extends Model
{
    protected $table = 'custom_entity_mappings';

    protected $fillable = [
        'parent_account_id',
        'child_account_id',
        'parent_custom_entity_id',
        'child_custom_entity_id',
        'custom_entity_name',
        'auto_created',
    ];

    protected $casts = [
        'auto_created' => 'boolean',
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
