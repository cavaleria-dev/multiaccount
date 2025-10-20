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
        'sync_enabled',
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
        'service_match_field',
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
        'sync_services',
        'sync_images',
        'create_product_folders',
        'price_mappings',
        'attribute_sync_list',
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
        // Target objects settings
        'target_store_id',
        'target_project_id',
        'purchase_order_state_id',
        'purchase_order_sales_channel_id',
        'supplier_counterparty_id',
        'target_objects_meta',
        'product_filters',
        'product_filters_enabled',
        // VAT sync settings
        'sync_vat',
        'vat_sync_mode',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'sync_enabled' => 'boolean',
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
        'sync_services' => 'boolean',
        'sync_images' => 'boolean',
        'create_product_folders' => 'boolean',
        'price_mappings' => 'array',
        'attribute_sync_list' => 'array',
        'auto_create_attributes' => 'boolean',
        'auto_create_characteristics' => 'boolean',
        'auto_create_price_types' => 'boolean',
        'sync_purchase_orders' => 'boolean',
        'sync_customer_orders' => 'boolean',
        'sync_retail_demands' => 'boolean',
        'sync_real_counterparties' => 'boolean',
        'sync_priority' => 'integer',
        'sync_delay_seconds' => 'integer',
        'target_objects_meta' => 'array',
        'product_filters' => 'array',
        'product_filters_enabled' => 'boolean',
        'sync_vat' => 'boolean',
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
