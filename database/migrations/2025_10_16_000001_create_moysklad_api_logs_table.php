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
        Schema::create('moysklad_api_logs', function (Blueprint $table) {
            $table->id();

            // Аккаунты
            $table->uuid('account_id')->comment('Аккаунт, который делал запрос');
            $table->enum('direction', ['main_to_child', 'child_to_main', 'internal'])->nullable()->comment('Направление синхронизации');
            $table->uuid('related_account_id')->nullable()->comment('Связанный аккаунт (child или main)');

            // Информация о сущности
            $table->string('entity_type')->nullable()->comment('product, variant, bundle, customerorder и т.д.');
            $table->uuid('entity_id')->nullable()->comment('ID сущности МойСклад');

            // Запрос
            $table->string('method', 10)->comment('GET, POST, PUT, DELETE');
            $table->text('endpoint')->comment('Полный URL эндпоинта');
            $table->json('request_payload')->nullable()->comment('Тело запроса');

            // Ответ
            $table->integer('response_status')->comment('HTTP статус (200, 404, 429, 500...)');
            $table->json('response_body')->nullable()->comment('Ответ от МойСклад');
            $table->text('error_message')->nullable()->comment('Сообщение об ошибке');

            // Метаданные
            $table->json('rate_limit_info')->nullable()->comment('Информация о rate limits');
            $table->integer('duration_ms')->nullable()->comment('Длительность запроса в миллисекундах');

            $table->timestamps();

            // Индексы для быстрого поиска
            $table->index('account_id');
            $table->index('entity_type');
            $table->index('response_status');
            $table->index('created_at');
            $table->index(['account_id', 'response_status']);
            $table->index(['entity_type', 'response_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moysklad_api_logs');
    }
};
