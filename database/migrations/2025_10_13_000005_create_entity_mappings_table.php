<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('parent_account_id');
            $table->uuid('child_account_id');
            $table->string('entity_type', 50);
            $table->string('parent_entity_id');
            $table->string('child_entity_id');
            $table->string('entity_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['parent_account_id', 'child_account_id']);
            $table->index('entity_type');
            $table->unique(['parent_account_id', 'child_account_id', 'entity_type', 'parent_entity_id'], 'entity_mapping_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_mappings');
    }
};
