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
            // Удалить старое поле product_filter_type
            $table->dropColumn('product_filter_type');

            // Добавить новые поля для фильтрации
            $table->json('product_filters')->nullable()->after('sync_bundles');
            $table->boolean('product_filters_enabled')->default(false)->after('sync_bundles');

            // Индекс для быстрого поиска аккаунтов с включенными фильтрами
            $table->index('product_filters_enabled', 'idx_product_filters_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            $table->dropIndex('idx_product_filters_enabled');
            $table->dropColumn(['product_filters', 'product_filters_enabled']);

            // Вернуть старое поле
            $table->string('product_filter_type')->nullable()->after('sync_bundles');
        });
    }
};
