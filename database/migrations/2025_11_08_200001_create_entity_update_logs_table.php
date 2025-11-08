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
        Schema::create('entity_update_logs', function (Blueprint $table) {
            $table->id();

            // Accounts and entity identification
            $table->uuid('main_account_id')->comment('Main account UUID');
            $table->uuid('child_account_id')->comment('Child account UUID');
            $table->string('entity_type', 50)->comment('Entity type: product, service, variant, order, etc.');
            $table->string('main_entity_id')->comment('Entity ID in main account');
            $table->string('child_entity_id')->nullable()->comment('Entity ID in child account');

            // Update strategy and classification
            $table->string('update_strategy', 50)->comment('Update strategy: SKIP, FULL_SYNC, PRICES_ONLY, ATTRIBUTES_ONLY, etc.');
            $table->json('updated_fields_received')->comment('Raw updatedFields from МойСклад webhook');
            $table->json('fields_classified')->comment('Classified fields: standard, custom_attributes, custom_price_types');
            $table->json('fields_applied')->comment('Fields that were actually updated');
            $table->json('fields_skipped')->nullable()->comment('Fields filtered by sync_settings');

            // Traceability
            $table->string('webhook_request_id')->nullable()->comment('Request ID from webhook (for linking)');
            $table->unsignedBigInteger('sync_queue_id')->nullable()->comment('Sync queue task ID (for linking)');

            // Status and performance
            $table->string('status', 20)->comment('Status: processing, completed, failed');
            $table->text('error_message')->nullable()->comment('Error message if failed');
            $table->integer('processing_time_ms')->nullable()->comment('Processing time in milliseconds');

            $table->timestamps();

            // Indexes for analytics and monitoring
            $table->index(['main_account_id', 'entity_type', 'created_at'], 'idx_main_entity_time');
            $table->index(['child_account_id', 'entity_type', 'created_at'], 'idx_child_entity_time');
            $table->index(['status', 'created_at'], 'idx_status_time');
            $table->index('webhook_request_id', 'idx_webhook_request');
            $table->index('sync_queue_id', 'idx_sync_queue');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_update_logs');
    }
};
