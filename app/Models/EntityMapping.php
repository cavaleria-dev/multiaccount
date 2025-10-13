<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityMapping extends Model
{
    protected $table = 'entity_mappings';

    protected $fillable = [
        'parent_account_id',
        'child_account_id',
        'entity_type',
        'parent_entity_id',
        'child_entity_id',
        'metadata',
        'match_field',
        'match_value',
        'sync_direction',
        'source_document_type',
    ];

    protected $casts = [
        'metadata' => 'array',
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

    /**
     * Check if sync direction is from main to child.
     */
    public function isMainToChild(): bool
    {
        return $this->sync_direction === 'main_to_child';
    }

    /**
     * Check if sync direction is from child to main.
     */
    public function isChildToMain(): bool
    {
        return $this->sync_direction === 'child_to_main';
    }

    /**
     * Check if sync is bidirectional.
     */
    public function isBoth(): bool
    {
        return $this->sync_direction === 'both';
    }
}
