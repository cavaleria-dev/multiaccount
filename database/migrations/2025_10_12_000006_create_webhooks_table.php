<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_id');
            $table->string('webhook_id')->nullable(); // ID вебхука в МойСклад
            $table->string('entity_type', 50); // product, customerorder и т.д.
            $table->string('action', 50); // CREATE, UPDATE, DELETE
            $table->boolean('enabled')->default(true);
            $table->string('url');
            $table->timestamps();

            $table->foreign('account_id')
                ->references('account_id')
                ->on('accounts')
                ->onDelete('cascade');

            $table->index('account_id');
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
