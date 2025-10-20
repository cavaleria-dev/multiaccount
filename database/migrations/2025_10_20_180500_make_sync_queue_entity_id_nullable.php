<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Изменяет колонку entity_id на nullable для поддержки batch задач.
     *
     * Batch задачи (batch_products, batch_services, product_variants) обрабатывают
     * множество сущностей и не имеют единого entity_id. Данные хранятся в payload.
     */
    public function up(): void
    {
        Schema::table('sync_queue', function (Blueprint $table) {
            // Сделать entity_id nullable для поддержки batch задач
            $table->string('entity_id', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_queue', function (Blueprint $table) {
            // Вернуть NOT NULL
            // ВНИМАНИЕ: rollback НЕ сработает если в таблице есть строки с entity_id = NULL
            $table->string('entity_id', 255)->nullable(false)->change();
        });
    }
};
