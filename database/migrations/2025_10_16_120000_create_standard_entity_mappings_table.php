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
        Schema::create('standard_entity_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('parent_account_id');
            $table->uuid('child_account_id');
            $table->string('entity_type', 50); // 'uom', 'currency', 'country', 'vat'
            $table->string('parent_entity_id', 255);
            $table->string('child_entity_id', 255);
            $table->string('code', 255)->nullable(); // code/isoCode для маппинга
            $table->string('name', 255)->nullable(); // Название (для отладки)
            $table->json('metadata')->nullable(); // Доп.данные (rate для vat, symbol для currency)
            $table->timestamps();

            // Уникальный индекс - один маппинг на комбинацию (parent, child, type, code)
            $table->unique(['parent_account_id', 'child_account_id', 'entity_type', 'code'], 'unique_standard_entity_mapping');

            // Индекс для быстрого поиска по аккаунтам
            $table->index(['parent_account_id', 'child_account_id', 'entity_type'], 'idx_standard_entity_accounts');

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
        Schema::dropIfExists('standard_entity_mappings');
    }
};
