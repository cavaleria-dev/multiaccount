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
        Schema::table('sync_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('sync_settings', 'service_match_field')) {
                $table->string('service_match_field', 50)
                    ->default('code')
                    ->after('product_match_field')
                    ->comment('Field for matching services: name, code, externalCode, barcode (NO article field in МойСклад API)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            if (Schema::hasColumn('sync_settings', 'service_match_field')) {
                $table->dropColumn('service_match_field');
            }
        });
    }
};
