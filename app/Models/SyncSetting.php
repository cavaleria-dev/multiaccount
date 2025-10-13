<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncSetting extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'sync_settings';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'account_id',
        'sync_catalog',
        'sync_orders',
        'sync_prices',
        'sync_stock',
        'sync_images_all',
        'schedule',
        'catalog_filters',
        'price_types',
        'warehouses',
        'product_match_field',
        // Product sync settings
        'purchase_price_type_id',
        'sale_price_type_id',
        'product_filter_type',
        'product_filter_attribute_id',
        'product_filter_attribute_value',
        'product_filter_folder_ids',
        'require_price_type_filled',
        'sync_product_folders',
        'product_folders_filter',
        'sync_products',
        'sync_variants',
        'sync_bundles',
        'auto_create_attributes',
        'auto_create_characteristics',
        'auto_create_price_types',
        // Purchase orders
        'sync_purchase_orders',
        // Customer orders
        'sync_customer_orders',
        'customer_order_state_id',
        'customer_order_success_state_id',
        'customer_order_sales_channel_id',
        // Retail demands
        'sync_retail_demands',
        'retail_demand_state_id',
        'retail_demand_success_state_id',
        'retail_demand_sales_channel_id',
        // Common order settings
        'sync_real_counterparties',
        'stub_counterparty_id',
        'target_organization_id',
        'responsible_employee_id',
        'franchise_counterparty_group',
        // Scalability settings
        'sync_priority',
        'sync_delay_seconds',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'sync_catalog' => 'boolean',
        'sync_orders' => 'boolean',
        'sync_prices' => 'boolean',
        'sync_stock' => 'boolean',
        'sync_images_all' => 'boolean',
        'catalog_filters' => 'array',
        'price_types' => 'array',
        'warehouses' => 'array',
        'product_filter_folder_ids' => 'array',
        'require_price_type_filled' => 'boolean',
        'sync_product_folders' => 'boolean',
        'product_folders_filter' => 'array',
        'sync_products' => 'boolean',
        'sync_variants' => 'boolean',
        'sync_bundles' => 'boolean',
        'auto_create_attributes' => 'boolean',
        'auto_create_characteristics' => 'boolean',
        'auto_create_price_types' => 'boolean',
        'sync_purchase_orders' => 'boolean',
        'sync_customer_orders' => 'boolean',
        'sync_retail_demands' => 'boolean',
        'sync_real_counterparties' => 'boolean',
        'sync_priority' => 'integer',
        'sync_delay_seconds' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the account that owns the sync settings.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }
}
