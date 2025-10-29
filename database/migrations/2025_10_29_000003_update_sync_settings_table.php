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
            // Add account_type column (main/child)
            if (!Schema::hasColumn('sync_settings', 'account_type')) {
                $table->string('account_type', 10)->default('main')->after('account_id')
                      ->comment('Account type: main (франшиза) or child (франчайзи)');
            }

            // Add webhooks_enabled flag
            if (!Schema::hasColumn('sync_settings', 'webhooks_enabled')) {
                $table->boolean('webhooks_enabled')->default(false)->after('sync_images_all')
                      ->comment('Enable real-time webhook synchronization');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            if (Schema::hasColumn('sync_settings', 'webhooks_enabled')) {
                $table->dropColumn('webhooks_enabled');
            }

            if (Schema::hasColumn('sync_settings', 'account_type')) {
                $table->dropColumn('account_type');
            }
        });
    }
};
