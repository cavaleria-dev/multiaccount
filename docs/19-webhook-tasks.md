# Webhook System - Day-by-Day Implementation Tasks

**Detailed task breakdown** - 14-15 days from 12-15% to 100% completion (+ Day 0 critical fixes)

**See also:**
- [19-webhook-roadmap.md](19-webhook-roadmap.md) - High-level overview & timeline
- [19-webhook-migration.md](19-webhook-migration.md) - Migration from existing code
- [18-webhook-system.md](18-webhook-system.md) - Architecture reference
- [18-webhook-services.md](18-webhook-services.md) - Service implementation details
- [18-webhook-testing.md](18-webhook-testing.md) - Testing strategies

---

## Quick Reference

### ⚠️ Day 0: Critical Fixes (MANDATORY BEFORE DAY 1)
- **Task 0.1:** Add Cycle Prevention Header (5 min) 🔴 CRITICAL
- **Task 0.2:** Disable Broken Webhooks (10 min) 🔴 CRITICAL
- **Task 0.3:** Database Backup (15 min) 🔴 CRITICAL
- **Total:** 1-2 hours

### Week 1: Backend Core (Days 1-7)
- **Day 1:** Database Migrations (5 migrations)
- **Day 2:** Models (3 models)
- **Day 3:** Core Services Part 1 (Receiver + Setup)
- **Day 4:** Core Services Part 2 (Processor + Health)
- **Day 5:** Jobs (2 async jobs)
- **Day 6:** Controllers & Routes (2 controllers)
- **Day 7:** Artisan Commands (4 commands)

### Week 2: Frontend & Testing (Days 8-10)
- **Day 8:** Vue Components Part 1 (AccountTypeSelector + WebhookHealth)
- **Day 9:** Vue Components Part 2 (WebhookLogs + Navigation + Router)
- **Day 10:** Testing (Unit + Integration + Manual)

### Week 3: Deployment (Days 11-14)
- **Day 11-12:** Staging Deployment & Validation
- **Day 13-14:** Production Rollout & Monitoring

---

## ⚠️ DAY 0: CRITICAL FIXES (MANDATORY BEFORE DAY 1)

**Goal:** Fix critical issues in existing code BEFORE starting implementation

**⚠️ WHY THIS IS CRITICAL:**
Code review revealed that existing code has 4 critical problems:
1. WebhookController parses payload INCORRECTLY → всегда returns error 400
2. Cycle prevention header MISSING → infinite loops in production
3. Synchronous processing → blocks response, causes timeouts
4. No idempotency → duplicate webhooks create duplicate tasks

**Prerequisites:**
- [ ] Read [19-webhook-roadmap.md](19-webhook-roadmap.md) section "🚨 КРИТИЧЕСКИЕ НАХОДКИ В КОДЕ"
- [ ] Understand why each fix is critical
- [ ] Have SSH access to production server
- [ ] Have МойСклад admin access

**Estimated Time:** 1-2 hours

---

### Task 0.1: Add Cycle Prevention Header 🔴 CRITICAL

**File:** `app/Services/MoySkladService.php` (line ~170)

**Status:** ❌ MISSING

**Priority:** 🔴 CRITICAL - WITHOUT THIS, INFINITE LOOPS WILL OCCUR!

**Problem:**
Без этого header:
- Main updates product → webhook → Child syncs
- Child sync triggers webhook back to Main (no DisableByPrefix!)
- Main sees "update" → webhook → Child syncs again
- **INFINITE LOOP ♾️** → API overload → system crash

**Steps:**

1. **SSH to server** (no local PHP!):
   ```bash
   ssh your-server
   cd /var/www/multiaccount
   ```

2. **Open file** (line ~170):
   ```bash
   nano app/Services/MoySkladService.php
   # Or use vim: vim +170 app/Services/MoySkladService.php
   ```

3. **Find this code** (around line 170):
   ```php
   $headers = [
       'Authorization' => 'Bearer ' . $this->accessToken,
       'Accept-Encoding' => 'gzip',
       'Content-Type' => 'application/json',
   ];
   ```

4. **Add header**:
   ```php
   $headers = [
       'Authorization' => 'Bearer ' . $this->accessToken,
       'Accept-Encoding' => 'gzip',
       'Content-Type' => 'application/json',
       'X-Lognex-WebHook-DisableByPrefix' => config('app.url'), // ⚠️ CRITICAL: Prevent webhook cycles
   ];
   ```

5. **Save file**:
   - Nano: `Ctrl+O`, `Enter`, `Ctrl+X`
   - Vim: `:wq`

**Validation:**
```bash
# Check header added
grep "X-Lognex-WebHook-DisableByPrefix" app/Services/MoySkladService.php

# Should output:
# 'X-Lognex-WebHook-DisableByPrefix' => config('app.url'),
```

**Commit:**
```bash
git add app/Services/MoySkladService.php
git commit -m "fix: Add X-Lognex-WebHook-DisableByPrefix header to prevent webhook cycles

CRITICAL: Without this header, webhook loops will occur:
- Main → webhook → Child → webhook → Main → infinite loop

This header tells МойСклад to NOT send webhooks for changes
made by our application, breaking the cycle."
```

**⏱️ Estimated Time:** 5 minutes

---

### Task 0.2: Disable Broken Webhooks 🔴 CRITICAL

**Status:** Current webhooks are BROKEN (controller парсит payload incorrectly)

**Priority:** 🔴 CRITICAL - Prevent broken webhooks from creating bad data

**Problem:**
Current WebhookController.php:32-33:
```php
$action = $payload['action'] ?? null;        // ❌ Всегда NULL!
$entityType = $payload['entityType'] ?? null; // ❌ Всегда NULL!
```

МойСклад НЕ отправляет action/entityType на верхнем уровне!
→ Webhooks всегда fail с error 400
→ Или создают некорректные задачи

**Steps:**

**Option A: Via МойСклад UI** (рекомендуется для малого количества аккаунтов)

1. Login to Main МойСклад account
2. Настройки → Приложения → Вебхуки
3. Find webhooks for "app.cavaleria.ru"
4. Delete ALL webhooks (нажать "Удалить")
5. Repeat for all Child accounts (если есть webhooks)

**Option B: Via API** (быстрее для многих аккаунтов)

```bash
# SSH to server
ssh your-server
cd /var/www/multiaccount

# Run cleanup
php artisan tinker

# In tinker (copy-paste this):
$service = app(\App\Services\WebhookService::class);
$accounts = \App\Models\Account::all();
$deleted = 0;
foreach ($accounts as $account) {
    try {
        $service->cleanupOldWebhooks($account->account_id);
        $deleted++;
        echo "✓ Cleaned: {$account->account_id}\n";
    } catch (\Exception $e) {
        echo "✗ Failed: {$account->account_id} - {$e->getMessage()}\n";
    }
}
echo "\nTotal cleaned: {$deleted} accounts\n";
exit
```

**Validation:**

**Option A: Check database**
```bash
# On server
php artisan tinker

# In tinker:
$count = \App\Models\WebhookHealth::count();
echo "Webhooks in DB: {$count}\n";
// Should be 0

exit
```

**Option B: Check МойСклад UI**
- Login to МойСклад
- Настройки → Приложения → Вебхуки
- Should see "Нет активных вебхуков"

**⏱️ Estimated Time:** 10 minutes

---

### Task 0.3: Database Backup 🔴 MANDATORY

**Status:** REQUIRED before ANY database changes

**Priority:** 🔴 CRITICAL - Rollback protection

**Steps:**

1. **SSH to server**:
   ```bash
   ssh your-server
   cd /var/www/multiaccount
   ```

2. **Create backup**:
   ```bash
   # Create backup with timestamp
   sudo -u postgres pg_dump multiaccount > backup_pre_webhook_$(date +%Y%m%d_%H%M%S).sql

   # Alternative (if Laravel command available):
   php artisan db:dump --database=pgsql
   ```

3. **Verify backup**:
   ```bash
   # Check backup size (should be > 0 bytes)
   ls -lh backup_pre_webhook_*.sql

   # Example output:
   # -rw-r--r-- 1 postgres postgres 15M Oct 29 10:30 backup_pre_webhook_20251029_103000.sql
   ```

4. **Check backup is valid** (optional but recommended):
   ```bash
   # Show first 20 lines
   head -20 backup_pre_webhook_*.sql

   # Should see PostgreSQL dump header:
   # --
   # -- PostgreSQL database dump
   # --
   ```

5. **Store backup safely** (optional but recommended):
   ```bash
   # Copy to backup directory
   sudo mkdir -p /var/backups/multiaccount
   sudo cp backup_pre_webhook_*.sql /var/backups/multiaccount/

   # Verify copied
   ls -lh /var/backups/multiaccount/
   ```

**Validation:**
```bash
# Check backup exists and has reasonable size
ls -lh backup_pre_webhook_*.sql

# Size should be:
# - Small DB: ~1-5 MB
# - Medium DB: ~5-50 MB
# - Large DB: ~50-500 MB

# If size is 0 bytes or missing → BACKUP FAILED, DO NOT PROCEED!
```

**⏱️ Estimated Time:** 15 minutes

---

### Day 0 Validation Checklist

**BEFORE starting Day 1, verify ALL tasks completed:**

- [ ] ✅ Task 0.1: Cycle prevention header added to MoySkladService.php
- [ ] ✅ Task 0.2: All broken webhooks disabled/deleted
- [ ] ✅ Task 0.3: Database backup created and verified (size > 0)
- [ ] ✅ Feature branch created: `git checkout -b feature/webhook-system-complete`
- [ ] ✅ Read [19-webhook-roadmap.md](19-webhook-roadmap.md) fully
- [ ] ✅ Understand critical issues in existing code
- [ ] ✅ Commit cycle prevention header fix

**Validation commands:**
```bash
# 1. Check header exists
grep "X-Lognex-WebHook-DisableByPrefix" app/Services/MoySkladService.php
# Should output the header line

# 2. Check no webhooks active
php artisan tinker --execute="echo \App\Models\WebhookHealth::count();"
# Should output: 0

# 3. Check backup exists
ls -lh backup_pre_webhook_*.sql
# Should show file with size > 0

# 4. Check git branch
git branch
# Should show: * feature/webhook-system-complete
```

**If ANY checkbox is unchecked → DO NOT START DAY 1!**

**⏱️ Total Time:** 1-2 hours

---

## WEEK 1: Backend Core

---

## Day 1: Database Migrations ⚠️ CRITICAL

**Goal:** Create all database tables and update existing tables with missing columns

**Prerequisites:**
- [ ] Backup current database on server:
  ```bash
  # SSH to server first
  ssh your-server
  cd /var/www/multiaccount

  # Create backup (choose one method):
  # Option A: Using postgres user
  sudo -u postgres pg_dump multiaccount > backup_$(date +%Y%m%d).sql

  # Option B: Using Laravel
  php artisan db:dump --database=pgsql
  ```
- [ ] Create feature branch: `git checkout -b feature/webhook-system-complete`
- [ ] Confirm migrations can be rolled back

**Estimated Time:** 3-4 hours

---

### Task 1.1: Update webhooks table

**File:** `database/migrations/2025_10_29_000001_update_webhooks_table.php`

**Purpose:** Add missing columns to existing webhooks table

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            // Check if columns exist before adding
            if (!Schema::hasColumn('webhooks', 'account_type')) {
                $table->string('account_type', 10)->after('account_id')->index();
            }

            if (!Schema::hasColumn('webhooks', 'diff_type')) {
                $table->string('diff_type', 20)->nullable()->after('action');
            }

            if (!Schema::hasColumn('webhooks', 'last_triggered_at')) {
                $table->timestamp('last_triggered_at')->nullable()->after('enabled');
            }

            if (!Schema::hasColumn('webhooks', 'total_received')) {
                $table->integer('total_received')->default(0)->after('last_triggered_at');
            }

            if (!Schema::hasColumn('webhooks', 'total_failed')) {
                $table->integer('total_failed')->default(0)->after('total_received');
            }

            // Rename webhook_id to moysklad_webhook_id if exists
            if (Schema::hasColumn('webhooks', 'webhook_id') && !Schema::hasColumn('webhooks', 'moysklad_webhook_id')) {
                $table->renameColumn('webhook_id', 'moysklad_webhook_id');
            }
        });

        // Add unique constraint (only if it doesn't exist)
        if (!$this->hasUniqueConstraint('webhooks', ['account_id', 'entity_type', 'action'])) {
            Schema::table('webhooks', function (Blueprint $table) {
                $table->unique(['account_id', 'entity_type', 'action'], 'webhooks_account_entity_action_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            // Drop unique constraint first
            $table->dropUnique('webhooks_account_entity_action_unique');

            // Drop added columns
            $table->dropColumn([
                'account_type',
                'diff_type',
                'last_triggered_at',
                'total_received',
                'total_failed'
            ]);

            // Rename back (optional - only if you want full rollback)
            if (Schema::hasColumn('webhooks', 'moysklad_webhook_id')) {
                $table->renameColumn('moysklad_webhook_id', 'webhook_id');
            }
        });
    }

    private function hasUniqueConstraint(string $table, array $columns): bool
    {
        $indexes = Schema::getConnection()
            ->getDoctrineSchemaManager()
            ->listTableIndexes($table);

        foreach ($indexes as $index) {
            if ($index->isUnique() && $index->getColumns() === $columns) {
                return true;
            }
        }

        return false;
    }
};
```

**Validation:**
- [ ] `php artisan migrate --pretend` shows correct SQL
- [ ] `php artisan migrate` succeeds
- [ ] Check table structure: `\d+ webhooks` in psql
- [ ] Verify unique constraint: `\d+ webhooks` shows index

---

### Task 1.2: Create webhook_logs table

**File:** `database/migrations/2025_10_29_000002_create_webhook_logs_table.php`

**Purpose:** Store all incoming webhook requests for debugging and monitoring

```php
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
            $table->uuid('account_id');
            $table->string('request_id', 255)->unique(); // Idempotency key
            $table->string('webhook_id', 255)->nullable(); // X-Lognex-WebHook-Id header
            $table->string('entity_type', 50);
            $table->string('action', 20); // CREATE, UPDATE, DELETE
            $table->json('payload'); // Full webhook payload
            $table->integer('events_count')->default(0); // Number of events in webhook
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed
            $table->integer('tasks_created')->default(0); // Number of sync tasks created
            $table->text('error_message')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('account_id');
            $table->index('status');
            $table->index('entity_type');
            $table->index('action');
            $table->index('received_at');
            $table->index(['account_id', 'status', 'received_at'], 'idx_webhook_logs_account_status_received');

            // Foreign key
            $table->foreign('account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
```

**Validation:**
- [ ] Migration runs successfully
- [ ] All indexes created
- [ ] Foreign key constraint works

---

### Task 1.3: Create webhook_health_stats table

**File:** `database/migrations/2025_10_29_000003_create_webhook_health_stats_table.php`

**Purpose:** Store aggregated webhook health statistics for monitoring

```php
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
            $table->uuid('account_id');
            $table->string('entity_type', 50);
            $table->string('action', 20);
            $table->date('stat_date'); // Daily aggregation
            $table->integer('total_received')->default(0);
            $table->integer('total_processed')->default(0);
            $table->integer('total_failed')->default(0);
            $table->decimal('failure_rate', 5, 2)->default(0); // Percentage
            $table->decimal('avg_processing_time', 8, 2)->nullable(); // Seconds
            $table->timestamps();

            // Unique constraint (one stat per account/entity/action/date)
            $table->unique(['account_id', 'entity_type', 'action', 'stat_date'], 'webhook_health_stats_unique');

            // Indexes
            $table->index('account_id');
            $table->index('stat_date');
            $table->index('failure_rate');

            // Foreign key
            $table->foreign('account_id')
                  ->references('account_id')
                  ->on('accounts')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_health_stats');
    }
};
```

**Validation:**
- [ ] Migration runs successfully
- [ ] Unique constraint prevents duplicate stats

---

### Task 1.4: Update sync_settings table

**File:** `database/migrations/2025_10_29_000004_update_sync_settings_table.php`

**Purpose:** Add account_type and webhooks_enabled columns

```php
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
                $table->string('account_type', 10)->nullable()->after('account_id'); // 'main' or 'child'
            }

            if (!Schema::hasColumn('sync_settings', 'webhooks_enabled')) {
                $table->boolean('webhooks_enabled')->default(false)->after('account_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sync_settings', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'webhooks_enabled']);
        });
    }
};
```

**Validation:**
- [ ] Migration runs successfully
- [ ] Existing sync_settings records unchanged

---

### Task 1.5: Update child_accounts table

**File:** `database/migrations/2025_10_29_000005_update_child_accounts_table.php`

**Purpose:** Add status tracking for inactive accounts

```php
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
                $table->string('status', 20)->default('active')->after('child_account_id'); // active, inactive
            }

            if (!Schema::hasColumn('child_accounts', 'inactive_reason')) {
                $table->text('inactive_reason')->nullable()->after('status');
            }

            if (!Schema::hasColumn('child_accounts', 'inactive_at')) {
                $table->timestamp('inactive_at')->nullable()->after('inactive_reason');
            }

            // Add index on status
            if (!Schema::hasColumn('child_accounts', 'status')) {
                $table->index('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('child_accounts', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'inactive_reason', 'inactive_at']);
        });
    }
};
```

**Validation:**
- [ ] Migration runs successfully
- [ ] Index on status created

---

### Day 1 Validation Checklist

**Run all migrations:**
```bash
php artisan migrate:status
php artisan migrate --pretend  # Dry run first!
php artisan migrate
```

**Check database:**
```sql
-- Check webhooks table structure
\d+ webhooks

-- Check new tables exist
\dt webhook_*

-- Check new columns in sync_settings
\d+ sync_settings

-- Check new columns in child_accounts
\d+ child_accounts
```

**Test rollback:**
```bash
php artisan migrate:rollback --step=5
php artisan migrate  # Re-run
```

**Commit:**
```bash
git add database/migrations/
git commit -m "feat: Add webhook system database migrations

- Update webhooks table with missing columns
- Create webhook_logs table for request tracking
- Create webhook_health_stats table for monitoring
- Add account_type to sync_settings
- Add status tracking to child_accounts"
```

**⏱️ Estimated Time:** 3-4 hours

---

## Day 2: Models

**Goal:** Create all model classes with relationships, casts, and helper methods

**Prerequisites:**
- [ ] Day 1 migrations completed successfully
- [ ] Database tables verified

**Estimated Time:** 2-3 hours

---

### Task 2.1: Create Webhook model

**File:** `app/Models/Webhook.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Webhook extends Model
{
    protected $table = 'webhooks';

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
        'total_received' => 'integer',
        'total_failed' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relationship: Webhook belongs to Account
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
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
     * Get health status based on failure rate
     */
    public function getHealthStatus(): string
    {
        if ($this->total_received === 0) {
            return 'unknown';
        }

        $failureRate = ($this->total_failed / $this->total_received) * 100;

        if ($failureRate > 10) {
            return 'unhealthy';
        } elseif ($failureRate > 5) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Get failure rate percentage
     */
    public function getFailureRateAttribute(): float
    {
        if ($this->total_received === 0) {
            return 0;
        }

        return round(($this->total_failed / $this->total_received) * 100, 2);
    }

    /**
     * Scope: Active webhooks
     */
    public function scopeActive($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope: By account
     */
    public function scopeByAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Scope: By account type
     */
    public function scopeByAccountType($query, string $accountType)
    {
        return $query->where('account_type', $accountType);
    }
}
```

**Validation:**
- [ ] Create test record in tinker
- [ ] Test relationships work
- [ ] Test computed properties

---

### Task 2.2: Create WebhookLog model

**File:** `app/Models/WebhookLog.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class WebhookLog extends Model
{
    protected $table = 'webhook_logs';

    protected $fillable = [
        'account_id',
        'request_id',
        'webhook_id',
        'entity_type',
        'action',
        'payload',
        'events_count',
        'status',
        'tasks_created',
        'error_message',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'events_count' => 'integer',
        'tasks_created' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relationship: Log belongs to Account
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /**
     * Mark log as processing
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => 'processing',
        ]);
    }

    /**
     * Mark log as completed
     */
    public function markAsCompleted(int $tasksCreated = 0): void
    {
        $this->update([
            'status' => 'completed',
            'tasks_created' => $tasksCreated,
            'processed_at' => now(),
            'error_message' => null,
        ]);
    }

    /**
     * Mark log as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'processed_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Get processing time in seconds
     */
    public function getProcessingTimeAttribute(): ?float
    {
        if (!$this->processed_at) {
            return null;
        }

        return $this->processed_at->diffInSeconds($this->received_at);
    }

    /**
     * Scope: Pending logs
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: Failed logs
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope: By account
     */
    public function scopeByAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Scope: Recent (last N hours)
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('received_at', '>=', now()->subHours($hours));
    }
}
```

**Validation:**
- [ ] Create test log
- [ ] Test status transitions
- [ ] Test computed properties

---

### Task 2.3: Rename and enhance WebhookHealth model

**Action:** Rename `app/Models/WebhookHealth.php` → `app/Models/WebhookHealthStat.php`

**File:** `app/Models/WebhookHealthStat.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookHealthStat extends Model
{
    protected $table = 'webhook_health_stats';

    protected $fillable = [
        'account_id',
        'entity_type',
        'action',
        'stat_date',
        'total_received',
        'total_processed',
        'total_failed',
        'failure_rate',
        'avg_processing_time',
    ];

    protected $casts = [
        'stat_date' => 'date',
        'total_received' => 'integer',
        'total_processed' => 'integer',
        'total_failed' => 'integer',
        'failure_rate' => 'decimal:2',
        'avg_processing_time' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relationship: Stat belongs to Account
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /**
     * Get health status
     */
    public function getHealthStatusAttribute(): string
    {
        if ($this->failure_rate > 10) {
            return 'unhealthy';
        } elseif ($this->failure_rate > 5) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Scope: By account
     */
    public function scopeByAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Scope: By date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('stat_date', [$startDate, $endDate]);
    }

    /**
     * Scope: Unhealthy (failure_rate > 10%)
     */
    public function scopeUnhealthy($query)
    {
        return $query->where('failure_rate', '>', 10);
    }
}
```

**Validation:**
- [ ] Model can be imported: `use App\Models\WebhookHealthStat;`
- [ ] Relationships work
- [ ] Computed attributes work

---

### Task 2.4: Update model references

**Files to update:**
- `app/Services/WebhookService.php` (if using old model name)
- Any other files referencing `WebhookHealth`

**Search & Replace:**
```bash
# Find all references
grep -r "WebhookHealth" app/

# Update imports
# Old: use App\Models\WebhookHealth;
# New: use App\Models\WebhookHealthStat;
```

---

### Day 2 Validation Checklist

**Test in tinker:**
```php
php artisan tinker

// Test Webhook model
$webhook = \App\Models\Webhook::first();
$webhook->incrementReceived();
$webhook->getHealthStatus();

// Test WebhookLog model
$log = \App\Models\WebhookLog::create([
    'account_id' => 'test-uuid',
    'request_id' => 'test-request-123',
    'entity_type' => 'product',
    'action' => 'UPDATE',
    'payload' => ['test' => 'data'],
    'events_count' => 1,
    'status' => 'pending',
    'received_at' => now()
]);
$log->markAsProcessing();
$log->markAsCompleted(5);

// Test WebhookHealthStat model
$stat = \App\Models\WebhookHealthStat::first();
$stat->health_status;
```

**Commit:**
```bash
git add app/Models/
git commit -m "feat: Create webhook models with relationships

- Create Webhook model with health tracking
- Create WebhookLog model with status management
- Rename WebhookHealth to WebhookHealthStat
- Add scopes and computed properties"
```

**⏱️ Estimated Time:** 2-3 hours

---

## Day 3: Core Services Part 1

**Goal:** Create WebhookReceiverService and refactor WebhookSetupService

**Prerequisites:**
- [ ] Models created and tested
- [ ] Database tables ready

**Estimated Time:** 4-5 hours

---

### Task 3.1: Create WebhookReceiverService

**File:** `app/Services/Webhook/WebhookReceiverService.php`

**Purpose:** Fast validation + idempotency + log creation (target <50ms)

```php
<?php

namespace App\Services\Webhook;

use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

class WebhookReceiverService
{
    /**
     * Validate webhook payload structure
     */
    public function validate(array $payload): bool
    {
        // Must have events array
        if (!isset($payload['events']) || !is_array($payload['events'])) {
            Log::warning('Webhook validation failed: missing events array', ['payload' => $payload]);
            return false;
        }

        // Events array must not be empty
        if (empty($payload['events'])) {
            Log::warning('Webhook validation failed: empty events array');
            return false;
        }

        // Each event must have required fields
        foreach ($payload['events'] as $event) {
            if (!isset($event['action'])) {
                Log::warning('Webhook validation failed: event missing action', ['event' => $event]);
                return false;
            }

            if (!isset($event['meta']['type'])) {
                Log::warning('Webhook validation failed: event missing meta.type', ['event' => $event]);
                return false;
            }

            if (!isset($event['meta']['href'])) {
                Log::warning('Webhook validation failed: event missing meta.href', ['event' => $event]);
                return false;
            }
        }

        return true;
    }

    /**
     * Receive webhook and create log entry
     *
     * @param array $payload Full webhook payload
     * @param string $requestId X-Request-Id header (for idempotency)
     * @param string|null $webhookId X-Lognex-WebHook-Id header
     * @return WebhookLog
     */
    public function receive(array $payload, string $requestId, ?string $webhookId = null): WebhookLog
    {
        // Check for existing log with same requestId (idempotency)
        $existingLog = WebhookLog::where('request_id', $requestId)->first();

        if ($existingLog) {
            Log::info('Webhook duplicate detected (idempotent)', [
                'request_id' => $requestId,
                'existing_log_id' => $existingLog->id,
                'status' => $existingLog->status
            ]);

            return $existingLog;
        }

        // Extract first event to determine entity_type and action
        $firstEvent = $payload['events'][0];
        $entityType = $firstEvent['meta']['type'];
        $action = $firstEvent['action'];
        $eventsCount = count($payload['events']);

        // Determine account_id from event
        $accountId = $firstEvent['accountId'] ?? null;

        if (!$accountId) {
            Log::error('Webhook missing accountId', ['payload' => $payload]);
            throw new \Exception('Webhook payload missing accountId');
        }

        // Create log entry
        $log = WebhookLog::create([
            'account_id' => $accountId,
            'request_id' => $requestId,
            'webhook_id' => $webhookId,
            'entity_type' => $entityType,
            'action' => $action,
            'payload' => $payload,
            'events_count' => $eventsCount,
            'status' => 'pending',
            'received_at' => now(),
        ]);

        Log::info('Webhook received successfully', [
            'log_id' => $log->id,
            'request_id' => $requestId,
            'account_id' => $accountId,
            'entity_type' => $entityType,
            'action' => $action,
            'events_count' => $eventsCount
        ]);

        return $log;
    }
}
```

**Validation:**
- [ ] Test valid payload: returns WebhookLog
- [ ] Test invalid payload: returns false
- [ ] Test duplicate requestId: returns existing log

---

### Task 3.2: Refactor existing WebhookService → WebhookSetupService

**Action:** Rename `app/Services/WebhookService.php` → `app/Services/Webhook/WebhookSetupService.php`

**Create directory first:**
```bash
mkdir -p app/Services/Webhook
```

**File:** `app/Services/Webhook/WebhookSetupService.php`

**Changes:**
1. Move to new namespace
2. Add `getWebhooksConfig()` method
3. Improve error handling (collect errors, don't just log)
4. Add `reinstallWebhooks()` method

```php
<?php

namespace App\Services\Webhook;

use App\Models\Account;
use App\Models\Webhook;
use App\Services\MoySkladService;
use Illuminate\Support\Facades\Log;

class WebhookSetupService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Get webhooks configuration for account type
     */
    public function getWebhooksConfig(string $accountType): array
    {
        $webhookUrl = config('moysklad.webhook_url');

        if ($accountType === 'main') {
            // Main account: товары → Child accounts
            return [
                // Products
                ['entity_type' => 'product', 'action' => 'CREATE', 'url' => $webhookUrl],
                ['entity_type' => 'product', 'action' => 'UPDATE', 'url' => $webhookUrl],
                ['entity_type' => 'product', 'action' => 'DELETE', 'url' => $webhookUrl],

                // Services
                ['entity_type' => 'service', 'action' => 'CREATE', 'url' => $webhookUrl],
                ['entity_type' => 'service', 'action' => 'UPDATE', 'url' => $webhookUrl],
                ['entity_type' => 'service', 'action' => 'DELETE', 'url' => $webhookUrl],

                // Variants
                ['entity_type' => 'variant', 'action' => 'CREATE', 'url' => $webhookUrl],
                ['entity_type' => 'variant', 'action' => 'UPDATE', 'url' => $webhookUrl],
                ['entity_type' => 'variant', 'action' => 'DELETE', 'url' => $webhookUrl],

                // Bundles
                ['entity_type' => 'bundle', 'action' => 'CREATE', 'url' => $webhookUrl],
                ['entity_type' => 'bundle', 'action' => 'UPDATE', 'url' => $webhookUrl],
                ['entity_type' => 'bundle', 'action' => 'DELETE', 'url' => $webhookUrl],

                // Product Folders
                ['entity_type' => 'productfolder', 'action' => 'CREATE', 'url' => $webhookUrl],
                ['entity_type' => 'productfolder', 'action' => 'UPDATE', 'url' => $webhookUrl],
                ['entity_type' => 'productfolder', 'action' => 'DELETE', 'url' => $webhookUrl],
            ];
        } else {
            // Child account: заказы → Main account
            return [
                ['entity_type' => 'customerorder', 'action' => 'CREATE', 'url' => $webhookUrl],
                ['entity_type' => 'customerorder', 'action' => 'UPDATE', 'url' => $webhookUrl],
                ['entity_type' => 'customerorder', 'action' => 'DELETE', 'url' => $webhookUrl],

                ['entity_type' => 'retaildemand', 'action' => 'CREATE', 'url' => $webhookUrl],
                ['entity_type' => 'retaildemand', 'action' => 'UPDATE', 'url' => $webhookUrl],
                ['entity_type' => 'retaildemand', 'action' => 'DELETE', 'url' => $webhookUrl],

                ['entity_type' => 'purchaseorder', 'action' => 'CREATE', 'url' => $webhookUrl],
                ['entity_type' => 'purchaseorder', 'action' => 'UPDATE', 'url' => $webhookUrl],
                ['entity_type' => 'purchaseorder', 'action' => 'DELETE', 'url' => $webhookUrl],
            ];
        }
    }

    /**
     * Setup webhooks for account
     */
    public function setupWebhooksForAccount(string $accountId, string $accountType): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();
            $webhooksConfig = $this->getWebhooksConfig($accountType);

            $results = [
                'created' => [],
                'errors' => [],
            ];

            // Delete existing webhooks first
            $this->deleteWebhooksForAccount($accountId);

            // Create new webhooks
            foreach ($webhooksConfig as $config) {
                try {
                    $moyskladWebhook = $this->createWebhook(
                        $account,
                        $config['url'],
                        $config['action'],
                        $config['entity_type']
                    );

                    // Save to database
                    $webhook = Webhook::create([
                        'account_id' => $accountId,
                        'account_type' => $accountType,
                        'moysklad_webhook_id' => $moysklad Webhook['id'],
                        'entity_type' => $config['entity_type'],
                        'action' => $config['action'],
                        'url' => $config['url'],
                        'enabled' => true,
                    ]);

                    $results['created'][] = $webhook;

                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'config' => $config,
                        'error' => $e->getMessage()
                    ];

                    Log::error('Failed to create webhook', [
                        'account_id' => $accountId,
                        'config' => $config,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('Webhooks setup completed', [
                'account_id' => $accountId,
                'account_type' => $accountType,
                'created' => count($results['created']),
                'errors' => count($results['errors'])
            ]);

            return $results;

        } catch (\Exception $e) {
            Log::error('Webhooks setup failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete all webhooks for account
     */
    public function deleteWebhooksForAccount(string $accountId): void
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            // Get all webhooks from МойСклад
            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/webhook');

            $webhooks = $result['data']['rows'] ?? [];
            $webhookUrl = config('moysklad.webhook_url');

            // Delete webhooks with our URL
            foreach ($webhooks as $webhook) {
                if (isset($webhook['url']) && str_contains($webhook['url'], parse_url($webhookUrl, PHP_URL_HOST))) {
                    $this->moySkladService
                        ->setAccessToken($account->access_token)
                        ->delete("entity/webhook/{$webhook['id']}");

                    Log::info('Webhook deleted', [
                        'account_id' => $accountId,
                        'webhook_id' => $webhook['id']
                    ]);
                }
            }

            // Delete from database
            Webhook::where('account_id', $accountId)->delete();

        } catch (\Exception $e) {
            Log::warning('Failed to delete webhooks', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Reinstall webhooks for account
     */
    public function reinstallWebhooks(string $accountId): array
    {
        // Determine account type from sync_settings
        $syncSettings = \App\Models\SyncSetting::where('account_id', $accountId)->first();
        $accountType = $syncSettings->account_type ?? 'main';

        Log::info('Reinstalling webhooks', [
            'account_id' => $accountId,
            'account_type' => $accountType
        ]);

        return $this->setupWebhooksForAccount($accountId, $accountType);
    }

    /**
     * Create single webhook in МойСклад
     */
    protected function createWebhook(Account $account, string $url, string $action, string $entityType): array
    {
        $data = [
            'url' => $url,
            'action' => $action,
            'entityType' => $entityType,
            'enabled' => true,
        ];

        $result = $this->moySkladService
            ->setAccessToken($account->access_token)
            ->post('entity/webhook', $data);

        Log::info('Webhook created in МойСклад', [
            'account_id' => $account->account_id,
            'entity_type' => $entityType,
            'action' => $action,
            'moysklad_webhook_id' => $result['data']['id'] ?? null
        ]);

        return $result['data'];
    }
}
```

**Validation:**
- [ ] Service can be instantiated
- [ ] `getWebhooksConfig('main')` returns 15 configs
- [ ] `getWebhooksConfig('child')` returns 9 configs

---

### Task 3.3: Add CRITICAL cycle prevention to MoySkladService

**File:** `app/Services/MoySkladService.php`

**Add to `makeRequest()` method:**

```php
protected function makeRequest(string $method, string $endpoint, array $data = []): array
{
    $headers = [
        'Authorization' => 'Bearer ' . $this->accessToken,
        'Content-Type' => 'application/json',
        // ⚠️ CRITICAL: Prevent webhook cycles
        'X-Lognex-WebHook-DisableByPrefix' => config('app.url')
    ];

    // ... rest of method
}
```

**⚠️ THIS IS CRITICAL - Prevents infinite webhook loops!**

**Validation:**
- [ ] Check header is added: inspect request in sync.log
- [ ] Test: Main updates product → Child syncs → no webhook triggered

---

### Day 3 Validation Checklist

**Test WebhookReceiverService:**
```php
php artisan tinker

$service = app(\App\Services\Webhook\WebhookReceiverService::class);

// Test valid payload
$payload = [
    'events' => [
        [
            'action' => 'UPDATE',
            'meta' => [
                'type' => 'product',
                'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/123'
            ],
            'accountId' => 'test-account-uuid'
        ]
    ]
];

$log = $service->receive($payload, 'test-request-123');
// Should create WebhookLog

// Test idempotency
$log2 = $service->receive($payload, 'test-request-123');
// Should return same log
```

**Test WebhookSetupService:**
```php
$service = app(\App\Services\Webhook\WebhookSetupService::class);
$config = $service->getWebhooksConfig('main');
// Should return 15 items
```

**Commit:**
```bash
git add app/Services/Webhook/
git commit -m "feat: Create WebhookReceiverService and refactor WebhookSetupService

- Add WebhookReceiverService for fast validation + idempotency
- Refactor WebhookService → WebhookSetupService
- Add getWebhooksConfig() method
- Improve error collection
- Add reinstallWebhooks() method
- CRITICAL: Add cycle prevention header to MoySkladService"
```

**⏱️ Estimated Time:** 4-5 hours

---

## Day 4: Core Services Part 2

**Goal:** Create WebhookProcessorService (most complex) and WebhookHealthService

**Prerequisites:**
- [ ] Day 3 services working
- [ ] MoySkladService has cycle prevention header

**Estimated Time:** 6-8 hours (increased from 5-6h due to complexity)

**⚠️ Note:** WebhookProcessorService is the MOST COMPLEX service (~700 lines):
- Filter checks require API calls
- Race condition handling
- Batch update strategy
- Multiple entity types
- Take breaks, test frequently!

---

### Task 4.1: Create WebhookProcessorService (COMPLEX)

**File:** `app/Services/Webhook/WebhookProcessorService.php`

**Purpose:** Parse events → check filters → create sync tasks

**This is the most complex service - take your time!**

Due to size, I'll provide the structure here. Full implementation in [18-webhook-services.md](18-webhook-services.md) lines 220-620.

```php
<?php

namespace App\Services\Webhook;

use App\Models\WebhookLog;
use App\Models\Account;
use App\Models\SyncQueue;
use App\Models\SyncSetting;
use App\Services\MoySkladService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class WebhookProcessorService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Process webhook log
     *
     * @return array ['created' => int, 'skipped' => int, 'errors' => array]
     */
    public function process(WebhookLog $webhookLog): array
    {
        // Implementation from 18-webhook-services.md lines 220-280
        // Steps:
        // 1. Get account + sync_settings
        // 2. Check account_type (main vs child)
        // 3. Route to appropriate processor
        // 4. Return results
    }

    /**
     * Process webhook from Main account (товары → Child accounts)
     */
    protected function processMainAccountWebhook(array $events, Account $account, SyncSetting $settings): array
    {
        // Implementation from 18-webhook-services.md lines 282-400
        // Steps:
        // 1. Get all active child accounts
        // 2. Parse events
        // 3. For UPDATE: batch processing
        // 4. For CREATE/DELETE: individual processing with filter checks
        // 5. Create sync tasks
    }

    /**
     * Process webhook from Child account (заказы → Main account)
     */
    protected function processChildAccountWebhook(array $events, Account $account): array
    {
        // Implementation from 18-webhook-services.md lines 402-480
        // Steps:
        // 1. Get parent account
        // 2. Create sync tasks for orders
        // 3. No filter checks needed
    }

    /**
     * Check if entity passes filter (for CREATE events)
     */
    protected function checkEntityPassesFilter(string $entityId, string $entityType, SyncSetting $settings): bool
    {
        // Implementation from 18-webhook-services.md lines 482-540
        // Steps:
        // 1. Load entity from МойСклад with expand
        // 2. Apply filters (productFolder, characteristics, etc.)
        // 3. Return boolean
    }

    /**
     * Apply filters to entity
     */
    protected function applyFilters(array $entity, SyncSetting $settings): bool
    {
        // Implementation from 18-webhook-services.md lines 542-600
        // Filter logic based on sync_settings
    }

    /**
     * Create individual sync task
     */
    protected function createSyncTask(
        string $childAccountId,
        string $mainAccountId,
        string $entityType,
        string $entityId,
        string $action,
        int $priority,
        array $additionalData = []
    ): void {
        // Implementation from 18-webhook-services.md lines 602-640
        // Create task with proper payload structure
    }

    /**
     * Create batch UPDATE task
     */
    protected function createBatchUpdateTask(
        string $childAccountId,
        string $mainAccountId,
        string $entityType,
        array $entityIds,
        int $priority
    ): void {
        // Implementation from 18-webhook-services.md lines 642-680
        // Batch task for multiple entities
    }

    /**
     * Extract entity ID from href
     */
    protected function extractEntityId(string $href): ?string
    {
        $parts = explode('/', $href);
        return end($parts) ?: null;
    }

    /**
     * Get priority for entity type + action
     */
    protected function getPriority(string $action, string $entityType): int
    {
        if ($action === 'UPDATE') {
            return 10; // High priority
        }

        if ($action === 'CREATE' || $action === 'DELETE') {
            return 7; // Medium priority
        }

        if ($entityType === 'productfolder') {
            return 5; // Lower priority
        }

        return 5; // Default
    }
}
```

**⚠️ IMPORTANT:** Copy full implementation from [18-webhook-services.md](18-webhook-services.md) starting at line 220.

**Validation:**
- [ ] Service can be instantiated
- [ ] Test process() with sample webhook log
- [ ] Verify sync tasks created

---

### Task 4.2: Create WebhookHealthService

**File:** `app/Services/Webhook/WebhookHealthService.php`

```php
<?php

namespace App\Services\Webhook;

use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\WebhookHealthStat;
use Illuminate\Support\Facades\DB;

class WebhookHealthService
{
    /**
     * Get health summary for all accounts
     */
    public function getHealthSummary(array $filters = []): array
    {
        $query = Webhook::with('account')
            ->select('account_id')
            ->groupBy('account_id');

        // Apply filters
        if (!empty($filters['account_id'])) {
            $query->where('account_id', $filters['account_id']);
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->where('enabled', true);
            } elseif ($filters['status'] === 'inactive') {
                $query->where('enabled', false);
            }
        }

        $accounts = $query->get();

        $summary = [];

        foreach ($accounts as $webhook) {
            $accountId = $webhook->account_id;

            // Get webhook stats
            $webhooksCount = Webhook::where('account_id', $accountId)->count();
            $activeWebhooksCount = Webhook::where('account_id', $accountId)->where('enabled', true)->count();

            // Get 24h logs
            $logs24h = WebhookLog::where('account_id', $accountId)
                ->where('received_at', '>=', now()->subHours(24))
                ->get();

            $received24h = $logs24h->count();
            $processed24h = $logs24h->where('status', 'completed')->count();
            $failed24h = $logs24h->where('status', 'failed')->count();

            $failureRate = $received24h > 0 ? ($failed24h / $received24h) * 100 : 0;

            $summary[] = [
                'account_id' => $accountId,
                'account_name' => $webhook->account->name ?? 'Unknown',
                'account_type' => $webhook->account_type ?? 'unknown',
                'webhooks_count' => $webhooksCount,
                'active_webhooks_count' => $activeWebhooksCount,
                'received_24h' => $received24h,
                'processed_24h' => $processed24h,
                'failed_24h' => $failed24h,
                'failure_rate' => round($failureRate, 2),
                'is_active' => $activeWebhooksCount > 0,
            ];
        }

        // Apply failure_rate filter
        if (!empty($filters['failure_rate'])) {
            $summary = array_filter($summary, function($item) use ($filters) {
                if ($filters['failure_rate'] === 'high') {
                    return $item['failure_rate'] > 10;
                } elseif ($filters['failure_rate'] === 'medium') {
                    return $item['failure_rate'] >= 5 && $item['failure_rate'] <= 10;
                } elseif ($filters['failure_rate'] === 'low') {
                    return $item['failure_rate'] < 5;
                }
                return true;
            });
        }

        return $summary;
    }

    /**
     * Get detailed logs with filtering and pagination
     */
    public function getDetailedLogs(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $query = WebhookLog::with('account');

        // Apply filters
        if (!empty($filters['account_id'])) {
            $query->where('account_id', $filters['account_id']);
        }

        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('request_id', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('payload', 'like', '%' . $filters['search'] . '%');
            });
        }

        // Date range
        if (!empty($filters['date_range'])) {
            $hoursAgo = match($filters['date_range']) {
                '1h' => 1,
                '24h' => 24,
                '7d' => 24 * 7,
                '30d' => 24 * 30,
                default => 24
            };

            $query->where('received_at', '>=', now()->subHours($hoursAgo));
        }

        // Total count
        $total = $query->count();

        // Get paginated results
        $logs = $query->orderBy('received_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return [
            'data' => $logs->map(function($log) {
                return [
                    'id' => $log->id,
                    'account_id' => $log->account_id,
                    'account_name' => $log->account->name ?? 'Unknown',
                    'request_id' => $log->request_id,
                    'entity_type' => $log->entity_type,
                    'action' => $log->action,
                    'events_count' => $log->events_count,
                    'status' => $log->status,
                    'tasks_created' => $log->tasks_created,
                    'error_message' => $log->error_message,
                    'received_at' => $log->received_at->toIso8601String(),
                    'processed_at' => $log->processed_at?->toIso8601String(),
                    'payload' => $log->payload,
                ];
            }),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Get statistics for period
     */
    public function getStatistics(string $period = '24h'): array
    {
        $hoursAgo = match($period) {
            '1h' => 1,
            '24h' => 24,
            '7d' => 24 * 7,
            '30d' => 24 * 30,
            default => 24
        };

        $logs = WebhookLog::where('received_at', '>=', now()->subHours($hoursAgo))->get();

        $totalAccounts = Webhook::distinct('account_id')->count();
        $totalWebhooks = Webhook::count();
        $received = $logs->count();
        $failed = $logs->where('status', 'failed')->count();
        $avgFailureRate = $received > 0 ? ($failed / $received) * 100 : 0;

        return [
            'total_accounts' => $totalAccounts,
            'total_webhooks' => $totalWebhooks,
            'received_24h' => $received,
            'failed_24h' => $failed,
            'avg_failure_rate' => round($avgFailureRate, 2),
        ];
    }

    /**
     * Update health statistics (run hourly)
     */
    public function updateHealthStats(): void
    {
        $yesterday = now()->subDay();

        // Get all logs from yesterday
        $logs = WebhookLog::whereDate('received_at', $yesterday->toDateString())->get();

        // Group by account_id, entity_type, action
        $grouped = $logs->groupBy(function($log) {
            return "{$log->account_id}|{$log->entity_type}|{$log->action}";
        });

        foreach ($grouped as $key => $groupLogs) {
            [$accountId, $entityType, $action] = explode('|', $key);

            $totalReceived = $groupLogs->count();
            $totalProcessed = $groupLogs->where('status', 'completed')->count();
            $totalFailed = $groupLogs->where('status', 'failed')->count();
            $failureRate = $totalReceived > 0 ? ($totalFailed / $totalReceived) * 100 : 0;

            // Calculate average processing time
            $processedLogs = $groupLogs->whereNotNull('processed_at');
            $avgProcessingTime = $processedLogs->count() > 0
                ? $processedLogs->avg(function($log) {
                    return $log->processed_at->diffInSeconds($log->received_at);
                })
                : null;

            // Create or update stat
            WebhookHealthStat::updateOrCreate(
                [
                    'account_id' => $accountId,
                    'entity_type' => $entityType,
                    'action' => $action,
                    'stat_date' => $yesterday->toDateString(),
                ],
                [
                    'total_received' => $totalReceived,
                    'total_processed' => $totalProcessed,
                    'total_failed' => $totalFailed,
                    'failure_rate' => round($failureRate, 2),
                    'avg_processing_time' => $avgProcessingTime ? round($avgProcessingTime, 2) : null,
                ]
            );
        }
    }

    /**
     * Get alerts for accounts with high failure rate
     */
    public function getAlerts(): array
    {
        $alerts = [];

        // Get accounts with >10% failure rate in last 24h
        $summary = $this->getHealthSummary();

        foreach ($summary as $item) {
            if ($item['failure_rate'] > 10) {
                $alerts[] = [
                    'account_id' => $item['account_id'],
                    'account_name' => $item['account_name'],
                    'message' => "High webhook failure rate: {$item['failure_rate']}%",
                    'failure_rate' => $item['failure_rate'],
                    'failed_count' => $item['failed_24h'],
                    'total_count' => $item['received_24h'],
                ];
            }
        }

        return $alerts;
    }
}
```

**Validation:**
- [ ] Service can be instantiated
- [ ] Test getHealthSummary()
- [ ] Test getStatistics()

---

### Day 4 Validation Checklist

**Test WebhookProcessorService (CRITICAL):**
```php
php artisan tinker

$service = app(\App\Services\Webhook\WebhookProcessorService::class);

// Create test webhook log
$log = \App\Models\WebhookLog::create([
    'account_id' => 'test-main-account-id',
    'request_id' => 'test-123',
    'entity_type' => 'product',
    'action' => 'UPDATE',
    'payload' => [
        'events' => [
            [
                'action' => 'UPDATE',
                'meta' => [
                    'type' => 'product',
                    'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/test-product-id'
                ],
                'accountId' => 'test-main-account-id',
                'updatedFields' => ['salePrices']
            ]
        ]
    ],
    'events_count' => 1,
    'status' => 'pending',
    'received_at' => now()
]);

// Process
$result = $service->process($log);
// Should create sync tasks

// Check sync_queue
\App\Models\SyncQueue::latest()->first();
```

**Commit:**
```bash
git add app/Services/Webhook/
git commit -m "feat: Create WebhookProcessorService and WebhookHealthService

- Add WebhookProcessorService with full event processing logic
- Implement filter checks for CREATE events
- Add batch UPDATE task creation
- Create WebhookHealthService for monitoring
- Add statistics and alerts functionality"
```

**⏱️ Estimated Time:** 5-6 hours

---

## Day 5: Jobs ⚠️ CRITICAL

**Goal:** Create async jobs for webhook processing and setup

**Prerequisites:**
- [ ] All services created and tested
- [ ] Queue worker running

**Estimated Time:** 3-4 hours

---

### Task 5.1: Create ProcessWebhookJob

**File:** `app/Jobs/ProcessWebhookJob.php`

**See:** [18-webhook-implementation.md](18-webhook-implementation.md) lines 15-100 for full implementation

**Key points:**
- Timeout: 120s
- Tries: 3
- Backoff: Exponential (10s, 30s, 90s)
- Calls WebhookProcessorService::process()
- Updates webhook_log status
- Increments webhook counters

**Validation:**
```bash
php artisan tinker
\App\Jobs\ProcessWebhookJob::dispatch($webhookLogId)
# Check logs for processing
```

---

### Task 5.2: Create SetupAccountWebhooksJob

**File:** `app/Jobs/SetupAccountWebhooksJob.php`

**See:** [18-webhook-implementation.md](18-webhook-implementation.md) lines 102-180 for full implementation

**Key points:**
- Timeout: 300s
- Tries: 3
- Calls WebhookSetupService::setupWebhooksForAccount()
- Updates sync_settings.webhooks_enabled

**Validation:**
```bash
php artisan tinker
\App\Jobs\SetupAccountWebhooksJob::dispatch($accountId, 'main')
# Check webhooks table
```

---

### Day 5 Validation Checklist

- [ ] Both jobs can be dispatched
- [ ] Jobs process successfully
- [ ] Failed jobs appear in failed_jobs table
- [ ] Retry mechanism works

**Commit:**
```bash
git add app/Jobs/
git commit -m "feat: Create async jobs for webhook processing

- Add ProcessWebhookJob for async webhook processing
- Add SetupAccountWebhooksJob for async webhook installation
- Configure retries and timeouts
- Add proper error handling"
```

**⏱️ Estimated Time:** 3-4 hours

---

## Day 6: Controllers & Routes

**Goal:** Create/rewrite controllers and configure routes

**Prerequisites:**
- [ ] Jobs created and tested
- [ ] Services working

**Estimated Time:** 4-5 hours

---

### Task 6.1: Rewrite WebhookController

**File:** `app/Http/Controllers/Api/WebhookController.php`

**See:** [18-webhook-implementation.md](18-webhook-implementation.md) lines 190-260 for full implementation

**Key changes from existing code:**
- Proper payload parsing: `$event['action']` not `$payload['action']`
- Idempotency via X-Request-Id header
- Fast response (<100ms)
- Dispatch ProcessWebhookJob (async)
- Return 200 immediately

---

### Task 6.2: Create WebhookManagementController

**File:** `app/Http/Controllers/Admin/WebhookManagementController.php`

**See:** [18-webhook-implementation.md](18-webhook-implementation.md) lines 262-460 for full implementation

**Endpoints:**
- GET /admin/webhooks - list with health
- POST /admin/webhooks/setup - install webhooks
- POST /admin/webhooks/{accountId}/reinstall
- POST /admin/webhooks/{accountId}/toggle
- DELETE /admin/webhooks/{accountId}
- GET /admin/webhooks/logs
- GET /admin/webhooks/statistics
- GET /admin/webhooks/alerts

---

### Task 6.3: Update routes

**File:** `routes/api.php`

**See:** [18-webhook-implementation.md](18-webhook-implementation.md) lines 462-490

---

### Day 6 Validation Checklist

**Test with curl:**
```bash
# Test webhook receiver
curl -X POST http://app.cavaleria.ru/api/webhooks/receive \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: test-123" \
  -d @webhook-test-payload.json

# Test admin endpoints (with auth)
curl -X GET http://app.cavaleria.ru/api/admin/webhooks \
  -H "X-Context-Key: your-context-key"
```

**Commit:**
```bash
git add app/Http/Controllers/ routes/
git commit -m "feat: Create webhook controllers and routes

- Rewrite WebhookController with proper payload parsing
- Add WebhookManagementController for admin endpoints
- Configure public and admin routes
- Add authentication middleware"
```

**⏱️ Estimated Time:** 4-5 hours

---

## Day 7: Artisan Commands

**Goal:** Create CLI commands for webhook management

**Prerequisites:**
- [ ] All backend components working

**Estimated Time:** 3-4 hours

---

### Task 7.1-7.4: Create Commands

**Files:**
1. `app/Console/Commands/WebhooksSetupCommand.php`
2. `app/Console/Commands/WebhooksCheckCommand.php`
3. `app/Console/Commands/WebhooksCleanupLogsCommand.php`
4. `app/Console/Commands/WebhooksUpdateStatsCommand.php`

**See:** [18-webhook-implementation.md](18-webhook-implementation.md) lines 500-850 for full implementations

---

### Task 7.5: Schedule commands

**File:** `app/Console/Kernel.php`

```php
protected function schedule(Schedule $schedule): void
{
    // Health check hourly
    $schedule->command('webhooks:check')->hourly();

    // Update stats daily
    $schedule->command('webhooks:update-stats')->dailyAt('03:00');

    // Cleanup old logs weekly
    $schedule->command('webhooks:cleanup-logs --days=30')->weekly();
}
```

---

### Day 7 Validation Checklist

**Test each command:**
```bash
php artisan webhooks:setup --account=test-account-id --type=main
php artisan webhooks:check
php artisan webhooks:cleanup-logs --days=30 --dry-run
php artisan webhooks:update-stats
```

**Commit:**
```bash
git add app/Console/
git commit -m "feat: Add Artisan commands for webhook management

- Add webhooks:setup command for installation
- Add webhooks:check for health monitoring
- Add webhooks:cleanup-logs for maintenance
- Add webhooks:update-stats for aggregation
- Configure scheduler for automation"
```

**⏱️ Estimated Time:** 3-4 hours

---

## WEEK 2: Frontend & Testing

---

## Day 8-9: Vue Components

**Goal:** Create frontend UI for webhook management

**See:** [18-webhook-frontend.md](18-webhook-frontend.md) for complete implementations

**Components to create:**
1. **AccountTypeSelector.vue** - Route: /welcome
2. **admin/WebhookHealth.vue** - Route: /admin/webhook-health
3. **admin/WebhookLogs.vue** - Route: /admin/webhook-logs

**Also update:**
- Router configuration
- Navigation menus
- API interceptor (ensure contextKey from sessionStorage)

**Estimated Time:** 8-10 hours total

---

## Day 10: Testing

**Goal:** Comprehensive testing - Unit + Integration + Manual

**See:** [18-webhook-testing.md](18-webhook-testing.md) for all test implementations

**Tasks:**
1. Create Unit Tests (tests/Unit/Services/Webhook/*)
2. Create Integration Tests (tests/Feature/Webhook*)
3. Manual Testing Checklist:
   - Setup testing (Main & Child)
   - Event testing (CREATE/UPDATE/DELETE)
   - Batch testing (10+ entities)
   - Filter testing
   - Idempotency testing
   - Cycle prevention testing

**Target:** >70% code coverage (reduced from 80% - prioritize critical paths)

**⚠️ Priority Tests (MUST HAVE):**
1. WebhookReceiverService (idempotency, validation) - CRITICAL
2. WebhookProcessorService (filter logic, task creation) - CRITICAL
3. Cycle prevention (integration test) - CRITICAL
4. Race conditions (integration test) - HIGH
5. Health monitoring - MEDIUM

**Estimated Time:** 8-10 hours (increased from 6-8h for thorough critical path testing)

---

## WEEK 3: Deployment

---

## Day 11-12: Staging Deployment

**Goal:** Deploy to staging and validate for 24 hours

**Tasks:**
1. Deploy to staging:
   ```bash
   git push staging feature/webhook-system-complete
   ssh staging
   cd /path/to/app
   ./deploy.sh
   ```

2. Setup test accounts:
   - Install webhooks on Main test account
   - Install webhooks on Child test account

3. Monitoring (24 hours):
   - Check webhook_logs every hour
   - Check sync_queue tasks created
   - Run `php artisan webhooks:check` hourly
   - Monitor logs for errors

4. Performance testing:
   - Send batch webhook (100 entities)
   - Send high frequency (100 webhooks/minute)
   - Check processing times

**Success Criteria:**
- ✅ No errors in logs for 24 hours
- ✅ All webhooks processed successfully
- ✅ Tasks created correctly
- ✅ Performance acceptable (<5s processing)
- ✅ No infinite loops detected

**Estimated Time:** 8-10 hours (includes monitoring)

---

## Day 13-14: Production Rollout

**Goal:** Gradual production deployment with monitoring

**Tasks:**

### Day 13: Initial Production Deploy

1. **Backup database:**
   ```bash
   pg_dump multiaccount > backup_$(date +%Y%m%d_%H%M%S).sql
   ```

2. **Deploy to production:**
   ```bash
   ./deploy.sh
   # Migrations run automatically
   # Queue worker restarted
   ```

3. **Phase 1: Single test account (48 hours)**
   ```bash
   php artisan webhooks:setup --account=production-test-account-id --type=main
   ```
   - Monitor closely for 48 hours
   - Check all metrics
   - Verify no issues

### Day 14: Full Rollout

4. **Phase 2: Gradual rollout**
   - Hour 0-6: +5 accounts
   - Hour 6-12: +10 accounts (if no issues)
   - Hour 12-18: +20 accounts (if no issues)
   - Hour 18-24: All remaining accounts

5. **Phase 3: Full monitoring (7 days)**
   - Daily health checks: `php artisan webhooks:check`
   - Weekly stats review
   - Alert on >5% failure rate
   - User feedback collection

**Success Criteria:**
- ✅ Production stable for 7 days
- ✅ Failure rate <5%
- ✅ No infinite loops
- ✅ No data loss
- ✅ No user complaints
- ✅ Sync latency reduced by 90%

**Estimated Time:** Variable (depends on issues, 12-20 hours)

---

## Rollback Plan

**If critical issues arise:**

```bash
# 1. Disable all webhooks immediately
php artisan webhooks:delete-all --confirm

# 2. Stop queue worker
sudo supervisorctl stop laravel-worker:*

# 3. Rollback migrations (if needed)
php artisan migrate:rollback --step=5

# 4. Rollback code
git revert HEAD
./deploy.sh

# 5. Resume manual sync workflow
UPDATE sync_settings SET webhooks_enabled = false;
```

---

## Summary

**Total Estimated Time:** 80-100 hours

### Week 1 (Days 1-7): Backend - 32-40 hours
- Day 1: Migrations (3-4h)
- Day 2: Models (2-3h)
- Day 3: Services Part 1 (4-5h)
- Day 4: Services Part 2 (5-6h)
- Day 5: Jobs (3-4h)
- Day 6: Controllers (4-5h)
- Day 7: Commands (3-4h)

### Week 2 (Days 8-10): Frontend & Testing - 20-24 hours
- Day 8-9: Vue Components (8-10h)
- Day 10: Testing (6-8h)

### Week 3 (Days 11-14): Deployment - 24-30 hours
- Day 11-12: Staging (8-10h)
- Day 13-14: Production (12-20h)

---

## Critical Path Items ⚠️

These MUST be completed correctly or entire system fails:

1. **Day 1:** Database migrations - blocks everything
2. **Day 3:** Cycle prevention header - prevents infinite loops
3. **Day 4:** WebhookProcessorService filter logic - prevents wrong data sync
4. **Day 4:** Race condition handling - prevents duplicate tasks
5. **Day 5:** ProcessWebhookJob error handling - ensures reliability
6. **Day 10:** Testing - catches bugs before production
7. **Day 13-14:** Gradual rollout - minimizes production risk

---

## Next Steps

**Ready to start implementation?**

1. Begin with Day 1 (Database Migrations)
2. Follow checklist for each task
3. Run validation steps after each day
4. Commit code frequently
5. Ask for help if stuck

**Questions during implementation?**
- Refer to detailed docs: [18-webhook-services.md](18-webhook-services.md), [18-webhook-implementation.md](18-webhook-implementation.md), etc.
- Check existing code examples
- Use Claude Code for assistance
- Test in staging first

**Good luck! 🚀**