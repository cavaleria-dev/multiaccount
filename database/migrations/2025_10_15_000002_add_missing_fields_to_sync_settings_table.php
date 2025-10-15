<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            // Product filter attribute value (for filtering products by attribute value)
            if (!Schema::hasColumn('sync_settings', 'product_filter_attribute_value')) {
                $table->string('product_filter_attribute_value', 255)->nullable()->after('product_filter_attribute_id');
            }

            // Target objects in main account (for document creation)
            if (!Schema::hasColumn('sync_settings', 'target_store_id')) {
                $table->uuid('target_store_id')->nullable()->after('target_organization_id');
            }

            if (!Schema::hasColumn('sync_settings', 'target_project_id')) {
                $table->uuid('target_project_id')->nullable()->after('target_store_id');
            }

            // Purchase order specific settings
            if (!Schema::hasColumn('sync_settings', 'purchase_order_state_id')) {
                $table->uuid('purchase_order_state_id')->nullable()->after('sync_purchase_orders');
            }

            if (!Schema::hasColumn('sync_settings', 'purchase_order_sales_channel_id')) {
                $table->uuid('purchase_order_sales_channel_id')->nullable()->after('purchase_order_state_id');
            }

            if (!Schema::hasColumn('sync_settings', 'supplier_counterparty_id')) {
                $table->uuid('supplier_counterparty_id')->nullable()->after('purchase_order_sales_channel_id');
            }

            // Product filters enabled flag
            if (!Schema::hasColumn('sync_settings', 'product_filters_enabled')) {
                $table->boolean('product_filters_enabled')->default(false)->after('attribute_sync_list');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            $columnsToDelete = [];

            if (Schema::hasColumn('sync_settings', 'product_filter_attribute_value')) {
                $columnsToDelete[] = 'product_filter_attribute_value';
            }

            if (Schema::hasColumn('sync_settings', 'target_store_id')) {
                $columnsToDelete[] = 'target_store_id';
            }

            if (Schema::hasColumn('sync_settings', 'target_project_id')) {
                $columnsToDelete[] = 'target_project_id';
            }

            if (Schema::hasColumn('sync_settings', 'purchase_order_state_id')) {
                $columnsToDelete[] = 'purchase_order_state_id';
            }

            if (Schema::hasColumn('sync_settings', 'purchase_order_sales_channel_id')) {
                $columnsToDelete[] = 'purchase_order_sales_channel_id';
            }

            if (Schema::hasColumn('sync_settings', 'supplier_counterparty_id')) {
                $columnsToDelete[] = 'supplier_counterparty_id';
            }

            if (Schema::hasColumn('sync_settings', 'product_filters_enabled')) {
                $columnsToDelete[] = 'product_filters_enabled';
            }

            if (!empty($columnsToDelete)) {
                $table->dropColumn($columnsToDelete);
            }
        });
    }
};
