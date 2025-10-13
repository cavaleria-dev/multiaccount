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
        Schema::create('sync_statistics', function (Blueprint $table) {
            $table->id();
            $table->uuid('parent_account_id');
            $table->uuid('child_account_id');
            $table->date('date');
            $table->integer('products_synced')->default(0);
            $table->integer('products_failed')->default(0);
            $table->integer('orders_synced')->default(0);
            $table->integer('orders_failed')->default(0);
            $table->integer('sync_duration_avg')->default(0);
            $table->integer('api_calls_count')->default(0);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            // Уникальный индекс для daily stats
            $table->unique(['parent_account_id', 'child_account_id', 'date'], 'unique_daily_stats');

            // Дополнительные индексы
            $table->index(['parent_account_id', 'date'], 'idx_sync_statistics_parent_date');
            $table->index(['child_account_id', 'date'], 'idx_sync_statistics_child_date');

            // Внешние ключи
            $table->foreign('parent_account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');

            $table->foreign('child_account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_statistics');
    }
};
