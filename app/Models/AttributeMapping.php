<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeMapping extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'attribute_mappings';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'parent_account_id',
        'child_account_id',
        'entity_type',
        'parent_attribute_id',
        'child_attribute_id',
        'attribute_name',
        'attribute_type',
        'is_synced',
        'auto_created',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_synced' => 'boolean',
        'auto_created' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the parent account.
     */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_account_id', 'account_id');
    }

    /**
     * Get the child account.
     */
    public function childAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'child_account_id', 'account_id');
    }
}
