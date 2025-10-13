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
        Schema::create('counterparty_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('parent_account_id');
            $table->uuid('child_account_id');
            $table->string('parent_counterparty_id', 255);
            $table->string('child_counterparty_id', 255);
            $table->string('counterparty_name', 255);
            $table->string('counterparty_inn', 20)->nullable();
            $table->boolean('is_stub')->default(false);
            $table->timestamps();

            // Уникальный индекс
            $table->unique(['parent_account_id', 'child_account_id', 'child_counterparty_id'], 'unique_counterparty_mapping');

            // Дополнительные индексы
            $table->index(['parent_account_id', 'child_account_id'], 'idx_counterparty_mappings_accounts');

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
        Schema::dropIfExists('counterparty_mappings');
    }
};
