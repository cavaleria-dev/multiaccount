<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            // Add account_type column (main/child)
            if (!Schema::hasColumn('webhooks', 'account_type')) {
                $table->string('account_type', 10)->after('account_id')->nullable();
            }

            // Add diff_type column (for UPDATE webhooks - always 'FIELDS')
            if (!Schema::hasColumn('webhooks', 'diff_type')) {
                $table->string('diff_type', 20)->after('action')->nullable();
            }

            // Add last_triggered_at timestamp
            if (!Schema::hasColumn('webhooks', 'last_triggered_at')) {
                $table->timestamp('last_triggered_at')->nullable()->after('enabled');
            }

            // Add counters
            if (!Schema::hasColumn('webhooks', 'total_received')) {
                $table->integer('total_received')->default(0)->after('last_triggered_at');
            }

            if (!Schema::hasColumn('webhooks', 'total_failed')) {
                $table->integer('total_failed')->default(0)->after('total_received');
            }
        });

        // Rename webhook_id to moysklad_webhook_id (separate statement)
        if (Schema::hasColumn('webhooks', 'webhook_id') && !Schema::hasColumn('webhooks', 'moysklad_webhook_id')) {
            Schema::table('webhooks', function (Blueprint $table) {
                $table->renameColumn('webhook_id', 'moysklad_webhook_id');
            });
        }

        // Add unique constraint (account_id, entity_type, action)
        // Note: PostgreSQL requires checking if constraint exists before adding
        // Using raw SQL to check and add constraint conditionally
        DB::statement('
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = \'webhooks_account_entity_action_unique\'
                ) THEN
                    ALTER TABLE webhooks
                    ADD CONSTRAINT webhooks_account_entity_action_unique
                    UNIQUE (account_id, entity_type, action);
                END IF;
            END $$;
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop unique constraint first
        DB::statement('
            ALTER TABLE webhooks
            DROP CONSTRAINT IF EXISTS webhooks_account_entity_action_unique;
        ');

        // Rename moysklad_webhook_id back to webhook_id
        if (Schema::hasColumn('webhooks', 'moysklad_webhook_id')) {
            Schema::table('webhooks', function (Blueprint $table) {
                $table->renameColumn('moysklad_webhook_id', 'webhook_id');
            });
        }

        // Drop added columns
        Schema::table('webhooks', function (Blueprint $table) {
            if (Schema::hasColumn('webhooks', 'total_failed')) {
                $table->dropColumn('total_failed');
            }
            if (Schema::hasColumn('webhooks', 'total_received')) {
                $table->dropColumn('total_received');
            }
            if (Schema::hasColumn('webhooks', 'last_triggered_at')) {
                $table->dropColumn('last_triggered_at');
            }
            if (Schema::hasColumn('webhooks', 'diff_type')) {
                $table->dropColumn('diff_type');
            }
            if (Schema::hasColumn('webhooks', 'account_type')) {
                $table->dropColumn('account_type');
            }
        });
    }
};
