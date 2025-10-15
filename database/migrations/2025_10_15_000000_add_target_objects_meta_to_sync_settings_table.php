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
            // JSON поле для хранения мета-информации о выбранных объектах (ID + имя)
            // Используется для отображения имен в UI без дополнительных запросов к МойСклад API
            $table->json('target_objects_meta')->nullable()->after('supplier_counterparty_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            $table->dropColumn('target_objects_meta');
        });
    }
};
