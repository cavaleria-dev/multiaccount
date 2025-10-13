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
            // Настройки товаров
            $table->uuid('purchase_price_type_id')->nullable()->after('product_match_field');
            $table->uuid('sale_price_type_id')->nullable()->after('purchase_price_type_id');
            $table->enum('product_filter_type', ['attribute', 'folder', 'both'])->nullable()->after('sale_price_type_id');
            $table->uuid('product_filter_attribute_id')->nullable()->after('product_filter_type');
            $table->text('product_filter_attribute_value')->nullable()->after('product_filter_attribute_id');
            $table->json('product_filter_folder_ids')->nullable()->after('product_filter_attribute_value');
            $table->boolean('require_price_type_filled')->default(true)->after('product_filter_folder_ids');
            $table->boolean('sync_product_folders')->default(true)->after('require_price_type_filled');
            $table->json('product_folders_filter')->nullable()->after('sync_product_folders');
            $table->boolean('sync_products')->default(true)->after('product_folders_filter');
            $table->boolean('sync_variants')->default(true)->after('sync_products');
            $table->boolean('sync_bundles')->default(true)->after('sync_variants');
            $table->boolean('auto_create_attributes')->default(true)->after('sync_bundles');
            $table->boolean('auto_create_characteristics')->default(true)->after('auto_create_attributes');
            $table->boolean('auto_create_price_types')->default(true)->after('auto_create_characteristics');

            // Настройки заказов поставщику
            $table->boolean('sync_purchase_orders')->default(false)->after('auto_create_price_types');

            // Настройки заказов покупателя
            $table->boolean('sync_customer_orders')->default(false)->after('sync_purchase_orders');
            $table->uuid('customer_order_state_id')->nullable()->after('sync_customer_orders');
            $table->uuid('customer_order_success_state_id')->nullable()->after('customer_order_state_id');
            $table->uuid('customer_order_sales_channel_id')->nullable()->after('customer_order_success_state_id');

            // Настройки розничных продаж
            $table->boolean('sync_retail_demands')->default(false)->after('customer_order_sales_channel_id');
            $table->uuid('retail_demand_state_id')->nullable()->after('sync_retail_demands');
            $table->uuid('retail_demand_success_state_id')->nullable()->after('retail_demand_state_id');
            $table->uuid('retail_demand_sales_channel_id')->nullable()->after('retail_demand_success_state_id');

            // Общие настройки для заказов/продаж
            $table->boolean('sync_real_counterparties')->default(false)->after('retail_demand_sales_channel_id');
            $table->uuid('stub_counterparty_id')->nullable()->after('sync_real_counterparties');
            $table->uuid('target_organization_id')->nullable()->after('stub_counterparty_id');
            $table->uuid('responsible_employee_id')->nullable()->after('target_organization_id');
            $table->uuid('franchise_counterparty_group')->nullable()->after('responsible_employee_id');

            // Настройки масштабирования
            $table->integer('sync_priority')->default(5)->after('franchise_counterparty_group');
            $table->integer('sync_delay_seconds')->default(0)->after('sync_priority');

            // Добавить индексы
            $table->index('sync_priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            $table->dropIndex(['sync_priority']);

            $table->dropColumn([
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
                'sync_delay_seconds'
            ]);
        });
    }
};
