<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('app.products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('app.accounts')
                ->onDelete('cascade');
            $table->string('ms_id', 100)->comment('ID товара в МойСклад');
            $table->string('name', 500)->nullable();
            $table->string('sku', 100)->nullable()->comment('Артикул');
            $table->string('code', 100)->nullable()->comment('Код');
            $table->string('external_code', 100)->nullable()->comment('Внешний код');
            $table->string('barcode', 100)->nullable()->comment('Штрихкод');
            $table->decimal('price', 15, 2)->nullable()->comment('Цена');
            $table->jsonb('data')->nullable()->comment('Полные данные из МойСклад');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'ms_id']);
            $table->index('sku');
            $table->index('code');
            $table->index('external_code');
            $table->index('barcode');
            $table->index('synced_at');
        });

        // GIN индекс для JSONB
        DB::statement('CREATE INDEX products_data_gin ON app.products USING GIN (data)');
    }

    public function down()
    {
        Schema::dropIfExists('app.products');
    }
};