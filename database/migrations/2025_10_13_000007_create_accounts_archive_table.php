<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_archive', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_id');
            $table->json('data');
            $table->timestamp('deleted_at');
            $table->timestamps();
            
            $table->index('account_id');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_archive');
    }
};
