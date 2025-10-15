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
            // Добавляем поле sync_images (синхронизировать изображения)
            // Ставим после sync_services
            if (!Schema::hasColumn('sync_settings', 'sync_images')) {
                $table->boolean('sync_images')->default(true)->after('sync_services');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            if (Schema::hasColumn('sync_settings', 'sync_images')) {
                $table->dropColumn('sync_images');
            }
        });
    }
};
