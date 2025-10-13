<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_id');
            $table->boolean('sync_catalog')->default(true);
            $table->boolean('sync_orders')->default(true);
            $table->boolean('sync_prices')->default(true);
            $table->boolean('sync_stock')->default(true);
            $table->boolean('sync_images_all')->default(false);
            $table->string('schedule', 100)->nullable();
            $table->json('catalog_filters')->nullable();
            $table->json('price_types')->nullable();
            $table->json('warehouses')->nullable();
            $table->string('product_match_field', 50)->default('article');
            $table->timestamps();
            
            $table->foreign('account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');
            
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_settings');
    }
};
