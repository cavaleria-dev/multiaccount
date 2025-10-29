# Webhook System - Migration Guide

**Migration strategy** - How to migrate from existing partial implementation to complete system

**See also:**
- [19-webhook-roadmap.md](19-webhook-roadmap.md) - High-level timeline & overview
- [19-webhook-tasks.md](19-webhook-tasks.md) - Detailed day-by-day tasks
- [18-webhook-system.md](18-webhook-system.md) - Architecture reference

---

## Quick Reference

### What Needs Migration?

**Database:**
- ✅ `webhooks` table exists → needs ALTER (add 5 columns + rename 1)
- ✅ `webhook_health` table exists → keep as is (or rename)
- ❌ `webhook_logs` table missing → needs CREATE
- ❌ `webhook_health_stats` table missing → needs CREATE
- ❌ `sync_settings` missing columns → needs ALTER
- ❌ `child_accounts` missing columns → needs ALTER

**Services:**
- ✅ `WebhookService.php` exists → rename to `WebhookSetupService.php` + refactor
- ❌ `WebhookReceiverService.php` missing → needs CREATE
- ❌ `WebhookProcessorService.php` missing → needs CREATE
- ❌ `WebhookHealthService.php` missing → needs CREATE

**Controllers:**
- ✅ `WebhookController.php` exists → needs REWRITE (wrong payload parsing)
- ❌ `Admin/WebhookManagementController.php` missing → needs CREATE

**Models:**
- ✅ `WebhookHealth.php` exists → rename to `WebhookHealthStat.php` + enhance
- ❌ `Webhook.php` missing → needs CREATE
- ❌ `WebhookLog.php` missing → needs CREATE

---

## ⚠️ Pre-Migration Critical Fixes (MANDATORY)

**⚠️ EXECUTE THESE BEFORE Step 0 (Preparation):**

Code review revealed critical issues that MUST be fixed before starting migration. Without these fixes, the system will fail in production!

---

### Critical Fix #1: Cycle Prevention Header (5 minutes) 🔴

**Problem:** Missing `X-Lognex-WebHook-DisableByPrefix` header in all API requests

**Location:** `app/Services/MoySkladService.php:170`

**Impact:** CRITICAL - Without this header:
- Main updates product → webhook → Child syncs
- Child sync triggers webhook back to Main (NO DisableByPrefix!)
- Main sees "Child updated" → webhook → INFINITE LOOP ♾️
- API overload → system crash → data corruption

**Solution:**
```bash
# SSH to server (no local PHP!)
ssh your-server
cd /var/www/multiaccount

# Edit file
nano app/Services/MoySkladService.php
# Find line ~170
```

**Find:**
```php
$headers = [
    'Authorization' => 'Bearer ' . $this->accessToken,
    'Accept-Encoding' => 'gzip',
    'Content-Type' => 'application/json',
];
```

**Replace with:**
```php
$headers = [
    'Authorization' => 'Bearer ' . $this->accessToken,
    'Accept-Encoding' => 'gzip',
    'Content-Type' => 'application/json',
    'X-Lognex-WebHook-DisableByPrefix' => config('app.url'), // ⚠️ CRITICAL
];
```

**Validation:**
```bash
grep "X-Lognex-WebHook-DisableByPrefix" app/Services/MoySkladService.php
# Should output the header line
```

**Commit:**
```bash
git add app/Services/MoySkladService.php
git commit -m "fix: Add X-Lognex-WebHook-DisableByPrefix header to prevent infinite webhook loops"
```

---

### Critical Fix #2: Disable Broken Webhooks (10 minutes) 🔴

**Problem:** Existing `WebhookController.php` parses payload INCORRECTLY

**Location:** `app/Http/Controllers/Api/WebhookController.php:32-33`

**Current code:**
```php
$action = $payload['action'] ?? null;        // ❌ Always NULL!
$entityType = $payload['entityType'] ?? null; // ❌ Always NULL!
```

**Why broken:**
МойСклад sends `action` and `entityType` INSIDE each event, NOT at root level!

**Impact:**
- All webhooks return error 400
- OR create incorrect sync tasks
- Data corruption risk

**Solution:** Delete all existing webhooks

**Option A: Via МойСклад UI**
1. Login to each МойСклад account
2. Настройки → Приложения → Вебхуки
3. Delete ALL webhooks for "app.cavaleria.ru"

**Option B: Via API (faster)**
```bash
ssh your-server
cd /var/www/multiaccount
php artisan tinker

# In tinker:
$service = app(\App\Services\WebhookService::class);
$accounts = \App\Models\Account::all();
foreach ($accounts as $account) {
    try {
        $service->cleanupOldWebhooks($account->account_id);
        echo "✓ Cleaned: {$account->account_id}\n";
    } catch (\Exception $e) {
        echo "✗ Failed: {$account->account_id} - {$e->getMessage()}\n";
    }
}
exit
```

**Validation:**
```bash
php artisan tinker --execute="echo \App\Models\WebhookHealth::count();"
# Should output: 0
```

---

### Critical Fix #3: Verify Database Backup (5 minutes) 🔴

**Before ANY migration steps:**

```bash
# SSH to server
ssh your-server
cd /var/www/multiaccount

# Create backup
sudo -u postgres pg_dump multiaccount > backup_pre_webhook_$(date +%Y%m%d_%H%M%S).sql

# Verify
ls -lh backup_pre_webhook_*.sql
# Size should be > 0 bytes and reasonable (1MB-500MB)
```

---

### Pre-Migration Checklist

**✅ VERIFY BEFORE PROCEEDING TO STEP 0:**

- [ ] Cycle prevention header added to MoySkladService.php
- [ ] All broken webhooks deleted from МойСклад
- [ ] Database backup created and verified
- [ ] Git commit made for header fix
- [ ] Read [19-webhook-roadmap.md](19-webhook-roadmap.md) section "🚨 КРИТИЧЕСКИЕ НАХОДКИ В КОДЕ"

**If ANY item unchecked → DO NOT PROCEED!**

**See:** [19-webhook-tasks.md](19-webhook-tasks.md) Day 0 for detailed step-by-step instructions.

---

## Code Inventory

### Existing Files (20% Complete)

#### Controllers (1 file - needs rewrite)

**File:** `app/Http/Controllers/Api/WebhookController.php` (266 lines)

**Status:** ⚠️ NEEDS COMPLETE REWRITE

**Current Issues:**
```php
// ❌ WRONG: Assumes action/entityType at root level
$action = $payload['action'] ?? null;
$entityType = $payload['entityType'] ?? null;
$entities = $payload['events'] ?? [];

// ✅ CORRECT: action/type are inside each event
$events = $payload['events'];
foreach ($events as $event) {
    $action = $event['action'];              // Inside event!
    $entityType = $event['meta']['type'];    // Inside meta!
}
```

**Other Issues:**
- Synchronous processing (blocks response)
- No idempotency check (duplicate webhooks not handled)
- Direct `SyncQueue::create()` (violates SRP)
- No error collection

**Migration Strategy:**
1. Keep old file as `WebhookController.old.php`
2. Create new `WebhookController.php` from scratch (see Day 6)
3. Test new controller works
4. Delete `.old.php` file

---

#### Services (1 file - needs refactor)

**File:** `app/Services/WebhookService.php` (360 lines)

**Status:** ⚠️ RENAME + ENHANCE

**What to keep:**
- ✅ `setupWebhooks()` method (basic logic good)
- ✅ `cleanupOldWebhooks()` method
- ✅ `checkWebhookHealth()` method
- ✅ `createWebhook()` helper

**What to add:**
- ❌ `getWebhooksConfig()` method (config per account type)
- ❌ `reinstallWebhooks()` method
- ❌ Better error collection (return errors array, don't just log)

**Migration Strategy:**
1. Create directory: `app/Services/Webhook/`
2. Copy `WebhookService.php` → `Webhook/WebhookSetupService.php`
3. Update namespace: `namespace App\Services\Webhook;`
4. Enhance with new methods (see Day 3)
5. Update references in code
6. Delete old file

---

#### Models (1 file - needs rename)

**File:** `app/Models/WebhookHealth.php` (37 lines)

**Status:** ⚠️ RENAME + ENHANCE

**What to keep:**
- ✅ All existing code

**What to add:**
- ❌ Computed property: `getHealthStatusAttribute()`
- ❌ Additional scopes: `unhealthy()`, `byAccount()`, `dateRange()`

**Migration Strategy:**
1. Rename `WebhookHealth.php` → `WebhookHealthStat.php`
2. Update class name
3. Add new methods (see Day 2)
4. Search & replace all references in code

---

#### Migrations (2 files - need updates)

**File 1:** `database/migrations/2025_10_13_000006_create_webhooks_table.php`

**Status:** ⚠️ NEEDS ALTER MIGRATION

**Missing columns:**
```sql
-- Need to add:
account_type VARCHAR(10)
diff_type VARCHAR(20)
last_triggered_at TIMESTAMP
total_received INTEGER DEFAULT 0
total_failed INTEGER DEFAULT 0

-- Need to rename:
webhook_id → moysklad_webhook_id

-- Need to add constraint:
UNIQUE (account_id, entity_type, action)
```

**Migration Strategy:**
1. Create new migration: `2025_10_29_000001_update_webhooks_table.php`
2. Use `Schema::table()` not `Schema::create()`
3. Check if columns exist before adding (see Day 1)
4. Test rollback works

---

**File 2:** `database/migrations/2025_10_13_100004_create_webhook_health_table.php`

**Status:** ✅ KEEP AS IS (or rename table to webhook_health_stats)

**Options:**
- Option A: Keep table name `webhook_health` (simpler)
- Option B: Rename to `webhook_health_stats` (more consistent with documentation)

**Recommendation:** Option A (keep as is) - less migration risk

---

#### Routes (1 endpoint - needs update)

**File:** `routes/api.php`

**Current:**
```php
Route::post('webhooks/moysklad', [WebhookController::class, 'handle'])
    ->name('webhooks.moysklad');
```

**Should be:**
```php
// Public endpoint (no auth needed - МойСклад webhooks)
Route::post('webhooks/receive', [WebhookController::class, 'receive'])
    ->name('webhooks.receive');

// Admin endpoints (auth required)
Route::prefix('admin')->middleware(['auth', 'admin'])->group(function() {
    Route::prefix('webhooks')->group(function() {
        Route::get('/', [Admin\WebhookManagementController::class, 'index']);
        Route::post('/setup', [Admin\WebhookManagementController::class, 'setup']);
        // ... more endpoints
    });
});
```

**Migration Strategy:**
1. Add new routes
2. Keep old route temporarily for backward compatibility
3. Test new routes work
4. Remove old route

---

## Missing Files (80% Not Implemented)

### Services (3 files - need CREATE)

1. **`app/Services/Webhook/WebhookReceiverService.php`** (0% - NOT EXISTS)
   - Purpose: Fast validation + idempotency + log creation
   - Lines: ~150
   - See: Day 3 tasks

2. **`app/Services/Webhook/WebhookProcessorService.php`** (0% - NOT EXISTS)
   - Purpose: Event parsing + filter checks + task creation
   - Lines: ~450 (most complex service)
   - See: Day 4 tasks

3. **`app/Services/Webhook/WebhookHealthService.php`** (0% - NOT EXISTS)
   - Purpose: Health monitoring + statistics + alerts
   - Lines: ~300
   - See: Day 4 tasks

---

### Jobs (2 files - need CREATE)

1. **`app/Jobs/ProcessWebhookJob.php`** (0% - NOT EXISTS)
   - Purpose: Async webhook processing
   - See: [18-webhook-implementation.md](18-webhook-implementation.md) lines 15-100

2. **`app/Jobs/SetupAccountWebhooksJob.php`** (0% - NOT EXISTS)
   - Purpose: Async webhook installation
   - See: [18-webhook-implementation.md](18-webhook-implementation.md) lines 102-180

---

### Controllers (1 file - need CREATE)

1. **`app/Http/Controllers/Admin/WebhookManagementController.php`** (0% - NOT EXISTS)
   - Purpose: Admin API endpoints for webhook management
   - See: [18-webhook-implementation.md](18-webhook-implementation.md) lines 262-460

---

### Models (2 files - need CREATE)

1. **`app/Models/Webhook.php`** (0% - NOT EXISTS)
   - Purpose: Webhook configuration model
   - See: Day 2 tasks

2. **`app/Models/WebhookLog.php`** (0% - NOT EXISTS)
   - Purpose: Webhook request log model
   - See: Day 2 tasks

---

### Migrations (5 files - need CREATE)

1. **`2025_10_29_000001_update_webhooks_table.php`** - ALTER existing table
2. **`2025_10_29_000002_create_webhook_logs_table.php`** - NEW table
3. **`2025_10_29_000003_create_webhook_health_stats_table.php`** - NEW table (or skip if keeping webhook_health)
4. **`2025_10_29_000004_update_sync_settings_table.php`** - ALTER add account_type + webhooks_enabled
5. **`2025_10_29_000005_update_child_accounts_table.php`** - ALTER add status + inactive_reason + inactive_at

---

### Commands (4 files - need CREATE)

1. **`app/Console/Commands/WebhooksSetupCommand.php`**
2. **`app/Console/Commands/WebhooksCheckCommand.php`**
3. **`app/Console/Commands/WebhooksCleanupLogsCommand.php`**
4. **`app/Console/Commands/WebhooksUpdateStatsCommand.php`**

See: [18-webhook-implementation.md](18-webhook-implementation.md) lines 500-850

---

### Frontend (3 components - need CREATE)

1. **`resources/js/components/AccountTypeSelector.vue`**
2. **`resources/js/components/admin/WebhookHealth.vue`**
3. **`resources/js/components/admin/WebhookLogs.vue`**

See: [18-webhook-frontend.md](18-webhook-frontend.md)

---

### Tests (0% coverage - need CREATE)

1. **Unit Tests:** `tests/Unit/Services/Webhook/*.php`
2. **Integration Tests:** `tests/Feature/Webhook*.php`

See: [18-webhook-testing.md](18-webhook-testing.md)

---

## Step-by-Step Migration Process

### Step 0: Preparation (Before Day 1)

**Backup everything:**
```bash
# Database backup (on server via SSH!)
ssh your-server
cd /var/www/multiaccount
sudo -u postgres pg_dump multiaccount > backup_pre_webhook_$(date +%Y%m%d).sql
# Or: php artisan db:dump --database=pgsql
exit

# Code backup (local)
git stash save "pre-webhook-migration-$(date +%Y%m%d)"

# Create feature branch (local)
git checkout -b feature/webhook-system-complete

# Document current state (on server via SSH)
ssh your-server
cd /var/www/multiaccount
php artisan route:list > routes_before_webhook.txt
ls -la app/Services/ > services_before_webhook.txt
exit
```

**Verify prerequisites:**
- [ ] PostgreSQL 18 running
- [ ] Redis running
- [ ] Supervisor running queue worker
- [ ] МойСклад test accounts available
- [ ] SSH access to production server
- [ ] Deployment scripts tested

---

### Step 1: Database Layer (Day 1)

**Order matters! Do in this sequence:**

1. **Create new tables first** (no dependencies):
   ```bash
   php artisan make:migration create_webhook_logs_table
   php artisan make:migration create_webhook_health_stats_table
   ```

2. **Update existing tables** (check for existing columns):
   ```bash
   php artisan make:migration update_webhooks_table
   php artisan make:migration update_sync_settings_table
   php artisan make:migration update_child_accounts_table
   ```

3. **Test migrations:**
   ```bash
   # Dry run
   php artisan migrate --pretend

   # Run on local/staging FIRST
   php artisan migrate

   # Test rollback
   php artisan migrate:rollback --step=5

   # Re-run
   php artisan migrate
   ```

4. **Verify schema:**
   ```sql
   -- Check all columns exist
   \d+ webhooks
   \d+ webhook_logs
   \d+ webhook_health_stats
   \d+ sync_settings
   \d+ child_accounts

   -- Check indexes
   SELECT tablename, indexname, indexdef
   FROM pg_indexes
   WHERE schemaname = 'public'
   AND tablename LIKE 'webhook%';
   ```

**Rollback plan:**
```bash
php artisan migrate:rollback --step=5
```

---

### Step 2: Model Layer (Day 2)

**Order matters! Dependencies:**

1. **Rename existing model:**
   ```bash
   # Rename file
   git mv app/Models/WebhookHealth.php app/Models/WebhookHealthStat.php

   # Update class name inside file
   # Update namespace imports in all files
   ```

2. **Create new models:**
   ```bash
   # Webhook model (depends on webhooks table)
   # WebhookLog model (depends on webhook_logs table)
   ```

3. **Update references:**
   ```bash
   # Find all uses of old model name
   grep -r "WebhookHealth" app/

   # Replace with WebhookHealthStat
   # Update imports
   ```

4. **Test in tinker:**
   ```php
   php artisan tinker

   // Test each model
   \App\Models\Webhook::first();
   \App\Models\WebhookLog::first();
   \App\Models\WebhookHealthStat::first();

   // Test relationships
   $webhook = \App\Models\Webhook::first();
   $webhook->account;
   ```

**Rollback plan:**
```bash
git checkout app/Models/
```

---

### Step 3: Service Layer (Days 3-4)

**Order matters! Dependencies:**

1. **Create service directory:**
   ```bash
   mkdir -p app/Services/Webhook
   ```

2. **Migrate existing service (Day 3 morning):**
   ```bash
   # Copy to new location
   cp app/Services/WebhookService.php app/Services/Webhook/WebhookSetupService.php

   # Update namespace
   # Enhance with new methods
   # Test works
   ```

3. **Create new services (Day 3 afternoon + Day 4):**
   ```bash
   # Create in order:
   # 1. WebhookReceiverService (no dependencies)
   # 2. WebhookProcessorService (depends on Receiver)
   # 3. WebhookHealthService (depends on models)
   ```

4. **Update service providers:**
   ```php
   // app/Providers/AppServiceProvider.php
   public function register(): void
   {
       $this->app->singleton(\App\Services\Webhook\WebhookReceiverService::class);
       $this->app->singleton(\App\Services\Webhook\WebhookProcessorService::class);
       $this->app->singleton(\App\Services\Webhook\WebhookSetupService::class);
       $this->app->singleton(\App\Services\Webhook\WebhookHealthService::class);
   }
   ```

5. **Update old references:**
   ```bash
   # Find all uses of old service
   grep -r "WebhookService" app/ --exclude-dir=Services

   # Update to new namespace
   ```

6. **Delete old service:**
   ```bash
   # Only after confirming new service works!
   rm app/Services/WebhookService.php
   ```

**Rollback plan:**
```bash
git checkout app/Services/
```

---

### Step 4: Job Layer (Day 5)

**No migration needed - all new files:**

1. Create `ProcessWebhookJob.php`
2. Create `SetupAccountWebhooksJob.php`
3. Test dispatch + processing
4. Verify failed_jobs table behavior

**Rollback plan:**
```bash
git checkout app/Jobs/
```

---

### Step 5: Controller Layer (Day 6)

**Careful! Webhook endpoint must stay accessible:**

1. **Create backup of old controller:**
   ```bash
   cp app/Http/Controllers/Api/WebhookController.php \
      app/Http/Controllers/Api/WebhookController.old.php
   ```

2. **Rewrite WebhookController.php:**
   - New implementation from scratch
   - Test side-by-side with old controller

3. **Add new admin controller:**
   - Create `Admin/WebhookManagementController.php`
   - No migration needed (new file)

4. **Update routes:**
   ```php
   // Keep old route temporarily
   Route::post('webhooks/moysklad', [WebhookController::class, 'handle_old']);

   // Add new route
   Route::post('webhooks/receive', [WebhookController::class, 'receive']);
   ```

5. **Test both endpoints work:**
   ```bash
   # Test old endpoint
   curl -X POST http://localhost/api/webhooks/moysklad -d @test.json

   # Test new endpoint
   curl -X POST http://localhost/api/webhooks/receive -d @test.json
   ```

6. **Switch МойСклад webhooks to new endpoint:**
   - Update webhook URL in МойСклад
   - Verify webhooks come to new endpoint

7. **Remove old code:**
   ```bash
   # After 24 hours of successful operation
   rm app/Http/Controllers/Api/WebhookController.old.php
   # Remove old route from routes/api.php
   ```

**Rollback plan:**
```bash
# Revert to old controller
git checkout app/Http/Controllers/Api/WebhookController.php

# Update МойСклад webhook URL back to old endpoint
```

---

### Step 6: Command Layer (Day 7)

**No migration needed - all new files:**

1. Create 4 commands
2. Update `Kernel.php` schedule
3. Test each command manually
4. Verify scheduler sees commands

**Rollback plan:**
```bash
git checkout app/Console/
```

---

### Step 7: Frontend Layer (Days 8-9)

**No migration needed - all new files:**

1. Create 3 Vue components
2. Update router
3. Update navigation
4. Test in browser

**Rollback plan:**
```bash
git checkout resources/js/
npm run build
```

---

### Step 8: Testing Layer (Day 10)

**No migration needed - all new files:**

1. Create unit tests
2. Create integration tests
3. Run manual tests
4. Verify coverage >80%

**Rollback plan:**
```bash
git checkout tests/
```

---

## Backward Compatibility

### Database Schema

**✅ Safe migrations:**
- ALTER TABLE add columns with DEFAULT values (no NULL errors)
- CREATE new tables (doesn't affect existing data)
- RENAME column (PostgreSQL handles gracefully)

**⚠️ Risky migrations:**
- DROP column (data loss)
- CHANGE column type (may fail if data incompatible)
- ADD NOT NULL column without DEFAULT (fails if table has data)

**Strategy:**
- Always use ALTER, never DROP/CREATE
- Add columns with DEFAULT values or NULL
- Test rollback before production

---

### API Endpoints

**✅ Backward compatible:**
- Keep old route `/api/webhooks/moysklad` working during transition
- Add new route `/api/webhooks/receive`
- МойСклад can call either endpoint

**Migration path:**
1. Deploy new code with both endpoints
2. Update МойСклад webhooks to new endpoint
3. Monitor for 24 hours
4. Remove old endpoint

**Rollback:** Revert МойСклад webhook URL to old endpoint

---

### Service Layer

**✅ Backward compatible:**
- New services don't break existing code
- Old service can coexist with new services during migration

**Migration path:**
1. Create new services alongside old service
2. Update code to use new services gradually
3. Delete old service when no longer referenced

---

## Data Migration

### No data migration needed! ✅

**Reason:** Webhook system starts fresh. No historical data to migrate.

**Process:**
1. Install new tables (empty)
2. Install webhooks via command: `php artisan webhooks:setup`
3. New webhooks start logging to webhook_logs table
4. Old data in other tables unaffected

---

## Testing Migration

### Pre-Deployment Testing

**Test on local/staging BEFORE production:**

1. **Database migration test:**
   ```bash
   # Staging
   ssh staging
   cd /path/to/app
   php artisan migrate --pretend
   php artisan migrate
   php artisan migrate:rollback --step=5
   php artisan migrate
   ```

2. **Service integration test:**
   ```bash
   php artisan tinker
   $service = app(\App\Services\Webhook\WebhookReceiverService::class);
   $service->validate(['events' => [...]]);
   ```

3. **Endpoint test:**
   ```bash
   curl -X POST http://staging.app.cavaleria.ru/api/webhooks/receive \
     -H "Content-Type: application/json" \
     -d @webhook-test-payload.json
   ```

4. **Queue test:**
   ```bash
   php artisan tinker
   \App\Jobs\ProcessWebhookJob::dispatch(1)
   # Monitor logs
   ```

5. **Frontend test:**
   - Open browser to staging
   - Test all components load
   - Test API calls work
   - Test navigation

---

### Post-Deployment Validation

**After deploying to production:**

1. **Immediate checks (first 5 minutes):**
   ```bash
   # Check migrations ran
   php artisan migrate:status

   # Check tables exist
   psql -c "\dt webhook*"

   # Check queue worker running
   ./monitor-queue.sh

   # Check no errors in logs
   tail -100 storage/logs/laravel.log
   ```

2. **First hour checks:**
   - Install webhooks on 1 test account
   - Trigger test webhook (update product in МойСклад)
   - Verify webhook received in webhook_logs
   - Verify sync task created in sync_queue
   - Verify task processed successfully

3. **First 24 hours checks:**
   - Run `php artisan webhooks:check` every hour
   - Monitor webhook_logs table size
   - Check failure rate <5%
   - Verify no infinite loops (same entity not repeating)

4. **First week checks:**
   - Daily health reports
   - Gradual rollout to more accounts
   - User feedback collection

---

## Rollback Procedures

### Emergency Rollback (Critical Issues)

**If webhooks cause system outage or data corruption:**

```bash
# 1. IMMEDIATELY disable all webhooks
php artisan down --message="Emergency maintenance"
php artisan webhooks:delete-all --confirm

# 2. Stop queue worker
sudo supervisorctl stop laravel-worker:*

# 3. Rollback database (if needed)
php artisan migrate:rollback --step=5

# 4. Rollback code
git revert HEAD~5..HEAD  # Revert last 5 commits
./deploy.sh

# 5. Restart queue
sudo supervisorctl start laravel-worker:*

# 6. Resume manual sync
UPDATE sync_settings SET webhooks_enabled = false;
php artisan up

# 7. Investigate issue
tail -1000 storage/logs/laravel.log | grep ERROR
```

**Time to rollback:** ~5-10 minutes

---

### Partial Rollback (Non-Critical Issues)

**If specific feature has issues but system overall works:**

```bash
# Option 1: Disable webhooks for problematic account
php artisan webhooks:delete-all --account=problem-account-id

# Option 2: Disable specific webhook type
# (requires manual deletion via МойСклад API or UI)

# Option 3: Pause webhook processing
# Stop queue worker temporarily
sudo supervisorctl stop laravel-worker:*
# Fix issue
# Resume
sudo supervisorctl start laravel-worker:*
```

**Time to rollback:** ~1-2 minutes per account

---

### Data Recovery

**If data corruption occurred:**

```bash
# 1. Stop all operations
php artisan down
php artisan webhooks:delete-all --confirm
sudo supervisorctl stop laravel-worker:*

# 2. Restore database from backup
pg_restore -d multiaccount backup_YYYYMMDD.sql

# 3. Verify data integrity
# Check critical tables
SELECT COUNT(*) FROM sync_queue WHERE status = 'processing';
# Should be 0 if queue stopped

# 4. Resume operations
php artisan up
sudo supervisorctl start laravel-worker:*
```

**Time to recover:** 10-30 minutes (depends on database size)

---

## Common Migration Issues

### Issue 1: Migration fails due to existing column

**Error:**
```
SQLSTATE[42701]: Duplicate column: 7 ERROR: column "account_type" of relation "webhooks" already exists
```

**Cause:** Column was added manually or by previous migration attempt

**Solution:**
```php
// In migration up() method:
if (!Schema::hasColumn('webhooks', 'account_type')) {
    $table->string('account_type', 10)->after('account_id');
}
```

---

### Issue 2: Foreign key constraint fails

**Error:**
```
SQLSTATE[23503]: Foreign key violation: 7 ERROR: insert or update on table "webhook_logs" violates foreign key constraint
```

**Cause:** Referencing account_id that doesn't exist in accounts table

**Solution:**
```php
// Ensure account exists before creating webhook log
$account = Account::find($accountId);
if (!$account) {
    throw new \Exception("Account not found: {$accountId}");
}
```

---

### Issue 3: Namespace not found after renaming service

**Error:**
```
Class 'App\Services\WebhookService' not found
```

**Cause:** Old references not updated

**Solution:**
```bash
# Find all old references
grep -r "use App\\Services\\WebhookService" app/

# Replace with new namespace
# Old: use App\Services\WebhookService;
# New: use App\Services\Webhook\WebhookSetupService;
```

---

### Issue 4: Queue job fails with "Class not found"

**Error:**
```
Class 'App\Jobs\ProcessWebhookJob' not found
```

**Cause:** Queue worker running old code (Supervisor doesn't auto-reload)

**Solution:**
```bash
# Restart queue worker after deploy
./restart-queue.sh

# Or manually:
sudo supervisorctl restart laravel-worker:*
```

---

### Issue 5: Webhook endpoint returns 404 after deploy

**Error:**
```
404 Not Found - POST /api/webhooks/receive
```

**Cause:** Route cache not cleared

**Solution:**
```bash
php artisan route:cache
php artisan config:cache
php artisan view:cache
```

---

## Best Practices

### DO ✅

1. **Always backup before migration**
   - Database: `pg_dump`
   - Code: `git stash` or feature branch

2. **Test on staging first**
   - Full deployment rehearsal
   - 24 hours monitoring
   - Load testing

3. **Gradual rollout**
   - 1 account → 5 accounts → 20 accounts → all accounts
   - Monitor at each step
   - Rollback if issues

4. **Keep old code during transition**
   - Old controller as `.old.php`
   - Old route for 24-48 hours
   - Old service until all references updated

5. **Monitor closely after deployment**
   - First 5 minutes: critical checks
   - First hour: test account validation
   - First 24 hours: hourly health checks
   - First week: daily reports

6. **Document everything**
   - What was changed
   - Why it was changed
   - How to rollback
   - Known issues

---

### DON'T ❌

1. **Don't deploy to production without staging test**
   - Always test migration on staging first

2. **Don't drop tables or columns**
   - Use ALTER, not DROP
   - Keep old columns until migration complete

3. **Don't deploy during peak hours**
   - Deploy during low-traffic periods
   - Have team available for monitoring

4. **Don't enable all webhooks at once**
   - Gradual rollout reduces risk
   - Easier to identify issues

5. **Don't skip backups**
   - Database backup is mandatory
   - Code backup via git

6. **Don't ignore warnings**
   - Any ERROR in logs requires investigation
   - High failure rate requires rollback

---

## Summary

**Migration Complexity:** Medium

**Time Required:** 14 days (80-100 hours)

**Risk Level:** Medium (mitigated by gradual rollout)

**Rollback Capability:** High (can rollback in 5-10 minutes)

**Success Factors:**
- ✅ Thorough staging testing
- ✅ Gradual production rollout
- ✅ Close monitoring
- ✅ Quick rollback plan
- ✅ Clear documentation

**Ready to migrate?** Proceed to [19-webhook-tasks.md](19-webhook-tasks.md) for day-by-day implementation plan.
