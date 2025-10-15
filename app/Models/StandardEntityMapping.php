<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandardEntityMapping extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'standard_entity_mappings';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'parent_account_id',
        'child_account_id',
        'entity_type',
        'parent_entity_id',
        'child_entity_id',
        'code',
        'name',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'metadata' => 'array',
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
