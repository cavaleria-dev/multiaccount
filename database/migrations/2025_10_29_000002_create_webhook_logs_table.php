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
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();

            // Request identification (for idempotency)
            $table->string('request_id', 100)->unique()->comment('МойСклад requestId for idempotency');

            // Account & webhook reference
            $table->uuid('account_id');
            $table->foreignId('webhook_id')->nullable()->constrained('webhooks')->onDelete('set null');

            // Event details
            $table->string('entity_type', 50)->comment('product, service, variant, customerorder, etc.');
            $table->string('action', 20)->comment('CREATE, UPDATE, DELETE');

            // Payload from МойСклад (full webhook body)
            $table->json('payload')->comment('Full webhook payload from МойСклад');

            // Processing status
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->timestamp('processed_at')->nullable()->comment('When webhook was processed');
            $table->text('error_message')->nullable()->comment('Error details if failed');

            // Processing metrics
            $table->integer('processing_time_ms')->nullable()->comment('Time taken to process (milliseconds)');
            $table->integer('events_count')->default(1)->comment('Number of events in this webhook');

            $table->timestamps();

            // Foreign keys
            $table->foreign('account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');

            // Indexes for performance
            $table->index('account_id');
            $table->index('status');
            $table->index('created_at');
            $table->index(['entity_type', 'action']);
            $table->index(['account_id', 'status', 'created_at'], 'idx_webhook_logs_account_status_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
