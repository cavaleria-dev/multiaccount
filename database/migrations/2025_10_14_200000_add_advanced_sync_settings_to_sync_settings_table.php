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
            // Синхронизация услуг
            $table->boolean('sync_services')->default(true)->after('sync_bundles');

            // Поле для сопоставления товаров (code, article, externalCode, barcode)
            $table->string('product_match_field', 50)->default('article')->after('product_filters');

            // Создавать ли группы товаров (productFolder) в дочернем аккаунте
            $table->boolean('create_product_folders')->default(true)->after('product_match_field');

            // Маппинг типов цен: [{"main_price_type_id": "uuid", "child_price_type_id": "uuid"}]
            // Пусто = синхронизировать все типы цен
            $table->json('price_mappings')->nullable()->after('create_product_folders');

            // Список ID атрибутов для синхронизации: ["uuid1", "uuid2", ...]
            // Пусто = синхронизировать все атрибуты
            $table->json('attribute_sync_list')->nullable()->after('price_mappings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            $table->dropColumn([
                'sync_services',
                'product_match_field',
                'create_product_folders',
                'price_mappings',
                'attribute_sync_list',
            ]);
        });
    }
};
