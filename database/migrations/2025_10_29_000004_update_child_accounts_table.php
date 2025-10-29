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
        Schema::table('child_accounts', function (Blueprint $table) {
            // Note: 'status' column already exists in the original migration

            // Add inactive_reason (why child account was deactivated)
            if (!Schema::hasColumn('child_accounts', 'inactive_reason')) {
                $table->text('inactive_reason')->nullable()->after('status')
                      ->comment('Reason for deactivation if status != active');
            }

            // Add inactive_at timestamp
            if (!Schema::hasColumn('child_accounts', 'inactive_at')) {
                $table->timestamp('inactive_at')->nullable()->after('inactive_reason')
                      ->comment('Timestamp when child account was deactivated');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('child_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('child_accounts', 'inactive_at')) {
                $table->dropColumn('inactive_at');
            }

            if (Schema::hasColumn('child_accounts', 'inactive_reason')) {
                $table->dropColumn('inactive_reason');
            }
        });
    }
};
