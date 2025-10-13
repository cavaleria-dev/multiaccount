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
            // Добавить новые поля для франшизной модели
            $table->string('account_name', 255)->nullable()->after('account_id');
            $table->uuid('organization_id')->nullable()->after('account_type');
            $table->uuid('counterparty_id')->nullable()->after('organization_id');
            $table->uuid('supplier_counterparty_id')->nullable()->after('counterparty_id');

            // Добавить индексы
            $table->index('account_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['account_name']);
            $table->dropColumn(['account_name', 'organization_id', 'counterparty_id', 'supplier_counterparty_id']);
        });
    }
};
