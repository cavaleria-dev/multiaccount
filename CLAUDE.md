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
php artisan queue:listen       # Process queue jobs
php artisan pail               # Real-time logs

# Testing (server only)
composer test                  # Run PHPUnit tests
php artisan test               # Same as above

# Production commands (server only)
php artisan config:cache       # Cache config
php artisan route:cache        # Cache routes
php artisan view:cache         # Cache views

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
11. **useMoyskladEntities Caching** - Always check if data is loaded before calling `load()`. Use `reload()` to force refresh. The composable prevents duplicate API calls automatically.
12. **Component Emit Events** - Section components (ProductSyncSection, etc.) only emit events, they don't call APIs directly. Parent component (FranchiseSettings.vue) handles all API calls and state management.
13. **Batch Loading First** - Use `getBatch()` for initial page load, then use individual endpoints only when user interacts (opens dropdown, clicks create, etc.)
14. **Price Types Structure** - priceTypes endpoint returns `{main: [...], child: [...]}`, NOT a flat array. Always destructure correctly.
15. **SimpleSelect Loading State** - Always pass `:loading` prop when data is being fetched asynchronously. This shows spinner and improves UX during API calls.
16. **CustomEntity ID Extraction** - Use `extractCustomEntityId()` helper to extract UUID from `customEntityMeta.href`. Supports both full URL and relative paths. UUID format: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` (36 chars).

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
