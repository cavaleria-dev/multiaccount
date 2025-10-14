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
            // Добавить мастер-переключатель синхронизации
            // Если sync_enabled = false, синхронизация полностью отключена для франшизы
            // Если sync_enabled = true, работают настройки sync_products, sync_orders и т.д.
            $table->boolean('sync_enabled')->default(true)->after('account_id');

            // Индекс для быстрого поиска активных франшиз
            $table->index('sync_enabled', 'idx_sync_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            $table->dropIndex('idx_sync_enabled');
            $table->dropColumn('sync_enabled');
        });
    }
};
