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
            if (!Schema::hasColumn('sync_settings', 'sync_vat')) {
                // Синхронизировать настройки НДС из главного аккаунта
                $table->boolean('sync_vat')->default(false)->after('sync_images');
            }

            if (!Schema::hasColumn('sync_settings', 'vat_sync_mode')) {
                // Режим синхронизации НДС:
                // 'from_main' - брать значения НДС из главного аккаунта
                // 'preserve_child' - оставлять настройки НДС дочернего аккаунта
                $table->string('vat_sync_mode', 20)->default('preserve_child')->after('sync_vat');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            if (Schema::hasColumn('sync_settings', 'vat_sync_mode')) {
                $table->dropColumn('vat_sync_mode');
            }

            if (Schema::hasColumn('sync_settings', 'sync_vat')) {
                $table->dropColumn('sync_vat');
            }
        });
    }
};
