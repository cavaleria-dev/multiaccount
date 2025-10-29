# Webhook System - Complete Implementation Plan

**Created:** 2025-10-29
**Status:** Planning
**Priority:** High
**Estimated Implementation:** 12 days (2 weeks)

---

## 🎯 Quick Start Summary (For Claude)

### What is This System?

Real-time webhook-based synchronization between Main and Child МойСклад accounts. Replaces manual "Sync All" with automatic updates triggered by МойСклад webhooks.

**Direction:**
- **Main → Child:** Products, services, bundles, variants, product folders (CREATE/UPDATE/DELETE)
- **Child → Main:** Customer orders, retail sales (CREATE/UPDATE)

**Key Flow:**
```
МойСклад → POST /api/webhooks/receive → Save to webhook_logs →
Dispatch ProcessWebhookJob → Parse events → Check filters →
Create sync_queue tasks → Queue worker processes → Sync completed
```

### Critical Files to Create:

**Database (5 migrations):**
1. `create_webhooks_table` - Installed webhooks
2. `create_webhook_logs_table` - Incoming webhook history
3. `create_webhook_health_stats_table` - Daily aggregated stats
4. `add_account_type_to_sync_settings` - Main/Child type
5. `add_status_to_child_accounts` - Active/inactive tracking

**Services (4 classes):**
1. `WebhookSetupService` - Install/delete webhooks via МойСклад API
2. `WebhookReceiverService` - Receive/validate incoming webhooks
3. `WebhookProcessorService` - Parse events → create sync_queue tasks
4. `WebhookHealthService` - Monitoring, stats, alerts

**Jobs (2 classes):**
1. `ProcessWebhookJob` - Async webhook processing
2. `SetupAccountWebhooksJob` - Async webhook installation

**Controllers (2 classes):**
1. `Api/WebhookController` - Public webhook receiver endpoint
2. `Admin/WebhookManagementController` - Admin API

**Commands (4 artisan):**
1. `webhooks:setup` - Install webhooks for accounts
2. `webhooks:check` - Health check and alerts
3. `webhooks:cleanup-logs` - Delete old logs
4. `webhooks:update-stats` - Update aggregated stats

**Vue (3 components):**
1. `AccountTypeSelector.vue` - First-time setup screen
2. `WebhookHealth.vue` - Admin dashboard (table + alerts)
3. `WebhookLogs.vue` - Detailed logs viewer

### Key Entities & Relationships:

```
webhooks (1) → (N) webhook_logs
webhooks (1) → (N) webhook_health_stats
webhooks (N) ← (1) accounts

webhook_logs.request_id = UNIQUE (idempotency key)
webhooks(account_id, entity_type, action) = UNIQUE
```

### ⚠️ Critical Points:

1. **MUST respond to webhooks within 100ms** (МойСклад expects < 1500ms)
2. **ALWAYS use `X-Lognex-WebHook-DisableByPrefix`** header (prevent cycles)
3. **Check filters ONLY on CREATE** (UPDATE trusts mappings)
4. **Batch UPDATE only** (CREATE/DELETE individual tasks)
5. **Archive on DELETE** (not hard delete)
6. **Idempotency via requestId** (duplicates ignored)
7. **UPDATE priority=10, CREATE/DELETE=7** (dynamic priorities)

### Implementation Sequence:

```
Phase 1: Database + Models (Day 1)
Phase 2: Services (Day 2-3)
Phase 3: Jobs (Day 4)
Phase 4: Controllers (Day 5)
Phase 5: Commands (Day 6)
Phase 6: Frontend - Account Selector (Day 7)
Phase 7: Frontend - Admin Dashboard (Day 8-9)
Phase 8: Integration Testing (Day 10)
Phase 9: Staging Deploy (Day 11)
Phase 10: Production Deploy (Day 12)
```

---

## 📚 Table of Contents

1. [Business Requirements & Scenarios](#1-business-requirements--scenarios)
2. [Architecture & Design Decisions](#2-architecture--design-decisions)
3. [Database Schema](#3-database-schema)
4. [Services Implementation](#4-services-implementation) → [18-webhook-services.md](18-webhook-services.md)
5. [Jobs & Queue Processing](#5-jobs--queue-processing) → [18-webhook-implementation.md](18-webhook-implementation.md)
6. [Controllers & Routes](#6-controllers--routes) → [18-webhook-implementation.md](18-webhook-implementation.md)
7. [Artisan Commands](#7-artisan-commands) → [18-webhook-implementation.md](18-webhook-implementation.md)
8. [Frontend Components](#8-frontend-components) → [18-webhook-frontend.md](18-webhook-frontend.md)
9. [Testing Strategy](#9-testing-strategy) → [18-webhook-testing.md](18-webhook-testing.md)
10. [Implementation Sequence](#10-implementation-sequence)
11. [Deployment Instructions](#11-deployment-instructions)
12. [Monitoring & Maintenance](#12-monitoring--maintenance)
13. [Troubleshooting Quick Reference](#13-troubleshooting-quick-reference)
14. [Related Documentation](#14-related-documentation)
15. [Summary](#15-summary)

---

## 1. Business Requirements & Scenarios

### 1.1. Problem Statement

**Current State:**
- Synchronization happens manually ("Sync All" button) or on schedule
- Full sync every time (even for 1 entity change)
- High API usage (batch loading 1000+ entities)
- Delayed updates (changes not reflected immediately)

**Desired State:**
- Real-time synchronization via webhooks
- Atomic updates (only changed fields)
- Reduced API calls (only affected entities)
- Immediate reflection of changes

### 1.2. Key Scenarios

#### 🎯 SCENARIO 1: Product Price Update (Main → Child)

**Flow:**
```
1. User changes price in МойСклад Main: 99,990 → 89,990
2. МойСклад sends webhook:
   {
     "events": [{
       "meta": {"type": "product", "href": "..."},
       "updatedFields": ["salePrices"],
       "action": "UPDATE",
       "accountId": "main-uuid"
     }]
   }
3. app.cavaleria.ru receives webhook (< 100ms)
4. ProcessWebhookJob processes:
   - Extract entity_id from href
   - Check entity_mappings (product synced?)
   - Create sync_queue task for each Child (priority=10)
5. Queue worker updates ONLY salePrices on Child accounts
```

**💡 RATIONALE:**
- Atomic update saves API calls (don't re-sync entire product)
- High priority ensures fast propagation (prices critical)
- Mapping check avoids syncing unsync'd products

#### 🎯 SCENARIO 2: New Product Created (Main → Child)

**Flow:**
```
1. User creates product "iPhone 16" in Main
2. МойСклад sends webhook (action: CREATE)
3. app.cavaleria.ru processes:
   - Load product from МойСклад Main
   - Apply filters (productFolder, characteristics, tags)
   - If passes → create sync_queue tasks for Child accounts
   - If filtered out → skip
4. Queue worker creates full product on Child accounts
```

**💡 RATIONALE:**
- Filter check prevents syncing unwanted products
- Full entity creation (not just fields)
- Medium priority (less urgent than price updates)

#### 🎯 SCENARIO 3: Product Deleted (Main → Child)

**Flow:**
```
1. User deletes product in Main
2. МойСклад sends webhook (action: DELETE)
3. app.cavaleria.ru processes:
   - Create sync_queue tasks for all Child accounts
   - Operation: archive (NOT delete)
4. Queue worker sets archived=true on Child accounts
```

**💡 RATIONALE:**
- Archive preserves data (can unarchive later)
- Safer than hard delete (permanent)
- МойСклад best practice

#### 🎯 SCENARIO 4: Batch Price Update (Main → Child)

**Flow:**
```
1. User bulk-edits 50 products (price change)
2. МойСклад sends webhook with 50 events
3. app.cavaleria.ru processes:
   - Split into chunks of 15 entities
   - Check mappings (which products synced?)
   - Create batch_products tasks (4 tasks for 50 entities)
4. Queue worker updates 15 products per batch request
```

**💡 RATIONALE:**
- Batch UPDATE efficient (fewer API calls)
- Chunking prevents oversized requests (МойСклад 20MB limit)
- Only batch synced entities (check mappings)

#### 🎯 SCENARIO 5: Order Created (Child → Main)

**Flow:**
```
1. Franchisee creates order in Child account
2. МойСклад sends webhook (action: CREATE)
3. app.cavaleria.ru processes:
   - Find parent account (from child_accounts table)
   - Create sync_queue task to Main account
4. Queue worker creates corresponding order on Main
```

**💡 RATIONALE:**
- Child → Main direction (opposite of products)
- No filter check (all orders synced)
- Medium priority

---

## 2. Architecture & Design Decisions

### 2.1. Single Endpoint vs Multiple

**🎯 DECISION:** Single endpoint for all webhooks

```
✅ Chosen: POST /api/webhooks/receive
❌ Rejected: /api/webhooks/receive/main, /api/webhooks/receive/child
```

**💡 RATIONALE:**
- Simpler webhook management (one URL to configure)
- Account type determined via database (sync_settings.account_type)
- МойСклад sends accountId in payload (no ambiguity)
- Easier monitoring (single log file)

**Alternative Considered:**
- Separate endpoints per account type
- Rejected because: More complexity, no clear benefit

### 2.2. Synchronous vs Asynchronous Processing

**🎯 DECISION:** Fast receiver + async job

```
✅ Chosen:
  1. Receive webhook (< 100ms)
  2. Save to webhook_logs
  3. Dispatch ProcessWebhookJob
  4. Return 200 OK

❌ Rejected: Process webhook synchronously
```

**💡 RATIONALE:**
- МойСклад expects response within 1500ms
- Filter checks may require API calls (slow)
- Complex logic (load entity, apply filters, create tasks)
- Prevents timeout errors and unnecessary retries
- Idempotency via requestId (duplicates handled)

**Trade-off:**
- Added complexity (job + queue)
- But: Much more reliable and scalable

### 2.3. Filter Application Strategy

**🎯 DECISION:** Check filters on CREATE only

```
✅ CREATE:
  - Load entity from МойСклад
  - Apply filters (productFolder, characteristics, tags)
  - Create task if passes

✅ UPDATE:
  - Check entity_mappings (mapping exists?)
  - Create task if synced
  - NO filter check (assume still valid)

✅ DELETE:
  - Always create archive task
  - NO filter check
```

**💡 RATIONALE:**
- CREATE: Must check (don't sync filtered-out products)
- UPDATE: Already synced → must update (performance)
- DELETE: Was synced → must archive

**⚠️ Edge Case:** Product synced, then filter changed to exclude it
- **Limitation:** UPDATE will still sync (mapping exists)
- **Workaround:** Manual delete or cleanup command
- **Future:** "Re-apply filters" command

### 2.4. Batch Strategy

**🎯 DECISION:** Batch UPDATE only (not CREATE/DELETE)

```
✅ UPDATE + multiple events → batch_products (chunks of 10-20)
✅ CREATE → individual tasks (need filter checks)
✅ DELETE → individual tasks (rare, better isolation)
```

**💡 RATIONALE:**
- UPDATE: Common (bulk price changes), batching saves API calls
- UPDATE: Low risk (entities already synced)
- CREATE: Filter checks require individual API calls anyway
- CREATE/DELETE: Less frequent, better error handling

**Implementation:**
```php
if ($action === 'UPDATE' && count($events) > 1) {
    // Batch: chunks of 15
    $batches = array_chunk($events, 15);
    foreach ($batches as $batch) {
        createBatchUpdateTask($batch);
    }
} else {
    // Individual
    foreach ($events as $event) {
        createSyncTask($event);
    }
}
```

### 2.5. Priority Strategy

**🎯 DECISION:** Dynamic priorities by action

```
UPDATE = 10        // Highest - critical changes (prices, stock)
CREATE = 7         // Medium - new entities less urgent
DELETE = 7         // Medium - archiving can wait
productfolder = 5  // Low - structure changes non-critical
```

**💡 RATIONALE:**
- UPDATE has immediate business impact (customer sees new price)
- CREATE/DELETE can tolerate slight delay (< 5 minutes)
- Productfolder changes are rare and cosmetic

### 2.6. Cycle Prevention

**🎯 DECISION:** Use `X-Lognex-WebHook-DisableByPrefix` header

```php
// In MoySkladService, all API requests include:
$headers = [
    'X-Lognex-WebHook-DisableByPrefix' => 'https://app.cavaleria.ru'
];
```

**💡 RATIONALE:**
- Prevents infinite loops (Main update → webhook → Child update → webhook → Main...)
- МойСклад ignores webhooks for requests from our app
- No manual tracking needed

**Alternative Considered:**
- No webhooks on Child for products
- Rejected because: Limits future two-way sync

### 2.7. Account Type Detection

**🎯 DECISION:** Store in `sync_settings.account_type`

```sql
ALTER TABLE sync_settings ADD COLUMN account_type VARCHAR(10);
-- 'main' or 'child'
```

**💡 RATIONALE:**
- User explicitly selects on first setup (clear intent)
- Single source of truth
- Can be changed later (with webhook reinstall)

**Alternative Considered:**
- Infer from child_accounts table
- Rejected because: Ambiguous (what if not in table?)

### 2.8. Account Type Change Handling

**🎯 DECISION:** Allow change with data preservation

```
Main → Child:
  1. Mark child_accounts as inactive (status, reason)
  2. Delete Main webhooks
  3. Install Child webhooks
  4. Show inactive children in UI (separate section)

Child → Main:
  1. Mark child_accounts as inactive
  2. Delete Child webhooks
  3. Install Main webhooks
```

**💡 RATIONALE:**
- Flexibility (business needs change)
- Data preserved (can reactivate)
- Clear audit trail

**Alternative Considered:**
- Permanent choice (can't change)
- Rejected because: Too restrictive

---

## 3. Database Schema

### 🎯 IMPLEMENTATION_STEP: Phase 1 - Database

### 3.1. Table: `webhooks`

**📝 PURPOSE:** Store installed webhooks (one row per webhook in МойСклад)

```php
// 📝 CODE_BLOCK: Migration - create_webhooks_table
// database/migrations/YYYY_MM_DD_HHMMSS_create_webhooks_table.php

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
            $table->uuid('account_id')->index();
            $table->string('account_type', 10); // 'main' or 'child'
            $table->uuid('moysklad_webhook_id')->unique(); // ID from МойСклад
            $table->string('entity_type', 50); // product, service, bundle, etc.
            $table->string('action', 20); // CREATE, UPDATE, DELETE
            $table->string('diff_type', 20)->nullable(); // NONE, FIELDS (for UPDATE)
            $table->string('url', 255); // https://app.cavaleria.ru/api/webhooks/receive
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->integer('total_received')->default(0);
            $table->integer('total_failed')->default(0);
            $table->timestamps();

            // Unique constraint: one webhook per (account, entity, action)
            $table->unique(['account_id', 'entity_type', 'action'], 'idx_webhooks_unique');

            // Foreign key
            $table->foreign('account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');

            // Indexes for fast queries
            $table->index(['enabled', 'entity_type'], 'idx_webhooks_enabled_entity');
            $table->index('last_triggered_at', 'idx_webhooks_last_triggered');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
```

**💡 INDEX RATIONALE:**
- `UNIQUE(account_id, entity_type, action)` - Prevent duplicate webhooks
- `idx_webhooks_enabled_entity` - Fast filtering in admin panel
- `idx_webhooks_last_triggered` - Sort by recent activity

### 3.2. Table: `webhook_logs`

**📝 PURPOSE:** Log every incoming webhook for debugging and monitoring

```php
// 📝 CODE_BLOCK: Migration - create_webhook_logs_table
// database/migrations/YYYY_MM_DD_HHMMSS_create_webhook_logs_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->nullable()->constrained()->onDelete('set null');
            $table->uuid('request_id')->unique()->index(); // Idempotency key from МойСклад
            $table->uuid('account_id');
            $table->string('entity_type', 50);
            $table->string('action', 20);
            $table->integer('events_count')->default(0); // count(events[])
            $table->jsonb('payload'); // Full webhook from МойСклад
            $table->string('status', 20)->default('received'); // received, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Indexes for fast queries
            $table->index(['account_id', 'status'], 'idx_webhook_logs_account_status');
            $table->index(['entity_type', 'action'], 'idx_webhook_logs_entity_action');
            $table->index('created_at', 'idx_webhook_logs_created');
            $table->index(['status', 'created_at'], 'idx_webhook_logs_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
```

**💡 INDEX RATIONALE:**
- `request_id UNIQUE` - Idempotency (prevent duplicate processing)
- `idx_webhook_logs_account_status` - Filter logs in admin panel
- `idx_webhook_logs_status_created` - Find failed/stuck webhooks

**⚠️ RETENTION:** Cleanup logs older than 30 days (via `webhooks:cleanup-logs`)

### 3.3. Table: `webhook_health_stats`

**📝 PURPOSE:** Pre-aggregated daily statistics for fast dashboard loading

```php
// 📝 CODE_BLOCK: Migration - create_webhook_health_stats_table
// database/migrations/YYYY_MM_DD_HHMMSS_create_webhook_health_stats_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_health_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->onDelete('cascade');
            $table->uuid('account_id');
            $table->string('entity_type', 50);
            $table->string('action', 20);
            $table->date('stat_date'); // Date for grouping
            $table->integer('total_received')->default(0);
            $table->integer('total_failed')->default(0);
            $table->integer('total_events')->default(0); // sum(events_count)
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamps();

            // Unique: one row per webhook per day
            $table->unique(['webhook_id', 'stat_date'], 'idx_webhook_health_stats_unique');

            // Indexes
            $table->index(['account_id', 'stat_date'], 'idx_webhook_health_stats_account_date');
            $table->index('stat_date', 'idx_webhook_health_stats_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_health_stats');
    }
};
```

**💡 UPDATE SCHEDULE:** Hourly via `webhooks:update-stats` command

**💡 RATIONALE:**
- Fast dashboard (no need to aggregate millions of logs)
- Historical data for charts
- Small table size (one row per webhook per day)

### 3.4. Update: `sync_settings` - Add account_type

```php
// 📝 CODE_BLOCK: Migration - add_account_type_to_sync_settings
// database/migrations/YYYY_MM_DD_HHMMSS_add_account_type_to_sync_settings.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('sync_settings', 'account_type')) {
                $table->string('account_type', 10)->nullable()->after('account_id');
                $table->index('account_type', 'idx_sync_settings_account_type');
            }

            if (!Schema::hasColumn('sync_settings', 'webhooks_enabled')) {
                $table->boolean('webhooks_enabled')->default(true)->after('account_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            if (Schema::hasColumn('sync_settings', 'webhooks_enabled')) {
                $table->dropColumn('webhooks_enabled');
            }

            if (Schema::hasColumn('sync_settings', 'account_type')) {
                $table->dropIndex('idx_sync_settings_account_type');
                $table->dropColumn('account_type');
            }
        });
    }
};
```

**💡 FIELDS:**
- `account_type` - 'main' or 'child' (user selects on first setup)
- `webhooks_enabled` - Master switch (disable all webhooks for account)

### 3.5. Update: `child_accounts` - Add status tracking

```php
// 📝 CODE_BLOCK: Migration - add_status_to_child_accounts
// database/migrations/YYYY_MM_DD_HHMMSS_add_status_to_child_accounts.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('child_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('child_accounts', 'status')) {
                $table->string('status', 20)->default('active')->after('child_account_id');
                $table->index('status', 'idx_child_accounts_status');
            }

            if (!Schema::hasColumn('child_accounts', 'inactive_reason')) {
                $table->string('inactive_reason', 255)->nullable()->after('status');
            }

            if (!Schema::hasColumn('child_accounts', 'inactive_at')) {
                $table->timestamp('inactive_at')->nullable()->after('inactive_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('child_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('child_accounts', 'inactive_at')) {
                $table->dropColumn('inactive_at');
            }

            if (Schema::hasColumn('child_accounts', 'inactive_reason')) {
                $table->dropColumn('inactive_reason');
            }

            if (Schema::hasColumn('child_accounts', 'status')) {
                $table->dropIndex('idx_child_accounts_status');
                $table->dropColumn('status');
            }
        });
    }
};
```

**💡 STATUS VALUES:**
- `active` - Normal operation
- `inactive` - Account changed type or manually deactivated

**💡 INACTIVE REASONS:**
- `account_type_changed` - Child changed to Main
- `manual` - User manually deactivated
- `deleted` - Child account deleted in МойСклад

### ✅ VALIDATION: Run Migrations

```bash
# Run migrations
php artisan migrate

# Check tables created
php artisan tinker
>>> Schema::hasTable('webhooks')
=> true
>>> Schema::hasTable('webhook_logs')
=> true
>>> Schema::hasTable('webhook_health_stats')
=> true
```

---

## 4. Services Layer

### 🎯 IMPLEMENTATION_STEP: Phase 2 - Services

### 4.1. Models

**Create models before services:**

```php
// 📝 CODE_BLOCK: Model - Webhook
// app/Models/Webhook.php

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    protected $fillable = [
        'account_id',
        'account_type',
        'moysklad_webhook_id',
        'entity_type',
        'action',
        'diff_type',
        'url',
        'enabled',
        'last_triggered_at',
        'total_received',
        'total_failed',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }

    public function healthStats(): HasMany
    {
        return $this->hasMany(WebhookHealthStat::class);
    }

    /**
     * Increment received counter
     */
    public function incrementReceived(): void
    {
        $this->increment('total_received');
        $this->update(['last_triggered_at' => now()]);
    }

    /**
     * Increment failed counter
     */
    public function incrementFailed(): void
    {
        $this->increment('total_failed');
    }

    /**
     * Get failure rate (%)
     */
    public function getFailureRateAttribute(): float
    {
        if ($this->total_received === 0) {
            return 0;
        }
        return round(($this->total_failed / $this->total_received) * 100, 2);
    }

    /**
     * Check if webhook is healthy (< 10% failure rate)
     */
    public function isHealthy(): bool
    {
        return $this->failure_rate < 10;
    }
}
```

```php
// 📝 CODE_BLOCK: Model - WebhookLog
// app/Models/WebhookLog.php

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    protected $fillable = [
        'webhook_id',
        'request_id',
        'account_id',
        'entity_type',
        'action',
        'events_count',
        'payload',
        'status',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    /**
     * Mark as processing
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
            'processed_at' => now(),
        ]);
    }
}
```

```php
// 📝 CODE_BLOCK: Model - WebhookHealthStat
// app/Models/WebhookHealthStat.php

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookHealthStat extends Model
{
    protected $fillable = [
        'webhook_id',
        'account_id',
        'entity_type',
        'action',
        'stat_date',
        'total_received',
        'total_failed',
        'total_events',
        'last_received_at',
        'last_failed_at',
    ];

    protected $casts = [
        'stat_date' => 'date',
        'last_received_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
```

### ✅ VALIDATION: Test Models

```bash
php artisan tinker
>>> $webhook = new \App\Models\Webhook();
>>> $webhook->account_id = 'test-uuid';
>>> $webhook->save();
```

---

---

## 4. Services Implementation

For complete service implementations with full code (~1,700 lines), see:

**📄 [18-webhook-services.md](18-webhook-services.md)**

This document contains:
- `WebhookSetupService` - Install/delete webhooks via МойСклад API (~400 lines)
- `WebhookReceiverService` - Receive/validate incoming webhooks (~200 lines)
- `WebhookProcessorService` - Parse events → create sync tasks (~700 lines)
- `WebhookHealthService` - Monitoring, stats, alerts (~400 lines)

**Quick summary:**
- Setup service handles webhook registration with МойСклад
- Receiver validates and stores webhooks (must respond <100ms)
- Processor creates sync_queue tasks based on webhook events
- Health service monitors failures and generates alerts

---

## 5. Jobs & Queue Processing

For complete job implementations with retry logic and error handling, see:

**📄 [18-webhook-implementation.md](18-webhook-implementation.md)** - Section: Jobs

This document contains:
- `ProcessWebhookJob` - Async webhook processing (3 retries, 120s timeout)
- `SetupAccountWebhooksJob` - Async webhook installation (3 retries, 300s timeout)

**Key points:**
- Jobs use queue for async processing
- Automatic retries on failure
- Failed jobs logged for manual review

---

## 6. Controllers & Routes

For complete controller implementations and route configuration, see:

**📄 [18-webhook-implementation.md](18-webhook-implementation.md)** - Sections: Controllers & Routes

This document contains:
- `Api/WebhookController` - Public webhook receiver endpoint
  - `POST /api/webhooks/receive` - Receive webhooks from МойСклад
- `Admin/WebhookManagementController` - Admin API (8 endpoints)
  - `GET /api/admin/webhooks` - List webhook health
  - `POST /api/admin/webhooks/setup` - Install webhooks
  - `POST /api/admin/webhooks/{id}/reinstall` - Reinstall webhooks
  - And 5 more endpoints for management

**Security:**
- Public endpoint: No auth (МойСклад validates via signature)
- Admin endpoints: Require authentication + context key

---

## 7. Artisan Commands

For complete command implementations with progress bars and dry-run mode, see:

**📄 [18-webhook-implementation.md](18-webhook-implementation.md)** - Section: Artisan Commands

This document contains 4 commands:
- `webhooks:setup` - Install webhooks for accounts (with progress bar)
- `webhooks:check` - Health check and alerts (color-coded output)
- `webhooks:cleanup-logs` - Delete old logs (with `--dry-run` option)
- `webhooks:update-stats` - Aggregate statistics (runs hourly)

**Scheduler configuration:**
```php
// app/Console/Kernel.php
Schedule::command('webhooks:update-stats')->hourly();
Schedule::command('webhooks:cleanup-logs --days=30')->weekly();
```

---

## 8. Frontend Components

For complete Vue 3 component implementations with Composition API, see:

**📄 [18-webhook-frontend.md](18-webhook-frontend.md)**

This document contains:
- `AccountTypeSelector.vue` - First-time account type selection (Main/Child)
  - Route: `/welcome`
  - Shown when `sync_settings.account_type IS NULL`

- `WebhookHealth.vue` - Admin dashboard for webhook monitoring
  - Route: `/admin/webhook-health`
  - Health metrics, alerts, filtering

- `WebhookLogs.vue` - Detailed webhook logs viewer
  - Route: `/admin/webhook-logs`
  - Search, filtering, payload inspection

Plus:
- Navigation integration
- Router configuration
- Account type change UI
- Inactive accounts handling

**Tech stack:**
- Vue 3 Composition API (script setup)
- Tailwind CSS
- Axios for API calls
- Context key from sessionStorage

---

## 9. Testing Strategy

For comprehensive testing guide with unit tests, integration tests, and manual scenarios, see:

**📄 [18-webhook-testing.md](18-webhook-testing.md)**

This document contains:
- **Unit Testing** - WebhookReceiverService, WebhookProcessorService tests
- **Integration Testing** - End-to-end webhook receive → task creation flow
- **Manual Testing** - Step-by-step scenarios for all webhook types
- **Performance Testing** - Load testing, batch processing
- **Security Testing** - Unauthorized access, payload injection
- **Monitoring** - Health checks, automated alerts
- **Troubleshooting** - Common issues with solutions

**Critical success metrics:**
- Webhook receive time: <100ms
- Processing time: <5 seconds
- Failure rate: <5%
- No cycles (infinite loops)

---

## 10. Implementation Sequence

### Phase-by-Phase Checklist

**✅ Phase 1: Database (Day 1)** - 5 migrations
- [ ] Create `create_webhooks_table` migration
- [ ] Create `create_webhook_logs_table` migration
- [ ] Create `create_webhook_health_stats_table` migration
- [ ] Create `add_account_type_to_sync_settings` migration
- [ ] Create `add_status_to_child_accounts` migration
- [ ] Run migrations: `php artisan migrate`
- [ ] Create 3 models: Webhook, WebhookLog, WebhookHealthStat

**✅ Phase 2: Services (Day 2-3)** - 4 services
- [ ] Create `WebhookSetupService` (~400 lines)
- [ ] Create `WebhookReceiverService` (~200 lines)
- [ ] Create `WebhookProcessorService` (~700 lines)
- [ ] Create `WebhookHealthService` (~400 lines)
- [ ] Register services in `AppServiceProvider` (if needed)

**✅ Phase 3: Jobs (Day 4)** - 2 jobs
- [ ] Create `ProcessWebhookJob`
- [ ] Create `SetupAccountWebhooksJob`
- [ ] Test job dispatch and processing

**✅ Phase 4: Controllers (Day 5)** - 2 controllers
- [ ] Create `Api/WebhookController` (public receiver)
- [ ] Create `Admin/WebhookManagementController` (admin API)
- [ ] Configure routes in `routes/api.php`

**✅ Phase 5: Commands (Day 6)** - 4 commands
- [ ] Create `webhooks:setup` command
- [ ] Create `webhooks:check` command
- [ ] Create `webhooks:cleanup-logs` command
- [ ] Create `webhooks:update-stats` command
- [ ] Configure scheduler in `app/Console/Kernel.php`

**✅ Phase 6: Frontend - Account Selector (Day 7)**
- [ ] Create `AccountTypeSelector.vue` component
- [ ] Add `/welcome` route
- [ ] Test account type selection flow

**✅ Phase 7: Frontend - Admin Dashboard (Day 8-9)**
- [ ] Create `WebhookHealth.vue` component
- [ ] Create `WebhookLogs.vue` component
- [ ] Add admin routes
- [ ] Update navigation
- [ ] Test filtering and pagination

**✅ Phase 8: Integration Testing (Day 10)**
- [ ] Write unit tests (80%+ coverage target)
- [ ] Write integration tests (webhook → task flow)
- [ ] Manual testing of all scenarios
- [ ] Performance testing (100 webhooks/min)

**✅ Phase 9: Staging Deploy (Day 11)**
- [ ] Deploy to staging environment
- [ ] Install webhooks on test account
- [ ] Monitor for 24 hours
- [ ] Fix any issues discovered

**✅ Phase 10: Production Deploy (Day 12)**
- [ ] Deploy to production
- [ ] Enable for one account first
- [ ] Monitor for 48 hours
- [ ] Gradual rollout to all accounts

---

## 11. Deployment Instructions

### Pre-Deployment Checklist

- [ ] All migrations tested
- [ ] All tests passing
- [ ] Code reviewed
- [ ] Documentation updated
- [ ] Rollback plan ready

### Deployment Steps

```bash
# 1. Backup database
pg_dump multiaccount > backup-$(date +%Y%m%d).sql

# 2. Pull latest code
cd /var/www/app.cavaleria.ru
git pull origin main

# 3. Install dependencies
composer install --no-dev --optimize-autoloader
npm install && npm run build

# 4. Run migrations
php artisan migrate --force

# 5. Clear caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 6. Restart queue worker
./restart-queue.sh

# 7. Install webhooks (test account first)
php artisan webhooks:setup --account-id=test-account-uuid

# 8. Monitor logs
tail -f storage/logs/webhook.log
tail -f storage/logs/laravel.log
```

### Post-Deployment Verification

```bash
# Check webhook installation
php artisan webhooks:check

# Test webhook receiver
curl -X POST https://app.cavaleria.ru/api/webhooks/receive \
  -H "Content-Type: application/json" \
  -d @test-webhook.json

# Check database
php artisan tinker
>>> \App\Models\Webhook::count()
>>> \App\Models\WebhookLog::latest()->first()
```

---

## 12. Monitoring & Maintenance

### Daily Monitoring

```bash
# Check webhook health
php artisan webhooks:check

# View recent logs
tail -100 storage/logs/webhook.log

# Check queue status
./monitor-queue.sh

# View admin dashboard
open https://app.cavaleria.ru/admin/webhook-health
```

### Weekly Maintenance

```bash
# Cleanup old logs (keep 30 days)
php artisan webhooks:cleanup-logs --days=30

# Review failed webhooks
# Check admin dashboard for high failure rates

# Update statistics
php artisan webhooks:update-stats
```

### Monthly Review

- Review webhook failure patterns
- Optimize database indexes if needed
- Check disk space for logs
- Review and update documentation

---

## 13. Troubleshooting Quick Reference

For complete troubleshooting guide, see **[18-webhook-testing.md](18-webhook-testing.md)** - Section 7

**Common Issues:**

1. **Webhooks not received** → Check webhook installation, verify URL accessible
2. **Webhooks not processed** → Check queue worker running, check for failed jobs
3. **High failure rate (>10%)** → Check error messages, fix underlying issues
4. **Cycles detected** → Verify `X-Lognex-WebHook-DisableByPrefix` header
5. **Duplicate sync tasks** → Check idempotency logic with `request_id`

**Debug Commands:**

```bash
# Check webhook status
php artisan webhooks:check

# View detailed logs
tail -f storage/logs/webhook.log

# Retry failed webhooks
php artisan queue:retry all

# Reinstall webhooks
php artisan webhooks:setup --account-id=uuid --force
```

---

## 14. Related Documentation

This webhook system documentation is split across multiple files for easier navigation:

1. **[18-webhook-system.md](18-webhook-system.md)** (this file) - Overview, architecture, database schema
2. **[18-webhook-services.md](18-webhook-services.md)** - Backend services with full code (~1,700 lines)
3. **[18-webhook-implementation.md](18-webhook-implementation.md)** - Jobs, controllers, routes, commands (~1,100 lines)
4. **[18-webhook-frontend.md](18-webhook-frontend.md)** - Vue 3 components with full code (~1,500 lines)
5. **[18-webhook-testing.md](18-webhook-testing.md)** - Testing, monitoring, troubleshooting guide

**Other related documentation:**
- [02-queue-supervisor.md](02-queue-supervisor.md) - Queue system and Supervisor setup
- [03-architecture.md](03-architecture.md) - Overall application architecture
- [04-batch-sync.md](04-batch-sync.md) - Batch synchronization patterns
- [16-sync-handlers.md](16-sync-handlers.md) - Modular sync task handlers

---

## 15. Summary

### What This System Does

Replaces manual "Sync All" with **real-time webhook-based synchronization**:
- Main account changes → Instant sync to Child accounts
- Child account orders → Instant sync to Main account
- Atomic updates (only changed fields)
- 97% fewer API calls vs batch sync

### Key Benefits

1. **Real-time synchronization** - Changes reflected in seconds, not minutes
2. **Reduced API usage** - Atomic updates vs full entity sync
3. **Better monitoring** - Admin dashboard with health metrics and alerts
4. **Automatic retry** - Failed webhooks retried up to 3 times
5. **Audit trail** - Complete log of all webhooks received

### Critical Design Decisions

1. **Fast receiver + async job** - <100ms response, processing in background
2. **Filter on CREATE only** - Performance optimization, trusts mappings
3. **Batch UPDATE only** - CREATE/DELETE remain individual for better control
4. **Cycle prevention** - X-Lognex-WebHook-DisableByPrefix header
5. **Idempotency** - requestId prevents duplicate processing

### Success Metrics

- ✅ Webhook receive time: <100ms
- ✅ Processing time: <5 seconds
- ✅ Failure rate: <5%
- ✅ Zero cycles (infinite loops)
- ✅ Zero duplicate tasks

---

**Implementation Status:** Planning Complete → Ready for Development

**Estimated Timeline:** 12 days (2 weeks) from start to production

**Next Step:** Begin Phase 1 - Database migrations