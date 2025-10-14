<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomEntityElementMapping extends Model
{
    protected $table = 'custom_entity_element_mappings';

    protected $fillable = [
        'parent_account_id',
        'child_account_id',
        'parent_custom_entity_id',
        'child_custom_entity_id',
        'parent_element_id',
        'child_element_id',
        'element_name',
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
