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
        Schema::create('attribute_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('parent_account_id');
            $table->uuid('child_account_id');
            $table->string('entity_type', 50);
            $table->string('parent_attribute_id', 255);
            $table->string('child_attribute_id', 255);
            $table->string('attribute_name', 255);
            $table->string('attribute_type', 50);
            $table->boolean('is_synced')->default(true);
            $table->boolean('auto_created')->default(false);
            $table->timestamps();

            // Уникальный индекс
            $table->unique(['parent_account_id', 'child_account_id', 'entity_type', 'attribute_name'], 'unique_attribute_mapping');

            // Дополнительные индексы
            $table->index(['parent_account_id', 'child_account_id', 'entity_type'], 'idx_attribute_mappings_accounts');

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
        Schema::dropIfExists('attribute_mappings');
    }
};
