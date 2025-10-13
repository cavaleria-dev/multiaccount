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
        Schema::create('sync_queue', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_id');
            $table->string('entity_type', 50);
            $table->string('entity_id', 255);
            $table->string('operation', 20);
            $table->integer('priority')->default(0);
            $table->string('status', 20);
            $table->json('payload')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->text('error')->nullable();
            $table->json('rate_limit_info')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Индексы
            $table->index(['account_id', 'status']);
            $table->index('scheduled_at');
            $table->index('priority', 'idx_sync_queue_priority');
            $table->index(['status', 'scheduled_at'], 'idx_sync_queue_status_scheduled');
            $table->index(['account_id', 'entity_type', 'entity_id'], 'idx_sync_queue_account_entity');

            // Внешний ключ
            $table->foreign('account_id')
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
        Schema::dropIfExists('sync_queue');
    }
};
