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
        Schema::create('custom_entity_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('parent_account_id');
            $table->uuid('child_account_id');
            $table->string('parent_custom_entity_id', 255);
            $table->string('child_custom_entity_id', 255);
            $table->string('custom_entity_name', 255);
            $table->boolean('auto_created')->default(false);
            $table->timestamps();

            // Уникальный индекс
            $table->unique(
                ['parent_account_id', 'child_account_id', 'custom_entity_name'],
                'unique_custom_entity_mapping'
            );

            // Дополнительные индексы
            $table->index(['parent_account_id', 'child_account_id'], 'idx_custom_entity_accounts');

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

        // Таблица для маппинга элементов справочников
        Schema::create('custom_entity_element_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('parent_account_id');
            $table->uuid('child_account_id');
            $table->string('parent_custom_entity_id', 255);
            $table->string('child_custom_entity_id', 255);
            $table->string('parent_element_id', 255);
            $table->string('child_element_id', 255);
            $table->string('element_name', 255);
            $table->boolean('auto_created')->default(false);
            $table->timestamps();

            // Уникальный индекс
            $table->unique(
                ['parent_account_id', 'child_account_id', 'parent_custom_entity_id', 'parent_element_id'],
                'unique_custom_entity_element_mapping'
            );

            // Дополнительные индексы
            $table->index(
                ['parent_account_id', 'child_account_id', 'parent_custom_entity_id'],
                'idx_custom_entity_element_accounts'
            );

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
        Schema::dropIfExists('custom_entity_element_mappings');
        Schema::dropIfExists('custom_entity_mappings');
    }
};
