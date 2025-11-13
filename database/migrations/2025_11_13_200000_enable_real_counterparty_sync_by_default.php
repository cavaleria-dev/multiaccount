<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Изменить default для поля sync_real_counterparties на true
        Schema::table('sync_settings', function (Blueprint $table) {
            $table->boolean('sync_real_counterparties')->default(true)->change();
        });

        // Обновить существующие записи где sync_real_counterparties = false
        // и stub_counterparty_id не задан (чтобы избежать ошибки "Stub counterparty not configured")
        $updatedCount = DB::table('sync_settings')
            ->where('sync_real_counterparties', false)
            ->whereNull('stub_counterparty_id')
            ->update(['sync_real_counterparties' => true]);

        Log::info('Enabled real counterparty sync by default', [
            'migration' => '2025_11_13_200000_enable_real_counterparty_sync_by_default',
            'updated_records' => $updatedCount,
            'total_records' => DB::table('sync_settings')->count()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Вернуть default на false
        Schema::table('sync_settings', function (Blueprint $table) {
            $table->boolean('sync_real_counterparties')->default(false)->change();
        });
    }
};
