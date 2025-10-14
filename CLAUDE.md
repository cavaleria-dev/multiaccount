# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

МойСклад Franchise Management Application - Laravel 11 + Vue 3 application for managing franchise networks in МойСклад with automatic data synchronization between main and child accounts. Runs as an iframe application inside МойСклад interface.

**Stack:** PHP 8.4, Laravel 11, PostgreSQL 18, Redis 7, Vue 3, Tailwind CSS 3

## Development Commands

```bash
# Setup (first time only)
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate

# Development (runs all services concurrently)
composer dev
# This starts: artisan serve, queue:listen, pail (logs), npm run dev

# Individual services
php artisan serve              # Backend server (localhost:8000)
npm run dev                    # Frontend hot reload (Vite)
php artisan queue:listen       # Process queue jobs
php artisan pail               # Real-time logs

# Database
php artisan migrate            # Run migrations
php artisan migrate:rollback   # Rollback last migration
php artisan migrate:fresh      # Drop all tables and re-migrate (WARNING: deletes data!)

# Cache management
php artisan cache:clear        # Clear application cache
php artisan config:clear       # Clear config cache
php artisan config:cache       # Cache config (production)
php artisan route:cache        # Cache routes (production)
php artisan view:cache         # Cache views (production)

# Frontend
npm run build                  # Production build
npm run preview                # Preview production build

# Testing
composer test                  # Run PHPUnit tests
php artisan test               # Same as above

# Scheduler (production)
# Add to crontab: * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
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
- Queued with priorities and delays (ProcessSyncQueueJob)
- **Deletion/archiving**: Archived in children (NOT deleted) when deleted in main
- **New features:**
  - Price mappings (main ↔ child)
  - Attribute filtering (sync only selected attributes)
  - Product match field (code/article/externalCode/barcode)
  - Optional product folder creation
  - Visual filter constructor for selective sync

**Child Accounts → Main Account (Orders):**
- customerorder → customerorder
- retaildemand → customerorder
- purchaseorder → customerorder (проведенные only)
- Immediate sync without queue

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

**Pages** (`resources/js/pages/`):
- `Dashboard.vue` - Statistics overview
- `ChildAccounts.vue` - Franchise management, add by account name
- `GeneralSettings.vue` - App-wide settings (account type: main/child)
- `FranchiseSettings.vue` - Per-franchise sync settings

**API Client** (`resources/js/api/index.js`):
- Axios instance with interceptor that auto-adds `X-MoySklad-Context-Key` from sessionStorage

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
   ```vue
   <script setup>
   import { ref, onMounted } from 'vue'
   const data = ref(null)
   </script>
   ```
2. **Composables for reusable logic** in `resources/js/composables/`
3. **Always handle loading and error states**

### Tailwind CSS

1. **Utility classes only** - No custom CSS
2. **Color scheme:**
   - Primary: `indigo-500` to `indigo-700`
   - Secondary: `purple-500` to `purple-600`
   - Gradients: `bg-gradient-to-r from-indigo-500 to-purple-600`
3. **Always add transitions** for hover states

## Adding New Features

### Backend Feature

1. Create migration if needed: `php artisan make:migration create_table_name`
2. Create/update model in `app/Models/`
3. Create service in `app/Services/` for business logic
4. Create controller in `app/Http/Controllers/Api/`
5. Add route in `routes/api.php`
6. Add comprehensive logging
7. Wrap in try-catch with error handling

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

```php
SyncQueue::create([
    'account_id' => $accountId,
    'entity_type' => 'product',
    'entity_id' => $productId,
    'operation' => 'update',
    'priority' => 5,
    'scheduled_at' => now()->addSeconds(10), // Delay if needed
    'status' => 'pending'
]);
```

## Important Gotchas

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

## Deployment

Auto-deploy via GitHub Actions on push to `main` branch.

Required GitHub Secrets:
- `SERVER_HOST`
- `SERVER_USER`
- `SSH_PRIVATE_KEY`

Manual deploy: `cd /var/www/app.cavaleria.ru && ./deploy.sh`

## Resources

- [МойСклад JSON API 1.2](https://dev.moysklad.ru/doc/api/remap/1.2/)
- [МойСклад Vendor API 1.0](https://dev.moysklad.ru/doc/api/vendor/1.0/)
- [Developer Console](https://apps.moysklad.ru/cabinet/)
