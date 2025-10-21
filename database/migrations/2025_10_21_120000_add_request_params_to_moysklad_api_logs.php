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
            if (!Schema::hasColumn('moysklad_api_logs', 'request_params')) {
                $table->json('request_params')
                    ->nullable()
                    ->after('endpoint')
                    ->comment('GET/POST параметры запроса (filter, limit, offset, expand и т.д.)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('moysklad_api_logs', function (Blueprint $table) {
            if (Schema::hasColumn('moysklad_api_logs', 'request_params')) {
                $table->dropColumn('request_params');
            }
        });
    }
};
