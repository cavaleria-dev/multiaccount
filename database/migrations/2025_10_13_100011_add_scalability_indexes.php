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
        // Добавить индексы для child_accounts (используем status вместо is_active)
        Schema::table('child_accounts', function (Blueprint $table) {
            $table->index(['parent_account_id', 'status'], 'idx_child_accounts_parent_status');
        });

        // Добавить индексы для sync_logs (только если таблица существует)
        if (Schema::hasTable('sync_logs')) {
            Schema::table('sync_logs', function (Blueprint $table) {
                $table->index(['account_id', 'created_at'], 'idx_sync_logs_account_date');
                $table->index(['status', 'created_at'], 'idx_sync_logs_status_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('child_accounts', function (Blueprint $table) {
            $table->dropIndex('idx_child_accounts_parent_status');
        });

        if (Schema::hasTable('sync_logs')) {
            Schema::table('sync_logs', function (Blueprint $table) {
                $table->dropIndex('idx_sync_logs_account_date');
                $table->dropIndex('idx_sync_logs_status_date');
            });
        }
    }
};
