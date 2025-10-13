<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('app.accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_id', 50)->unique()->comment('ID аккаунта в МойСклад');
            $table->string('app_uid', 100)->unique()->comment('Уникальный ID установки приложения');
            $table->string('app_id', 100)->nullable()->comment('ID приложения');
            $table->string('user_id', 100)->nullable()->comment('ID пользователя');
            $table->string('account_name')->nullable()->comment('Название аккаунта');
            $table->boolean('is_main')->default(false)->comment('Главный аккаунт?');
            $table->string('status', 20)->default('active')->comment('active, inactive, suspended');
            $table->text('ms_token')->nullable()->comment('Bearer токен для МойСклад API');
            $table->timestamp('installed_at')->nullable()->comment('Дата установки');
            $table->timestamps();

            $table->index('account_id');
            $table->index('app_uid');
            $table->index(['is_main', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('app.accounts');
    }
};
