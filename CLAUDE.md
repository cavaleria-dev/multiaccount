# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

МойСклад Franchise Management Application - Laravel 11 + Vue 3 application for managing franchise networks in МойСклад with automatic data synchronization between main and child accounts. Runs as an iframe application inside МойСклад interface.

**Stack:** PHP 8.4, Laravel 11, PostgreSQL 18, Redis 7, Vue 3, Tailwind CSS 3

## Development Environment

**IMPORTANT:** Local development machine does NOT have PHP environment installed. All PHP commands (migrations, artisan) must be executed on the production server.

## Development Commands

```bash
# Frontend development (local)
npm install                    # Install dependencies
npm run dev                    # Start Vite dev server with hot reload
npm run build                  # Production build

# Backend commands (ONLY on server via SSH or deploy.sh)
composer install               # Install PHP dependencies
php artisan migrate            # Run migrations
php artisan migrate:rollback   # Rollback last migration
php artisan cache:clear        # Clear application cache
php artisan config:clear       # Clear config cache

# Development (server only)
composer dev                   # Runs all services concurrently
# This starts: artisan serve, queue:listen, pail (logs), npm run dev

# Individual services (server only)
php artisan serve              # Backend server (localhost:8000)
php artisan queue:listen       # Process queue jobs (manual, for dev only)
php artisan pail               # Real-time logs

# Testing (server only)
composer test                  # Run PHPUnit tests
php artisan test               # Same as above

# Production commands (server only)
php artisan config:cache       # Cache config
php artisan route:cache        # Cache routes
php artisan view:cache         # Cache views

# Queue management (production - via Supervisor)
./setup-queue-worker.sh        # Setup queue worker with Supervisor (one-time)
./monitor-queue.sh             # Monitor queue status and logs
./restart-queue.sh             # Restart queue worker
sudo supervisorctl status laravel-worker:*    # Check worker status
sudo supervisorctl restart laravel-worker:*   # Restart worker

# Scheduler (production)
# Add to crontab: * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
```

## Deployment

**Primary method:** Use `deploy.sh` script for deployment.

```bash
# On server
cd /var/www/app.cavaleria.ru && ./deploy.sh
```

The deploy.sh script handles:
- Git pull from main branch
- composer install (production mode)
- npm install && npm run build
- Database migrations
- Cache clearing
- Service restarts

**Auto-deploy:** GitHub Actions automatically runs deploy.sh on push to `main` branch.

Required GitHub Secrets:
- `SERVER_HOST`
- `SERVER_USER`
- `SSH_PRIVATE_KEY`

## Queue System & Supervisor

### Why Supervisor?

**Supervisor** is a process control system for Unix-like operating systems. It's critical for production Laravel queue workers because:

1. **Automatic Restart**: If queue worker crashes or is killed, Supervisor automatically restarts it
2. **Boot Persistence**: Worker starts automatically when server reboots (no manual intervention)
3. **Process Management**: Easily start/stop/restart workers without finding PIDs
4. **Multiple Workers**: Run multiple worker processes for high-load scenarios
5. **Centralized Logging**: All worker output goes to single log file
6. **Graceful Shutdown**: Properly handles worker shutdown (waits for current job to finish)

**Without Supervisor**: You'd need to manually run `php artisan queue:work` in a screen/tmux session, and it would stop after server reboot or SSH disconnect.

**With Supervisor**: Set it up once, workers run forever with auto-restart on failure.

### Queue Architecture Flow

```
User clicks "Sync All" Button (Frontend)
    ↓
POST /api/sync/{accountId}/products/all (Controller)
    ↓
Create tasks in sync_queue table (status: pending, priority: 10)
    ↓
Cron runs every minute: php artisan schedule:run
    ↓
Scheduler dispatches ProcessSyncQueueJob to queue
    ↓
Supervisor-managed queue:work picks up job from queue
    ↓
ProcessSyncQueueJob::handle() - processes 50 tasks from sync_queue
    ↓
For each task: Call ProductSyncService->syncProduct()
    ↓
MoySkladService makes API calls with RateLimitHandler (45 req/sec control)
    ↓
Update task status: completed/failed
    ↓
Log to storage/logs/sync.log (detailed REQUEST/RESPONSE)
```

### Queue Configuration

**Current Setup** (`.env`):
```env
QUEUE_CONNECTION=database  # Uses sync_queue table in PostgreSQL
```

**Alternative Options**:
- `sync` - Synchronous (no queue, processes immediately) - for development only
- `database` - Uses database table - good for small/medium load
- `redis` - Uses Redis - best for high load (faster, more scalable)

**Why database queue for this app:**
- Moderate load (~1000 products per sync)
- Already have PostgreSQL running
- Easy debugging (can see tasks in DB)
- Rate limit control more important than raw speed

### Initial Setup (One-Time)

**Step 1: Install Supervisor**
```bash
# CentOS/RHEL
sudo yum install supervisor -y
sudo systemctl enable supervisord
sudo systemctl start supervisord

# Ubuntu/Debian
sudo apt-get install supervisor -y
sudo systemctl enable supervisor
sudo systemctl start supervisor
```

**Step 2: Configure Worker**
```bash
cd /var/www/app.cavaleria.ru
./setup-queue-worker.sh
```

This script:
1. Detects OS (CentOS → `/etc/supervisord.d/*.ini`, Ubuntu → `/etc/supervisor/conf.d/*.conf`)
2. Creates Supervisor config for laravel-worker
3. Reloads Supervisor configuration
4. Starts worker process
5. Shows status and management commands

**Generated config** (example):
```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/app.cavaleria.ru/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --timeout=300
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/app.cavaleria.ru/storage/logs/worker.log
stopwaitsecs=3600
```

**Key parameters:**
- `--sleep=3` - Sleep 3 seconds when no jobs available (reduces DB load)
- `--tries=3` - Retry failed jobs up to 3 times
- `--max-time=3600` - Worker runs for 1 hour then restarts (prevents memory leaks)
- `--timeout=300` - Kill job if it runs longer than 5 minutes
- `numprocs=1` - Run 1 worker process (increase for high load)

### Multiple Workers (High Load Scenario)

**When to use multiple workers:**
- Syncing 10,000+ products
- Multiple child accounts (3+) syncing simultaneously
- Need to process >100 jobs/minute

**Option 1: Multiple identical workers**
```ini
[program:laravel-worker]
numprocs=4  # Run 4 identical workers
process_name=%(program_name)s_%(process_num)02d
command=php /path/artisan queue:work --sleep=3 --tries=3
```

**Option 2: Priority-based queues**
```ini
# High priority worker (products, orders)
[program:laravel-worker-high]
command=php /path/artisan queue:work --queue=high --sleep=1 --tries=3
numprocs=2

# Low priority worker (images, metadata)
[program:laravel-worker-low]
command=php /path/artisan queue:work --queue=low --sleep=5 --tries=3
numprocs=1
```

Then when queueing jobs:
```php
// High priority (products, orders)
ProcessSyncQueueJob::dispatch()->onQueue('high');

// Low priority (images, folders)
ProcessSyncQueueJob::dispatch()->onQueue('low');
```

**Option 3: Per-account workers**
```ini
# Main account workers (high load)
[program:laravel-worker-main]
command=php /path/artisan queue:work --queue=account-main-uuid --sleep=2
numprocs=3

# Child accounts (moderate load)
[program:laravel-worker-child]
command=php /path/artisan queue:work --queue=account-child-* --sleep=3
numprocs=1
```

### Daily Management

**Check worker status:**
```bash
sudo supervisorctl status laravel-worker:*
# Output: laravel-worker:laravel-worker_00   RUNNING   pid 12345, uptime 2 days, 3:45:12
```

**Restart worker** (after code deploy):
```bash
sudo supervisorctl restart laravel-worker:*
# Or use shortcut:
./restart-queue.sh
```

**Stop worker** (for maintenance):
```bash
sudo supervisorctl stop laravel-worker:*
```

**Start worker**:
```bash
sudo supervisorctl start laravel-worker:*
```

**Monitor queue**:
```bash
./monitor-queue.sh
# Shows: pending, processing, completed, failed counts + recent logs
```

**View worker logs**:
```bash
tail -f storage/logs/worker.log          # Worker output
tail -f storage/logs/laravel.log         # Application logs
tail -f storage/logs/sync.log            # Detailed sync logs (REQUEST/RESPONSE)
```

### Debugging Common Issues

**Issue: Worker not processing jobs**
```bash
# 1. Check worker is running
sudo supervisorctl status laravel-worker:*

# 2. Check for errors in logs
tail -30 storage/logs/worker.log

# 3. Check tasks in queue
php artisan tinker
>>> \DB::table('sync_queue')->where('status', 'pending')->count();

# 4. Try running worker manually (see what happens)
php artisan queue:work --once

# 5. Check cron is running scheduler
grep CRON /var/log/syslog  # Ubuntu
journalctl -u crond        # CentOS
```

**Issue: Jobs failing repeatedly**
```bash
# Check failed jobs
php artisan tinker
>>> \DB::table('sync_queue')->where('status', 'failed')->orderBy('updated_at', 'desc')->take(5)->get();

# Check error messages
tail -50 storage/logs/sync.log | grep ERROR

# Requeue failed jobs
php artisan queue:retry all
```

**Issue: Worker stuck/frozen**
```bash
# Hard restart
sudo supervisorctl restart laravel-worker:*

# If still stuck, kill and restart
sudo supervisorctl stop laravel-worker:*
sudo pkill -9 -f "queue:work"
sudo supervisorctl start laravel-worker:*
```

**Issue: High memory usage**
```bash
# Reduce max-time to restart worker more often
# Edit Supervisor config:
command=php /path/artisan queue:work --max-time=1800  # 30 minutes instead of 1 hour

# Then reload
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart laravel-worker:*
```

### Rate Limiting & МойСклад API

**Why queue is critical for МойСклад:**
- API limit: 45 requests/second burst, lower sustained rate
- Exceeding limit → 429 error → temporary ban
- Queue allows controlled request rate via `RateLimitHandler`

**How rate limiting works:**
1. `MoySkladService` uses `RateLimitHandler` for all API calls
2. Handler tracks requests per second
3. If approaching limit (45 req/sec), adds delay
4. On 429 response, exponential backoff (1s → 2s → 4s → 8s)
5. Queue workers process steadily without overwhelming API

**Without queue (webhook immediate sync):**
- Webhook triggers → Immediate API call
- 10 webhooks in 1 second → 10 API calls → Potential rate limit

**With queue (batch sync):**
- Create 1000 tasks → Queue processes 50/minute
- Controlled rate → Never exceeds API limits

### Helper Scripts

**setup-queue-worker.sh** - Initial setup
- Auto-detects OS (CentOS/Ubuntu)
- Creates Supervisor config
- Starts worker
- Shows management commands

**monitor-queue.sh** - Monitor queue status
- Shows pending/processing/completed/failed counts
- Shows recent worker logs
- Shows recent application logs

**restart-queue.sh** - Quick restart
- Restarts worker via Supervisor
- Useful after code deployment

All scripts are in project root. Run `./script-name.sh` on server.

## Creating Migrations

**IMPORTANT:** When creating new migrations, write them manually in `database/migrations/` directory.

Migration naming convention: `YYYY_MM_DD_HHMMSS_description.php`

Example:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_name', function (Blueprint $table) {
            if (!Schema::hasColumn('table_name', 'new_column')) {
                $table->string('new_column')->nullable()->after('existing_column');
            }
        });
    }

    public function down(): void
    {
        Schema::table('table_name', function (Blueprint $table) {
            if (Schema::hasColumn('table_name', 'new_column')) {
                $table->dropColumn('new_column');
            }
        });
    }
};
```

**Best practices:**
- Always check if column/table exists with `Schema::hasColumn()` / `Schema::hasTable()`
- Use `->after('column')` to specify position
- Always implement `down()` method for rollback
- Migrations run automatically during deployment via deploy.sh
```

## Architecture Overview

### МойСклад Integration Context

This app integrates with МойСклад (Russian inventory management system) using three APIs:

1. **Vendor API (JWT-based)**: App lifecycle (install/uninstall), context retrieval
2. **JSON API 1.2 (Bearer token)**: CRUD operations on entities (products, orders, etc.)
3. **Webhook API**: Real-time event notifications

**Critical Flow:**
1. User opens app iframe → МойСклад provides `contextKey` in URL
2. Frontend calls `/api/context` with contextKey + appUid
3. Backend generates JWT, calls Vendor API to get full context (accountId, userId, permissions)
4. **Context cached for 30min** with key `moysklad_context:{contextKey}`
5. **contextKey stored in sessionStorage**
6. All subsequent API calls include `X-MoySklad-Context-Key` header
7. Middleware `MoySkladContext` validates context from cache

### Synchronization Architecture

**Main Account → Child Accounts (Products):**
- Products, variants, bundles, services, custom entities
- Product folders (groups) - рекурсивное создание иерархии
- Attributes, characteristics, prices, barcodes, packages
- **Queued sync via `sync_queue` table** (ProcessSyncQueueJob)
- **Deletion/archiving**: Archived in children (NOT deleted) when deleted in main
- **New features:**
  - Price mappings (main ↔ child)
  - Attribute filtering (sync only selected attributes)
  - Product match field (code/article/externalCode/barcode)
  - Optional product folder creation
  - Visual filter constructor for selective sync

**Queue Flow Details:**
1. User clicks "Sync All" or webhook triggers
2. Controller creates tasks in `sync_queue` (status: pending, priority: 1-10)
3. Laravel Scheduler runs `ProcessSyncQueueJob` every minute
4. Job fetches 50 pending tasks (ordered by priority DESC, scheduled_at ASC)
5. For each task:
   - Call appropriate service (ProductSyncService, ServiceSyncService, etc.)
   - Update status to 'processing'
   - Execute sync with МойСклад API (via RateLimitHandler)
   - Update status to 'completed' or 'failed'
   - Increment attempts counter on failure
6. Failed tasks (attempts < 3) stay in queue for retry
7. Failed tasks (attempts >= 3) marked as 'failed' permanently

**Priority Levels:**
- Priority 10: Manual "Sync All" (user-initiated)
- Priority 5: Webhook updates (real-time changes)
- Priority 1: Background tasks (images, metadata)

**Why queue for products:**
- Large catalogs (1000+ products) can't sync instantly
- МойСклад rate limits (45 req/sec) require controlled processing
- Allows retry on temporary failures (network, API timeouts)
- User doesn't wait - sync happens in background
- Can monitor progress via Dashboard statistics

**Child Accounts → Main Account (Orders):**
- customerorder → customerorder
- retaildemand → customerorder
- purchaseorder → customerorder (проведенные only)
- **Immediate sync WITHOUT queue** (small volume, time-sensitive)

**Why NO queue for orders:**
- Low volume (few orders per day)
- Time-sensitive (need to appear immediately)
- Simple 1:1 mapping (no complex transformations)
- Failure rate low (orders already validated by МойСклад)

### Service Layer

**Core Services** (`app/Services/`):
- `MoySkladService` - Low-level API client, rate limit handling
- `VendorApiService` - JWT generation, context retrieval
- `ProductSyncService` - Products, variants, bundles sync + product folders
- `ServiceSyncService` - Services sync (NEW)
- `CustomerOrderSyncService` - Customer orders sync
- `RetailDemandSyncService` - Retail sales sync
- `PurchaseOrderSyncService` - Purchase orders sync (проведенные only)
- `CounterpartySyncService` - Counterparty management
- `CustomEntitySyncService` - Custom entity sync
- `BatchSyncService` - Batch sync with queues
- `WebhookService` - Webhook management
- `ProductFilterService` - Apply visual filters to products
- `RateLimitHandler` - API rate limit handling (45 req/sec burst, exponential backoff)

**Key Jobs:**
- `ProcessSyncQueueJob` - Runs every minute via scheduler, processes 50 tasks per batch

### Frontend Architecture

**Vue 3 Composition API** - Options API is NOT used

**Key Composables** (`resources/js/composables/`):
- `useMoyskladContext.js` - Context management, loads from URL params, saves to sessionStorage
- `useMoyskladEntities.js` - Universal loader for МойСклад entities with caching
  * Supports 10 entity types: organizations, stores, projects, employees, salesChannels, customerOrderStates, purchaseOrderStates, attributes, folders, priceTypes
  * Auto-caching with `loaded` flag
  * Methods: `load(force)`, `reload()`, `clear()`, `addItem()`, `addChildPriceType()`
  * Reduces code duplication (~300 lines saved across components)
- `useTargetObjectsMetadata.js` - Metadata management for target objects
  * Auto-watches 10 settings fields (target_organization_id, target_store_id, etc.)
  * Methods: `updateMetadata()`, `clearMetadata()`, `initializeMetadata()`, `getMetadata()`
  * Replaces 9 identical watch handlers (~50 lines saved)

**Pages** (`resources/js/pages/`):
- `Dashboard.vue` - Statistics overview
- `ChildAccounts.vue` - Franchise management, add by account name
- `GeneralSettings.vue` - App-wide settings (account type: main/child)
- `FranchiseSettings.vue` - Per-franchise sync settings

**API Client** (`resources/js/api/index.js`):
- Axios instance with interceptor that auto-adds `X-MoySklad-Context-Key` from sessionStorage

**Component Architecture:**

Settings pages use modular component structure for maintainability:

`FranchiseSettings.vue` (983 lines) - Main settings page, composed of:
- `ProductSyncSection.vue` (131 lines) - Product sync checkboxes + advanced settings
- `PriceMappingsSection.vue` (254 lines) - Price type mappings + attribute selection
- `ProductFiltersSection.vue` (77 lines) - Product filters toggle + ProductFilterBuilder
- `DocumentSyncSection.vue` (356 lines) - Document sync options + target objects
- `AutoCreateSection.vue` (72 lines) - Auto-creation settings

**Component pattern:** "Dumb" components that only render UI and emit events. All business logic remains in parent component (FranchiseSettings.vue). This approach:
- Reduces main file size by 32% (1454 → 983 lines)
- Improves code organization and readability
- Maintains single source of truth for data and logic
- Easy to add/remove/reorder sections

**Reusable UI Components:**

`SimpleSelect.vue` - Custom select dropdown with loading state support:
- Props: `modelValue`, `label`, `placeholder`, `options`, `disabled`, `required`, `loading`
- **Loading prop**: Shows animated spinner instead of dropdown arrow when `loading=true`
- Features: Clear button, dropdown animation, click-outside handling
- Loading state: Disables clear button, shows indigo spinner (4x4px)
- Usage example:
  ```vue
  <SimpleSelect
    v-model="selectedId"
    :options="items"
    :loading="isLoadingItems"
    placeholder="Выберите значение"
  />
  ```

`SearchableSelect.vue` - Advanced select with search functionality
`ProductFilterBuilder.vue` - Visual filter constructor for product filtering
`ProductFolderPicker.vue` - Hierarchical folder tree picker

### Database Structure

**Critical Tables:**

`accounts` - Installed apps (account_id UUID PK, access_token, account_type: main/child, status: activated/suspended/uninstalled)

`child_accounts` - Parent-child links (parent_account_id, child_account_id, invitation_code, status)

`sync_settings` - Per-account sync config (35+ fields: sync_enabled, sync_products, sync_services, sync_orders, sync_images, product_match_field, create_product_folders, price_mappings JSON, attribute_sync_list JSON, counterparty IDs, priorities, delays)

`sync_queue` - Task queue (entity_type, entity_id, operation: create/update/delete, priority, scheduled_at, status: pending/processing/completed/failed, attempts, error_message)

`entity_mappings` - Cross-account entity mapping (parent_account_id, child_account_id, parent_entity_id UUID, child_entity_id UUID, entity_type: product/variant/bundle/service/productfolder/customerorder, sync_direction: main_to_child/child_to_main, match_field, match_value)

`webhook_health` - Webhook monitoring (account_id, webhook_id, entity_type, is_active, last_check_at, check_attempts, error_message)

`sync_statistics` - Daily stats (parent_account_id, child_account_id, date, products_synced, products_failed, orders_synced, orders_failed, sync_duration_avg, api_calls_count, last_sync_at) - unique per (parent, child, date)

**Mapping Tables:**
- `attribute_mappings` - Attribute (additional fields) mapping
- `characteristic_mappings` - Variant characteristics mapping
- `price_type_mappings` - Price type mapping
- `custom_entity_mappings` - Custom entity metadata mapping
- `custom_entity_element_mappings` - Custom entity elements mapping
- `standard_entity_mappings` - Standard МойСклад references mapping (uom, currency, country, vat) by code/isoCode

### Standard Entity Mapping

**Problem:** МойСклад standard references (uom/currency/country/vat) have **different UUIDs in each account**, but same **code/isoCode** values.

**Example:**
```
Main account:  uom "шт" (pieces) = UUID: 19f1edc0-fc42-4001-94cb-c9ec9c62ec10, code: "796"
Child account: uom "шт" (pieces) = UUID: 8f2a3d50-bc21-5002-85dc-d0fd0d73fd21, code: "796"
                                    ↑ DIFFERENT UUID!           ↑ SAME code!
```

**Solution:** `standard_entity_mappings` table maps by code/isoCode instead of UUID.

**Table structure:**
```sql
standard_entity_mappings:
- parent_account_id (UUID) - Main account
- child_account_id (UUID) - Child account
- entity_type (string) - 'uom', 'currency', 'country', 'vat'
- parent_entity_id (string) - UUID in main account
- child_entity_id (string) - UUID in child account
- code (string) - Matching code (e.g., "796" for uom, "RUB" for currency)
- name (string) - Human-readable name for debugging
- metadata (json) - Additional data (rate for vat, symbol for currency)
- UNIQUE(parent_account_id, child_account_id, entity_type, code)
```

**Mapping strategies by entity type:**

1. **uom (единицы измерения)** - by `code`:
   - Standard: "796" (шт), "166" (г), "163" (кг), "112" (л), etc.
   - Custom: User-created units also have codes
   - If not found in child → create custom uom

2. **currency (валюты)** - by `isoCode`:
   - "RUB" (Российский рубль)
   - "USD" (US Dollar)
   - "EUR" (Euro)
   - Always exist in all accounts (can't create custom)

3. **country (страны)** - by `code`:
   - "643" (Россия)
   - "840" (США)
   - "276" (Германия)
   - Always exist in all accounts (can't create custom)

4. **vat (ставки НДС)** - by `rate`:
   - 20 (20%)
   - 10 (10%)
   - 0 (0%)
   - null (Без НДС)
   - Stored as integer in metadata

**Why this is critical:**
- Without mapping → API error: "Entity with UUID xxx not found"
- Can't copy UUID from main → child (different UUIDs!)
- Must find corresponding entity by code/isoCode

### JWT Generation for МойСклад Vendor API

**CRITICAL:** Must use `JSON_UNESCAPED_SLASHES` flag when encoding!

```php
$header = ['alg' => 'HS256', 'typ' => 'JWT'];
$payload = [
    'sub' => $appUid,
    'iat' => time(),
    'exp' => time() + 60,
    'jti' => bin2hex(random_bytes(12))
];

$headerEncoded = base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
$payloadEncoded = base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
$signature = base64UrlEncode(hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secretKey, true));
$jwt = "$headerEncoded.$payloadEncoded.$signature";
```

### Webhook Flow

Webhooks handled in `WebhookController`:

1. МойСклад sends POST to `/api/webhooks/moysklad`
2. Parse `auditContext` to get accountId, entityType, action (CREATE/UPDATE/DELETE)
3. Route to appropriate service based on entity type
4. For products: Queue task in `sync_queue` with priority
5. For orders: Immediate sync without queue
6. For purchaseorder: Only sync if `applicable=true` (проведенные)

**Important:** TariffChanged event does NOT include access_token - must fetch from DB.

## Coding Standards

### PHP/Laravel

1. **PSR-12** formatting
2. **Strict typing required:**
   ```php
   public function getContext(Request $request): JsonResponse
   ```
3. **Always log operations:**
   ```php
   Log::info('Operation completed', ['data' => $data]);
   ```
4. **Try-catch mandatory:**
   ```php
   try {
       // code
       Log::info('Success');
   } catch (\Exception $e) {
       Log::error('Error', ['error' => $e->getMessage()]);
       return response()->json(['error' => 'Message'], 500);
   }
   ```
5. **Service Layer Pattern** - Business logic in `app/Services/`, controllers only for HTTP handling
6. **Never use raw SQL** - Use Eloquent or Query Builder

### Vue 3

1. **Composition API only** - No Options API

2. **Component Structure Order:**
   ```vue
   <template>
     <!-- Template always first -->
   </template>

   <script setup>
   // 1. Imports (grouped: Vue → Router → API → Composables → Components)
   import { ref, computed, watch, onMounted } from 'vue'
   import { useRouter } from 'vue-router'
   import api from '@/api'
   import { useMoyskladEntities } from '@/composables/useMoyskladEntities'
   import MyComponent from '@/components/MyComponent.vue'

   // 2. Props
   const props = defineProps({
     accountId: { type: String, required: true }
   })

   // 3. Emits
   const emit = defineEmits(['update:settings'])

   // 4. Composables
   const router = useRouter()
   const loader = useMoyskladEntities(props.accountId, 'organizations')

   // 5. Reactive state
   const data = ref(null)
   const loading = ref(false)
   const error = ref(null)

   // 6. Computed properties
   const isValid = computed(() => data.value !== null)

   // 7. Watchers
   watch(() => props.accountId, loadData)

   // 8. Methods
   const loadData = async () => { /* ... */ }

   // 9. Lifecycle hooks
   onMounted(loadData)
   </script>
   ```

3. **Naming Conventions:**
   - Components: PascalCase (`MyComponent.vue`)
   - Composables: camelCase with `use` prefix (`useMoyskladEntities.js`)
   - Props: camelCase (`accountId`, `isLoading`)
   - Events: kebab-case (`update:settings`, `save-complete`)
   - Variables/functions: camelCase (`loadData`, `isValid`)
   - Constants: SCREAMING_SNAKE_CASE (`API_URL`, `MAX_RETRIES`)

4. **Reactive State Management:**
   ```javascript
   // ✅ CORRECT: Use ref for all values
   const count = ref(0)
   const user = ref({ name: 'John' })
   const items = ref([])

   // ✅ Access with .value in script
   count.value++
   console.log(user.value.name)

   // ✅ No .value in template
   <template>{{ count }}</template>

   // ❌ AVOID: reactive() for simple values
   const state = reactive({ count: 0 }) // Use ref(0) instead
   ```

5. **Error Handling:**
   ```javascript
   // ✅ ALWAYS handle loading, error, and success states
   const loading = ref(false)
   const error = ref(null)

   const loadData = async () => {
     try {
       loading.value = true
       error.value = null
       const response = await api.get('/data')
       data.value = response.data
     } catch (err) {
       console.error('Failed:', err)
       error.value = 'Не удалось загрузить данные'
     } finally {
       loading.value = false
     }
   }
   ```

6. **Component Communication:**
   ```javascript
   // ✅ Props down, events up
   // Parent passes data
   <ChildComponent :data="myData" @update="handleUpdate" />

   // Child emits events (never mutate props)
   const emit = defineEmits(['update'])
   emit('update', newValue)

   // ✅ Use v-model for two-way binding
   const props = defineProps({ modelValue: String })
   const emit = defineEmits(['update:modelValue'])
   emit('update:modelValue', newValue)
   ```

7. **Composable Patterns:**
   ```javascript
   // ✅ Return reactive refs and methods
   export function useMyFeature() {
     const data = ref(null)
     const loading = ref(false)

     const load = async () => { /* ... */ }

     return { data, loading, load }
   }
   ```

8. **Performance Optimization:**
   ```javascript
   // ✅ Use computed for derived state (cached)
   const fullName = computed(() => `${first.value} ${last.value}`)

   // ✅ Cache API calls in composables
   if (data.value.length > 0) return // Already loaded

   // ✅ Watch specific properties, not whole objects
   watch(() => obj.value.prop, callback) // Better than deep watch
   ```

### Code Organization Principles

**DRY (Don't Repeat Yourself):**
- Logic used in 2+ places → extract to composable
- UI pattern repeats 3+ times → create component
- API call repeats → add to `api/index.js`

**Component Design Patterns:**
```javascript
// ✅ "Dumb" Presentational Component:
// - Only renders UI
// - Emits events for interactions
// - No API calls or business logic
// - All data via props

// ✅ "Smart" Container Component:
// - Loads data via composables/API
// - Manages state and logic
// - Passes data to dumb components
```

**File Organization:**
```
resources/js/
├── api/index.js                    # API client
├── components/
│   ├── ProductCard.vue             # Reusable components
│   └── franchise-settings/         # Feature-specific
│       └── ProductSyncSection.vue
├── composables/
│   └── useMoyskladEntities.js      # Reusable logic
├── pages/
│   └── FranchiseSettings.vue       # Route pages
└── router/index.js                 # Routes
```

### Common Anti-Patterns to Avoid

**❌ DON'T:**
```javascript
// ❌ Mutate props directly
props.value = newValue

// ❌ Use reactive() for simple values
const count = reactive({ value: 0 }) // Use ref(0)

// ❌ Put business logic in template
<div v-if="items.filter(i => i.active).length > 0">

// ❌ Skip error handling
const data = await api.get() // What if it fails?

// ❌ Access .value in template
<div>{{ count.value }}</div> // Wrong!

// ❌ Copy-paste logic across components
// Extract to composable instead
```

**✅ DO:**
```javascript
// ✅ Emit events to update props
emit('update:modelValue', newValue)

// ✅ Use computed for derived state
const active = computed(() => items.value.filter(i => i.active))

// ✅ Always handle errors
try { /* ... */ } catch (err) { error.value = err }

// ✅ No .value in template
<div>{{ count }}</div>

// ✅ Use composables for shared logic
const { data, load } = useMoyskladEntities(id, 'type')
```

### Tailwind CSS

1. **Utility classes only** - No custom CSS
2. **Color scheme:**
   - Primary: `indigo-500` to `indigo-700`
   - Secondary: `purple-500` to `purple-600`
   - Gradients: `bg-gradient-to-r from-indigo-500 to-purple-600`
3. **Always add transitions** for hover states

## Adding New Features

### Backend Feature

1. Create migration if needed: Manually create file in `database/migrations/` (see "Creating Migrations" section)
2. Create/update model in `app/Models/`
3. Create service in `app/Services/` for business logic
4. Create controller in `app/Http/Controllers/Api/`
5. Add route in `routes/api.php`
6. Add comprehensive logging
7. Wrap in try-catch with error handling
8. Test locally with frontend, deploy via deploy.sh to run migrations

### Frontend Feature

1. Create component/page in `resources/js/pages/`
2. Add route in `resources/js/router/index.js`
3. Create composable if needed in `resources/js/composables/`
4. Style with Tailwind
5. Add loading and error state handling

### Adding Franchise (Example Flow)

User enters account name → Backend finds account by `account_name` → Checks:
- App installed (status='activated')
- Not adding self
- Not already connected to current main
- Not connected to another main

If valid → Creates row in `child_accounts` → Creates default `sync_settings`

## API Endpoints

### Sync Settings - Extended

**GET** `/api/sync-settings/{accountId}/price-types`
- Получить типы цен из main и child аккаунтов
- **МойСклад API endpoint**: `GET context/companysettings` (возвращает все настройки компании)
- Структура ответа МойСклад: `{meta, currency, priceTypes: [{id, name, externalCode}], ...}`
- Возвращает: `{main: [{id, name}], child: [{id, name}]}`

**GET** `/api/sync-settings/{accountId}/attributes`
- Получить все доп.поля из main аккаунта
- Возвращает: `{data: [{id, name, type}]}`

**GET** `/api/sync-settings/{accountId}/folders`
- Получить дерево групп товаров из main аккаунта
- Возвращает иерархическую структуру папок

**GET** `/api/sync-settings/{accountId}/batch` ⭐ **NEW - Batch Loading**
- Batch load initial data for settings page (optimization)
- Returns in single request:
  1. `settings` - Sync settings object
  2. `accountName` - Child account name
  3. `priceTypes` - { main: [...], child: [...] } with buyPrice prepended
  4. `attributes` - [{id, name, type}]
  5. `folders` - Hierarchical folder tree
- **Performance:** 4-5 API calls → 1 API call (3-4x faster page load)
- Graceful degradation: if one resource fails, others still load
- Returns: `{data: {settings, accountName, priceTypes, attributes, folders}}`

### Sync Actions

**POST** `/api/sync/{accountId}/products/all`
- Запустить синхронизацию всей номенклатуры
- Обрабатывает постранично (по 1000 товаров)
- Применяет фильтры из настроек
- Создаёт задачи в `sync_queue` с приоритетом 10
- Возвращает: `{tasks_created, status, message}`

**ВАЖНО:** Постраничная обработка критична для больших каталогов (10000+ товаров).
МойСклад API лимиты: max 1000 без expand, 100 с expand.

## Rate Limiting

МойСклад API limits: 45 requests/sec burst, sustained rate lower

`RateLimitHandler` tracks:
- Requests per second
- `X-Lognex-Reset` header for rate limit reset time
- Exponential backoff on 429 responses
- Automatic retry with delays

## Common Patterns

### Checking Context in Controller

```php
public function index(Request $request)
{
    $contextData = $request->get('moysklad_context');
    if (!$contextData || !isset($contextData['accountId'])) {
        return response()->json(['error' => 'Account context not found'], 400);
    }
    $mainAccountId = $contextData['accountId'];
    // ... rest of logic
}
```

### Frontend API Call with Context

```javascript
// contextKey automatically added by interceptor in api/index.js
const response = await api.childAccounts.list()
```

### Queueing Sync Task

**Basic queueing** (used in controllers/webhooks):
```php
use App\Models\SyncQueue;

SyncQueue::create([
    'account_id' => $accountId,
    'entity_type' => 'product',
    'entity_id' => $productId,
    'operation' => 'update',
    'priority' => 5,
    'scheduled_at' => now()->addSeconds(10), // Delay if needed
    'status' => 'pending',
    'attempts' => 0,
    'payload' => [
        'main_account_id' => $mainAccountId  // IMPORTANT: Required for processing
    ]
]);
```

**Batch queueing** (used in "Sync All" feature):
```php
// In SyncActionsController::syncAllProducts
$tasks = [];
foreach ($products as $product) {
    $tasks[] = [
        'account_id' => $accountId,
        'entity_type' => 'product',
        'entity_id' => $product['id'],
        'operation' => 'create',
        'priority' => 10,  // High priority for user-initiated sync
        'scheduled_at' => now(),
        'status' => 'pending',
        'attempts' => 0,
        'payload' => json_encode(['main_account_id' => $mainAccountId]),
        'created_at' => now(),
        'updated_at' => now()
    ];
}

// Bulk insert for performance (1000 products in 1 query instead of 1000 queries)
SyncQueue::insert($tasks);

Log::info("Created {count($tasks)} sync tasks", [
    'account_id' => $accountId,
    'entity_type' => 'product'
]);
```

**Checking queue status** (for dashboard/monitoring):
```php
// Get counts by status
$stats = SyncQueue::where('account_id', $accountId)
    ->selectRaw('status, count(*) as count')
    ->groupBy('status')
    ->pluck('count', 'status');

$pending = $stats['pending'] ?? 0;
$processing = $stats['processing'] ?? 0;
$completed = $stats['completed'] ?? 0;
$failed = $stats['failed'] ?? 0;

// Get recent failed tasks with errors
$failedTasks = SyncQueue::where('account_id', $accountId)
    ->where('status', 'failed')
    ->orderBy('updated_at', 'desc')
    ->limit(10)
    ->get(['entity_type', 'entity_id', 'error_message', 'attempts', 'updated_at']);
```

**Processing queue** (in ProcessSyncQueueJob):
```php
// Fetch tasks (priority DESC, scheduled_at ASC)
$tasks = SyncQueue::where('status', 'pending')
    ->where('scheduled_at', '<=', now())
    ->where('attempts', '<', 3)  // Skip tasks that failed 3 times
    ->orderByDesc('priority')
    ->orderBy('scheduled_at')
    ->limit(50)
    ->get();

foreach ($tasks as $task) {
    try {
        // Mark as processing
        $task->update(['status' => 'processing']);

        // Get payload (always check it exists!)
        $payload = $task->payload ?? [];
        if (empty($payload) || !isset($payload['main_account_id'])) {
            throw new \Exception('Invalid payload: missing main_account_id');
        }

        // Call appropriate service
        match($task->entity_type) {
            'product' => $this->productSyncService->syncProduct($task, $payload),
            'service' => $this->serviceSyncService->syncService($task, $payload),
            'bundle' => $this->productSyncService->syncBundle($task, $payload),
            default => throw new \Exception("Unknown entity type: {$task->entity_type}")
        };

        // Mark as completed
        $task->update(['status' => 'completed']);

    } catch (\Throwable $e) {
        // Increment attempts, save error
        $task->increment('attempts');
        $task->update([
            'status' => $task->attempts >= 3 ? 'failed' : 'pending',
            'error_message' => substr($e->getMessage(), 0, 500)
        ]);

        Log::error('Sync task failed', [
            'task_id' => $task->id,
            'entity_type' => $task->entity_type,
            'attempts' => $task->attempts,
            'error' => $e->getMessage()
        ]);
    }
}
```

**Important notes:**
- Always include `payload['main_account_id']` when creating tasks
- Use bulk insert for batch operations (much faster)
- Priority: 10 (user-initiated) > 5 (webhooks) > 1 (background)
- Failed tasks (attempts >= 3) stop retrying automatically
- Use `scheduled_at` for delayed execution (e.g., rate limit cooldown)

### Using useMoyskladEntities Composable

```javascript
import { useMoyskladEntities } from '@/composables/useMoyskladEntities'

// In component setup
const accountId = ref('uuid-here')

// Create entity loaders
const organizationsLoader = useMoyskladEntities(accountId.value, 'organizations')
const storesLoader = useMoyskladEntities(accountId.value, 'stores')

// Access reactive data
const organizations = organizationsLoader.items
const loading = organizationsLoader.loading
const error = organizationsLoader.error

// Load data (with auto-caching)
await organizationsLoader.load() // Won't reload if already loaded
await organizationsLoader.reload() // Force reload

// Add new item after creation
const newOrg = await api.syncSettings.createOrganization(accountId.value, data)
organizationsLoader.addItem(newOrg.data)
```

### Using useTargetObjectsMetadata Composable

```javascript
import { useTargetObjectsMetadata } from '@/composables/useTargetObjectsMetadata'

// In component setup
const settings = ref({
  target_organization_id: null,
  target_store_id: null,
  // ... other fields
})

const entities = {
  organizations: organizationsLoader.items,
  stores: storesLoader.items,
  projects: projectsLoader.items,
  employees: employeesLoader.items,
  customerOrderStates: customerOrderStatesLoader.items,
  salesChannels: salesChannelsLoader.items,
  purchaseOrderStates: purchaseOrderStatesLoader.items
}

// Setup metadata management (auto-watches all fields)
const { metadata, initializeMetadata } = useTargetObjectsMetadata(settings, entities)

// Initialize from API response
initializeMetadata(response.data.targetObjectsMeta)

// metadata.value automatically updates when selections change
// Use in SearchableSelect :initial-name="metadata.customer_order_state_id?.name"
```

### Using Batch Loading

```javascript
// OLD WAY (5 separate requests):
const settings = await api.syncSettings.get(accountId)
const priceTypes = await api.syncSettings.getPriceTypes(accountId)
const attributes = await api.syncSettings.getAttributes(accountId)
const folders = await api.syncSettings.getFolders(accountId)
// accountName from separate query...

// NEW WAY (1 batch request):
const batchData = await api.syncSettings.getBatch(accountId)
const { settings, accountName, priceTypes, attributes, folders } = batchData.data.data

// 3-4x faster, especially on slow connections
```

## Important Gotchas

### API & Integration

1. **JWT for МойСклад MUST use `JSON_UNESCAPED_SLASHES`** - will fail without it
2. **Context must be cached** - Middleware expects it in cache with key `moysklad_context:{contextKey}`
3. **contextKey must be in sessionStorage** - API interceptor reads from there
4. **PurchaseOrder sync** - Only проведенные (applicable=true), on CREATE and UPDATE
5. **Product deletion** - Archive in children, don't delete
6. **Rate limits** - Always use `RateLimitHandler`, never direct API calls
7. **Webhook TariffChanged** - No access_token in payload, must fetch from DB
8. **CORS** - Only `online.moysklad.ru` and `dev.moysklad.ru` allowed
9. **Price Types Endpoint** - Use `context/companysettings` (NOT `context/companysettings/pricetype`), returns all company settings including `priceTypes` array
10. **MoySkladService Response Structure** - All methods return `['data' => ..., 'rateLimitInfo' => ...]`, always access via `$response['data']`

### Queue System & Supervisor

11. **Queue Payload MUST include main_account_id** - ProcessSyncQueueJob requires `payload['main_account_id']` to work. Tasks without it will fail with TypeError. Always include when creating tasks:
    ```php
    'payload' => ['main_account_id' => $mainAccountId]
    ```

12. **Restart worker after code deploy** - Supervisor keeps old PHP code in memory. After deployment, ALWAYS restart worker:
    ```bash
    sudo supervisorctl restart laravel-worker:*
    # Or use: ./restart-queue.sh
    ```

13. **Catch Throwable, not Exception** - Queue jobs must catch `\Throwable` (not `\Exception`) to handle TypeError and other errors:
    ```php
    try {
        // process task
    } catch (\Throwable $e) {  // Not \Exception!
        // handle error
    }
    ```

14. **Scheduler + Queue are separate** - `schedule:run` (cron) dispatches jobs to queue. Queue worker (`queue:work`) processes them. If worker is not running, jobs pile up in `jobs` table and never execute. Always check both:
    ```bash
    # Check scheduler is running (cron)
    crontab -l | grep schedule:run

    # Check worker is running (Supervisor)
    sudo supervisorctl status laravel-worker:*
    ```

15. **Worker won't see DB changes immediately** - Worker holds DB connection. If you manually update `sync_queue` in database, worker may not see it until next iteration (up to 3 seconds with `--sleep=3`). For immediate processing, restart worker.

16. **Failed tasks (attempts >= 3) stop retrying** - Tasks that fail 3 times are marked as 'failed' and ignored. They won't retry automatically. Must manually requeue or fix and requeue:
    ```php
    // Requeue all failed tasks
    SyncQueue::where('status', 'failed')->update([
        'status' => 'pending',
        'attempts' => 0,
        'error_message' => null
    ]);
    ```

17. **Supervisor config path differs by OS**:
    - CentOS/RHEL: `/etc/supervisord.d/*.ini`
    - Ubuntu/Debian: `/etc/supervisor/conf.d/*.conf`
    - Use `setup-queue-worker.sh` - auto-detects OS

18. **Queue logs vs Application logs** - Different log files:
    - `storage/logs/worker.log` - Worker stdout/stderr (job dispatch, errors)
    - `storage/logs/laravel.log` - Application logs (Log::info/error)
    - `storage/logs/sync.log` - Detailed sync operations (REQUEST/RESPONSE)
    - Check ALL three when debugging

19. **Bulk insert requires json_encode for payload** - When using `SyncQueue::insert($tasks)`, payload must be JSON string:
    ```php
    'payload' => json_encode(['main_account_id' => $id])  // String
    ```
    When using `SyncQueue::create()`, payload can be array (auto-casted):
    ```php
    'payload' => ['main_account_id' => $id]  // Array (works with create)
    ```

### Frontend

20. **useMoyskladEntities Caching** - Always check if data is loaded before calling `load()`. Use `reload()` to force refresh. The composable prevents duplicate API calls automatically.
21. **Component Emit Events** - Section components (ProductSyncSection, etc.) only emit events, they don't call APIs directly. Parent component (FranchiseSettings.vue) handles all API calls and state management.
22. **Batch Loading First** - Use `getBatch()` for initial page load, then use individual endpoints only when user interacts (opens dropdown, clicks create, etc.)
23. **Price Types Structure** - priceTypes endpoint returns `{main: [...], child: [...]}`, NOT a flat array. Always destructure correctly.
24. **SimpleSelect Loading State** - Always pass `:loading` prop when data is being fetched asynchronously. This shows spinner and improves UX during API calls.
25. **CustomEntity ID Extraction** - Use `extractCustomEntityId()` helper to extract UUID from `customEntityMeta.href`. Supports both full URL and relative paths. UUID format: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` (36 chars).

## Configuration

Key `.env` variables:
```env
MOYSKLAD_APP_ID=         # App UUID from developer console
MOYSKLAD_APP_UID=        # App UID (appUid)
MOYSKLAD_SECRET_KEY=     # Secret key from developer console

DB_CONNECTION=pgsql
DB_DATABASE=moysklad_db
DB_USERNAME=moysklad_user
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

CACHE_STORE=redis        # Context caching requires Redis/Memcached
```

## Git Workflow

Format: `<type>: <description>`

Types: `feat`, `fix`, `style`, `refactor`, `docs`

Always commit with descriptive messages including:
```
🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>
```

## Resources

- [МойСклад JSON API 1.2](https://dev.moysklad.ru/doc/api/remap/1.2/)
- [МойСклад Vendor API 1.0](https://dev.moysklad.ru/doc/api/vendor/1.0/)
- [Developer Console](https://apps.moysklad.ru/cabinet/)
