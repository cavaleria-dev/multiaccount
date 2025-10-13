<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_id');
            $table->string('sync_type', 50);
            $table->string('direction', 50);
            $table->string('status', 50);
            $table->text('message')->nullable();
            $table->json('data')->nullable();
            $table->integer('items_total')->default(0);
            $table->integer('items_processed')->default(0);
            $table->integer('items_failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            
            $table->index('account_id');
            $table->index('sync_type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
