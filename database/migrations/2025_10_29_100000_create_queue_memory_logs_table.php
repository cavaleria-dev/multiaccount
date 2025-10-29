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
        Schema::create('queue_memory_logs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->nullable()->comment('Уникальный ID запуска ProcessSyncQueueJob');
            $table->integer('batch_index')->default(0)->comment('Индекс задачи в batch (0 = начало, -1 = конец)');
            $table->integer('task_count')->default(1)->comment('Количество задач в batch');
            $table->string('entity_type')->nullable()->comment('Тип сущности (product, service, etc.)');
            $table->decimal('memory_current_mb', 8, 2)->comment('Текущая память в MB');
            $table->decimal('memory_peak_mb', 8, 2)->comment('Пиковая память в MB');
            $table->decimal('memory_limit_mb', 8, 2)->nullable()->comment('Лимит памяти из ini_get');
            $table->integer('duration_ms')->nullable()->comment('Длительность обработки в миллисекундах');
            $table->timestamp('logged_at')->comment('Когда залогировано');

            // Индексы для быстрого поиска
            $table->index('job_id');
            $table->index('logged_at');
            $table->index(['entity_type', 'logged_at']);
            $table->index('memory_peak_mb'); // Для поиска проблемных мест
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_memory_logs');
    }
};
