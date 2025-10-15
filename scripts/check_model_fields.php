#!/usr/bin/env php
<?php

/**
 * Script to check if all fields in SyncSetting model's $fillable
 * exist in database migrations.
 *
 * Usage: php scripts/check_model_fields.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Schema;

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Model fields from $fillable
$modelFields = [
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
    'sync_purchase_orders',
    'sync_customer_orders',
    'customer_order_state_id',
    'customer_order_success_state_id',
    'customer_order_sales_channel_id',
    'sync_retail_demands',
    'retail_demand_state_id',
    'retail_demand_success_state_id',
    'retail_demand_sales_channel_id',
    'sync_real_counterparties',
    'stub_counterparty_id',
    'target_organization_id',
    'responsible_employee_id',
    'franchise_counterparty_group',
    'sync_priority',
    'sync_delay_seconds',
    'target_store_id',
    'target_project_id',
    'purchase_order_state_id',
    'purchase_order_sales_channel_id',
    'supplier_counterparty_id',
    'target_objects_meta',
    'product_filters_enabled',
];

echo "Checking sync_settings table columns...\n\n";

$missing = [];
$existing = [];

foreach ($modelFields as $field) {
    if (Schema::hasColumn('sync_settings', $field)) {
        $existing[] = $field;
        echo "✓ {$field}\n";
    } else {
        $missing[] = $field;
        echo "✗ {$field} - MISSING\n";
    }
}

echo "\n";
echo "Summary:\n";
echo "  Existing: " . count($existing) . "\n";
echo "  Missing:  " . count($missing) . "\n";

if (!empty($missing)) {
    echo "\n";
    echo "Missing fields:\n";
    foreach ($missing as $field) {
        echo "  - {$field}\n";
    }
    exit(1);
}

echo "\n✅ All fields exist in database!\n";
exit(0);
