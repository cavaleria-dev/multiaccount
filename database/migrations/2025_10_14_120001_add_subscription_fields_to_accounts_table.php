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
        Schema::table('accounts', function (Blueprint $table) {
            // Добавить поля для полной информации о подписке
            $table->uuid('tariff_id')->nullable()->after('tariff_name');
            $table->timestamp('subscription_expires_at')->nullable()->after('subscription_status');

            // Индекс для быстрого поиска истекающих подписок
            $table->index('subscription_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['subscription_expires_at']);
            $table->dropColumn(['tariff_id', 'subscription_expires_at']);
        });
    }
};
