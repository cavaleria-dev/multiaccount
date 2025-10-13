<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('app.franchise_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_account_id')
                ->constrained('app.accounts')
                ->onDelete('cascade')
                ->comment('ID главного аккаунта');
            $table->foreignId('child_account_id')
                ->constrained('app.accounts')
                ->onDelete('cascade')
                ->comment('ID дочернего аккаунта');
            $table->string('child_name')->nullable()->comment('Название дочернего аккаунта');
            $table->text('child_token')->nullable()->comment('Токен дочернего для синхронизации');
            $table->string('status', 20)->default('active')->comment('active, inactive, paused');
            $table->string('webhook_url')->nullable()->comment('URL вебхука');
            $table->string('webhook_secret', 100)->nullable()->comment('Секрет для вебхука');
            $table->timestamp('last_sync_at')->nullable()->comment('Последняя синхронизация');
            $table->text('error_message')->nullable()->comment('Последняя ошибка');
            $table->timestamps();

            // Уникальная связь главный-дочерний
            $table->unique(['main_account_id', 'child_account_id']);

            // Один дочерний может быть только у одного активного главного
            $table->unique(['child_account_id', 'status'], 'unique_active_child');

            $table->index('status');
            $table->index('last_sync_at');
        });

        // Добавляем constraint для проверки уникальности активного дочернего
        DB::statement('
            CREATE UNIQUE INDEX unique_active_child_account 
            ON app.franchise_links (child_account_id) 
            WHERE status = \'active\'
        ');
    }

    public function down()
    {
        Schema::dropIfExists('app.franchise_links');
    }
};