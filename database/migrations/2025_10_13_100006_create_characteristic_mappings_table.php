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
        Schema::create('characteristic_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('parent_account_id');
            $table->uuid('child_account_id');
            $table->string('parent_characteristic_id', 255);
            $table->string('child_characteristic_id', 255);
            $table->string('characteristic_name', 255);
            $table->boolean('auto_created')->default(false);
            $table->timestamps();

            // Индексы
            $table->index(['parent_account_id', 'child_account_id'], 'idx_characteristic_mappings_accounts');

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
        Schema::dropIfExists('characteristic_mappings');
    }
};
