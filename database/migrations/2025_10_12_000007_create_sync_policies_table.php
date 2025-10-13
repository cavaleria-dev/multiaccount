<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('app.sync_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('app.accounts')
                ->onDelete('cascade')
                ->comment('ID главного аккаунта');
            $table->foreignId('franchise_link_id')
                ->nullable()
                ->constrained('app.franchise_links')
                ->onDelete('cascade')
                ->comment('Связь (null = для всех)');
            $table->string('name')->comment('Название политики');
            $table->string('policy_type', 50)->comment('products, orders, prices');
            $table->jsonb('settings')->comment('Настройки маппинга и фильтров');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('account_id');
            $table->index(['policy_type', 'is_active']);
        });

        DB::statement('CREATE INDEX sync_policies_settings_gin ON app.sync_policies USING GIN (settings)');
    }

    public function down()
    {
        Schema::dropIfExists('app.sync_policies');
    }
};