<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('app_id');
            $table->uuid('account_id')->unique();
            $table->string('access_token');
            $table->string('status', 50)->default('activating');
            $table->string('account_type', 20)->nullable();
            $table->string('subscription_status', 50)->nullable();
            $table->string('tariff_name', 100)->nullable();
            $table->decimal('price_per_month', 10, 2)->default(0);
            $table->string('cause', 50)->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            
            $table->index('account_id');
            $table->index('status');
            $table->index('account_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
