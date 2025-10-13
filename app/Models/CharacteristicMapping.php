<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacteristicMapping extends Model
{
    protected $table = 'characteristic_mappings';

    protected $fillable = [
        'parent_account_id',
        'child_account_id',
        'parent_characteristic_id',
        'child_characteristic_id',
        'characteristic_name',
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
