<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('app.orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('app.accounts')
                ->onDelete('cascade');
            $table->string('ms_id', 100)->comment('ID заказа в МойСклад');
            $table->string('number', 50)->nullable()->comment('Номер заказа');
            $table->string('status', 100)->nullable()->comment('Статус заказа');
            $table->string('type', 50)->nullable()->comment('customerorder, purchaseorder');
            $table->decimal('sum', 15, 2)->nullable()->comment('Сумма заказа');
            $table->jsonb('data')->nullable()->comment('Полные данные из МойСклад');
            $table->timestamp('order_date')->nullable()->comment('Дата заказа');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'ms_id']);
            $table->index('number');
            $table->index('status');
            $table->index('type');
            $table->index('order_date');
            $table->index('synced_at');
        });

        DB::statement('CREATE INDEX orders_data_gin ON app.orders USING GIN (data)');
    }

    public function down()
    {
        Schema::dropIfExists('app.orders');
    }
};