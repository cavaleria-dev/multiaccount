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
            if (!Schema::hasColumn('sync_settings', 'sync_services')) {
                $table->boolean('sync_services')->default(true)->after('sync_bundles');
            }

            // Поле для сопоставления товаров (code, article, externalCode, barcode)
            // Это поле уже существует в базе, пропускаем
            // if (!Schema::hasColumn('sync_settings', 'product_match_field')) {
            //     $table->string('product_match_field', 50)->default('article')->after('product_filters');
            // }

            // Создавать ли группы товаров (productFolder) в дочернем аккаунте
            if (!Schema::hasColumn('sync_settings', 'create_product_folders')) {
                $table->boolean('create_product_folders')->default(true)->after('product_match_field');
            }

            // Маппинг типов цен: [{"main_price_type_id": "uuid", "child_price_type_id": "uuid"}]
            // Пусто = синхронизировать все типы цен
            if (!Schema::hasColumn('sync_settings', 'price_mappings')) {
                $table->json('price_mappings')->nullable()->after('create_product_folders');
            }

            // Список ID атрибутов для синхронизации: ["uuid1", "uuid2", ...]
            // Пусто = синхронизировать все атрибуты
            if (!Schema::hasColumn('sync_settings', 'attribute_sync_list')) {
                $table->json('attribute_sync_list')->nullable()->after('price_mappings');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            // Удаляем только те колонки, которые добавили в этой миграции
            // product_match_field существовал ранее, не удаляем его
            $columnsToDelete = [];

            if (Schema::hasColumn('sync_settings', 'sync_services')) {
                $columnsToDelete[] = 'sync_services';
            }

            if (Schema::hasColumn('sync_settings', 'create_product_folders')) {
                $columnsToDelete[] = 'create_product_folders';
            }

            if (Schema::hasColumn('sync_settings', 'price_mappings')) {
                $columnsToDelete[] = 'price_mappings';
            }

            if (Schema::hasColumn('sync_settings', 'attribute_sync_list')) {
                $columnsToDelete[] = 'attribute_sync_list';
            }

            if (!empty($columnsToDelete)) {
                $table->dropColumn($columnsToDelete);
            }
        });
    }
};
