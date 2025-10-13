<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'accounts';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'app_id',
        'account_id',
        'account_name',
        'account_type',
        'access_token',
        'status',
        'subscription_status',
        'tariff_name',
        'price_per_month',
        'cause',
        'organization_id',
        'counterparty_id',
        'supplier_counterparty_id',
        'installed_at',
        'suspended_at',
        'uninstalled_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'price_per_month' => 'decimal:2',
        'installed_at' => 'datetime',
        'suspended_at' => 'datetime',
        'uninstalled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the sync settings for this account.
     */
    public function syncSettings(): HasOne
    {
        return $this->hasOne(SyncSetting::class, 'account_id', 'account_id');
    }

    /**
     * Get all child accounts (if this is a main account).
     */
    public function childAccounts(): HasMany
    {
        return $this->hasMany(ChildAccount::class, 'parent_account_id', 'account_id');
    }

    /**
     * Get sync queue items for this account.
     */
    public function syncQueue(): HasMany
    {
        return $this->hasMany(SyncQueue::class, 'account_id', 'account_id');
    }

    /**
     * Get webhook health records for this account.
     */
    public function webhookHealth(): HasMany
    {
        return $this->hasMany(WebhookHealth::class, 'account_id', 'account_id');
    }

    /**
     * Get entity mappings where this account is the parent.
     */
    public function parentMappings(): HasMany
    {
        return $this->hasMany(EntityMapping::class, 'parent_account_id', 'account_id');
    }

    /**
     * Get entity mappings where this account is the child.
     */
    public function childMappings(): HasMany
    {
        return $this->hasMany(EntityMapping::class, 'child_account_id', 'account_id');
    }

    /**
     * Check if account is a main account.
     */
    public function isMainAccount(): bool
    {
        return $this->account_type === 'main';
    }

    /**
     * Check if account is a child account.
     */
    public function isChildAccount(): bool
    {
        return $this->account_type === 'child';
    }

    /**
     * Check if account is activated.
     */
    public function isActivated(): bool
    {
        return $this->status === 'activated';
    }
}
