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
        Schema::create('webhook_health', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_id');
            $table->string('webhook_id', 255)->nullable();
            $table->string('entity_type', 50);
            $table->string('action', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_check_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('check_attempts')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            // Индексы
            $table->index('account_id');
            $table->index('is_active');
            $table->index('last_check_at');
            $table->index(['is_active', 'last_check_at'], 'idx_webhook_health_active_check');

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
        Schema::dropIfExists('webhook_health');
    }
};
