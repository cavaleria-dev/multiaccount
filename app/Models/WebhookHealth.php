<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookHealth extends Model
{
    protected $table = 'webhook_health';

    protected $fillable = [
        'account_id',
        'webhook_id',
        'entity_type',
        'action',
        'is_active',
        'last_check_at',
        'error_message',
        'check_attempts',
        'last_success_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'check_attempts' => 'integer',
        'last_check_at' => 'datetime',
        'last_success_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }
}
