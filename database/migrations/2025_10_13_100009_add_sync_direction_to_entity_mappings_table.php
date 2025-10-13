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
        Schema::table('entity_mappings', function (Blueprint $table) {
            $table->string('match_field', 50)->nullable()->after('metadata');
            $table->string('match_value', 255)->nullable()->after('match_field');
            $table->enum('sync_direction', ['main_to_child', 'child_to_main', 'both'])->default('main_to_child')->after('match_value');
            $table->string('source_document_type', 50)->nullable()->after('sync_direction');

            // Добавить индексы
            $table->index('sync_direction');
            $table->index(['parent_account_id', 'entity_type'], 'idx_entity_mappings_parent_type');
            $table->index(['child_account_id', 'entity_type'], 'idx_entity_mappings_child_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entity_mappings', function (Blueprint $table) {
            $table->dropIndex(['sync_direction']);
            $table->dropIndex('idx_entity_mappings_parent_type');
            $table->dropIndex('idx_entity_mappings_child_type');

            $table->dropColumn(['match_field', 'match_value', 'sync_direction', 'source_document_type']);
        });
    }
};
