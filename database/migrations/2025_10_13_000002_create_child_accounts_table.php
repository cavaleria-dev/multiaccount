<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('parent_account_id');
            $table->uuid('child_account_id');
            $table->string('invitation_code', 100)->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamp('connected_at')->useCurrent();
            $table->timestamps();
            
            $table->foreign('parent_account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');
                  
            $table->foreign('child_account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');
            
            $table->unique(['parent_account_id', 'child_account_id']);
            $table->index('parent_account_id');
            $table->index('child_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_accounts');
    }
};
