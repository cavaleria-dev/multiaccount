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
        Schema::table('moysklad_api_logs', function (Blueprint $table) {
            // Проверяем что колонка еще не существует
            if (!Schema::hasColumn('moysklad_api_logs', 'operation_type')) {
                $table->string('operation_type')
                    ->nullable()
                    ->after('entity_id')
                    ->comment('Тип операции: load, create, update, batch_create, search_existing, mapping и т.д.');

                $table->index('operation_type');
            }

            if (!Schema::hasColumn('moysklad_api_logs', 'operation_result')) {
                $table->string('operation_result')
                    ->nullable()
                    ->after('operation_type')
                    ->comment('Результат операции: success, found_existing, error_412_duplicate и т.д.');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('moysklad_api_logs', function (Blueprint $table) {
            if (Schema::hasColumn('moysklad_api_logs', 'operation_type')) {
                $table->dropIndex(['operation_type']);
                $table->dropColumn('operation_type');
            }

            if (Schema::hasColumn('moysklad_api_logs', 'operation_result')) {
                $table->dropColumn('operation_result');
            }
        });
    }
};
