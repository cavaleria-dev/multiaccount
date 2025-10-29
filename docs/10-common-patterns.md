# Common Patterns & Gotchas
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

### Using StandardEntitySyncService

**Purpose:** Synchronize standard МойСклад references (uom/currency/country/vat) between accounts by code/isoCode.

**When to use:**
- Before creating/updating products (need uom, currency, country, vat)
- When copying entities that reference standard refs
- Initial franchise setup

**Example in ProductSyncService:**

```php
use App\Services\StandardEntitySyncService;

class ProductSyncService
{
    protected StandardEntitySyncService $standardEntitySync;

    public function __construct(
        MoySkladService $moySkladService,
        StandardEntitySyncService $standardEntitySync
    ) {
        $this->moySkladService = $moySkladService;
        $this->standardEntitySync = $standardEntitySync;
    }

    public function createProduct(array $parentProduct, string $parentAccountId, string $childAccountId): ?array
    {
        // Load product with standard references expanded
        $product = $this->moySkladService->getEntity(
            $parentAccountId,
            'product',
            $parentProduct['id'],
            ['expand' => 'uom,country']
        )['data'];

        // Sync UOM (единица измерения)
        $childUomId = null;
        if (isset($product['uom'])) {
            $parentUomId = $this->extractId($product['uom']['meta']['href']);
            $childUomId = $this->standardEntitySync->syncUom(
                $parentAccountId,
                $childAccountId,
                $parentUomId
            );
        }

        // Sync Country (страна)
        $childCountryId = null;
        if (isset($product['country'])) {
            $parentCountryId = $this->extractId($product['country']['meta']['href']);
            $childCountryId = $this->standardEntitySync->syncCountry(
                $parentAccountId,
                $childAccountId,
                $parentCountryId
            );
        }

        // Sync Currency (валюта) - from price type or default
        $childCurrencyId = null;
        if (isset($product['salePrices'][0]['currency'])) {
            $parentCurrencyId = $this->extractId($product['salePrices'][0]['currency']['meta']['href']);
            $childCurrencyId = $this->standardEntitySync->syncCurrency(
                $parentAccountId,
                $childAccountId,
                $parentCurrencyId
            );
        }

        // Sync VAT (ставка НДС)
        $vatRate = $product['vat'] ?? null; // 20, 10, 0, null
        $this->standardEntitySync->syncVat($parentAccountId, $childAccountId, $vatRate);

        // Build child product with mapped IDs
        $childProduct = [
            'name' => $product['name'],
            'code' => $product['code'],
            // ... other fields
        ];

        // Add uom reference if synced
        if ($childUomId) {
            $childProduct['uom'] = [
                'meta' => [
                    'href' => $this->moySkladService->buildUrl($childAccountId, 'uom', $childUomId),
                    'type' => 'uom',
                    'mediaType' => 'application/json'
                ]
            ];
        }

        // Add country reference if synced
        if ($childCountryId) {
            $childProduct['country'] = [
                'meta' => [
                    'href' => $this->moySkladService->buildUrl($childAccountId, 'country', $childCountryId),
                    'type' => 'country',
                    'mediaType' => 'application/json'
                ]
            ];
        }

        // Create product in child account
        return $this->moySkladService->createEntity($childAccountId, 'product', $childProduct)['data'];
    }
}
```

**Key methods:**

1. **syncUom(parentAccountId, childAccountId, parentUomId)** → childUomId
   - Maps by `code` ("796", "166", "163")
   - Creates custom UOM if not found
   - Caches locally to avoid duplicate API calls
   - Returns `null` on error

2. **syncCurrency(parentAccountId, childAccountId, parentCurrencyId)** → childCurrencyId
   - Maps by `isoCode` ("RUB", "USD", "EUR")
   - Only maps (can't create currency)
   - Returns `null` if not found

3. **syncCountry(parentAccountId, childAccountId, parentCountryId)** → childCountryId
   - Maps by `code` ("643", "840", "276")
   - Only maps (can't create country)
   - Returns `null` if not found

4. **syncVat(parentAccountId, childAccountId, vatRate)** → vatRate
   - Maps by rate (20, 10, 0, null)
   - Always returns same rate (for tracking only)
   - Saves mapping for statistics

**Performance notes:**
- Service has internal cache (uomCache, currencyCache, countryCache)
- Call `clearCache()` if processing multiple unrelated batches
- DB mappings persist across requests (checked first)
- API calls only made if mapping not found in DB

**Error handling:**
- Returns `null` if entity not found or can't be created
- Logs warnings/errors but doesn't throw exceptions
- Caller should check return value and handle gracefully

```php
$childUomId = $this->standardEntitySync->syncUom($parentId, $childId, $parentUomId);
if (!$childUomId) {
    Log::warning('Failed to sync UOM, skipping uom field');
    // Continue without uom reference
}
```

**Currency synchronization in prices:**

`ProductSyncService::syncPrices()` automatically synchronizes currencies in all price fields using `StandardEntitySyncService::syncCurrency()`. This handles:

1. **buyPrice → buyPrice** (with and without price mappings):
```php
// Extract currency from buyPrice
if (isset($buyPrice['currency']['meta']['href'])) {
    $parentCurrencyId = $this->extractEntityId($buyPrice['currency']['meta']['href']);
    $childCurrencyId = $this->standardEntitySync->syncCurrency(
        $mainAccountId,
        $childAccountId,
        $parentCurrencyId
    );

    if ($childCurrencyId) {
        $buyPrice['currency'] = [
            'meta' => [
                'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                'type' => 'currency',
                'mediaType' => 'application/json'
            ]
        ];
    } else {
        // Remove currency if sync failed → МойСклад uses default
        unset($buyPrice['currency']);
        Log::warning('Currency sync failed, using default currency');
    }
}
```

2. **buyPrice → salePrice** (price type mapping):
   - Syncs currency from main account's buyPrice
   - Builds proper currency meta for child's salePrice

3. **salePrice → buyPrice** (reverse mapping):
   - Priority: priceInfo currency → mainBuyPrice currency
   - Syncs currency from whichever is available

4. **salePrice → salePrice** (with explicit mapping):
   - Syncs currency from main account's salePrice
   - Applied to both mapped and old logic (getOrCreatePriceType) paths

**Key behavior:**
- **If currency sync fails** (returns `null`): Price field sent WITHOUT currency reference → МойСклад automatically uses account's default currency
- **Hardcoded currency removed**: Previously had hardcoded `/entity/currency/643` (RUB) → now dynamic based on main account
- **All price paths covered**: buyPrice, salePrices with mappings, salePrices without mappings (old logic)
- **Graceful fallback**: Warning logged, but sync continues without currency → prevents API errors

**When currency sync is triggered:**
- Product/variant/bundle sync via `ProductSyncService`
- Service sync via `ServiceSyncService`
- Any entity with price fields that use currency references

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

20. **Stale characteristic mappings cause error 10001** - When a variant is deleted in child account, МойСклад also deletes its characteristics. If characteristic mappings remain in database, next sync will fail with error 10001: "поле 'id' ссылается на несуществующую характеристику". This is now handled automatically:
    - Error 10001 is caught in `VariantSyncService::updateVariant()`
    - Stale characteristic mappings are deleted
    - Variant is recreated with new characteristics
    - Manual cleanup command available: `php artisan sync:cleanup-stale-characteristic-mappings`

### Data Synchronization

21. **Attribute search must check type, not just name** - When child accounts have pre-existing attributes, searching by name only can match wrong attribute with same name but different type. Always use `AttributeSyncService::findAttributeByNameAndType()` which validates:
    - Exact name match
    - Exact type match (string, long, double, boolean, text, link, time, file, customentity, etc.)
    - For `customentity` type: exact customEntity name match

    **Wrong (name only):**
    ```php
    $childAttr = $childAttributesByName[$attrName] ?? null;  // May match wrong type!
    ```

    **Correct (name + type):**
    ```php
    $childAttr = $this->attributeSyncService->findAttributeByNameAndType(
        $childAttributes,
        $attrName,
        $attrType,
        $mainAttr['customEntityMeta'] ?? null
    );
    ```

    **Why this matters:** Child accounts may have existing attributes from previous operations. Example:
    - Main: "Цвет" (customentity type, entity: "Цвет")
    - Child: Already has "Цвет" (string type) from different sync
    - Searching by name only → matches wrong attribute → API error or data corruption

22. **Product folder API uses fuzzy search, validate exact name** - МойСклад API performs approximate matching when searching folders by name. Always validate exact name match before accepting result, or you may link products to wrong groups. Fixed in commit [a4e09f0](https://github.com/cavaleria-dev/multiaccount/commit/a4e09f0).

    **Wrong (accepts fuzzy match):**
    ```php
    $folders = $this->moySkladService->getList(..., ['filter' => "name~{$name}"]);
    return $folders[0] ?? null;  // May return "Декор для маникюра" when searching "LEELOO (merch)"!
    ```

    **Correct (validates exact match):**
    ```php
    $folders = $this->moySkladService->getList(..., ['filter' => "name~{$name}"]);
    foreach ($folders as $folder) {
        if (($folder['name'] ?? '') === $name) {
            return $folder;  // Only return if name matches exactly
        }
    }
    return null;  // No exact match → create new folder
    ```

    **Real example:** Searching for "LEELOO (merch)" returned "Декор для маникюра" → products placed in completely wrong group. See [docs/14-product-folder-sync.md](docs/14-product-folder-sync.md#issue-products-placed-in-wrong-folder-api-fuzzy-search--fixed-2025-10-29) for details.

23. **Variant code should NOT be synced** - Variant `code` field (артикул/article) should be excluded from synchronization to avoid uniqueness conflicts when child accounts have existing variants. Fixed in commit [13c385e](https://github.com/cavaleria-dev/multiaccount/commit/13c385e).

    **Problem:**
    - Main: Variant with code "ART-001"
    - Child: Already has variant with code "ART-001" (from different product)
    - Sync fails: "Артикул не уникален" (code must be unique)

    **Solution:** Remove `code` from variant sync, keep `externalCode`:
    ```php
    // DON'T sync code
    // if (isset($variant['code'])) { $variantData['code'] = $variant['code']; }

    // DO sync externalCode
    if (isset($variant['externalCode'])) {
        $variantData['externalCode'] = $variant['externalCode'];
    }
    ```

    **Why this works:** Variants are matched by parent product + characteristics, NOT by code. Code is optional field for internal accounting only. Removed from:
    - `VariantSyncService::createVariant()` (line 303)
    - `VariantSyncService::updateVariant()` (line 460)
    - `VariantSyncService::prepareVariantDataForBatchUpdate()` (lines 1386-1388)

### Frontend

24. **useMoyskladEntities Caching** - Always check if data is loaded before calling `load()`. Use `reload()` to force refresh. The composable prevents duplicate API calls automatically.
25. **Component Emit Events** - Section components (ProductSyncSection, etc.) only emit events, they don't call APIs directly. Parent component (FranchiseSettings.vue) handles all API calls and state management.
26. **Batch Loading First** - Use `getBatch()` for initial page load, then use individual endpoints only when user interacts (opens dropdown, clicks create, etc.)
27. **Price Types Structure** - priceTypes endpoint returns `{main: [...], child: [...]}`, NOT a flat array. Always destructure correctly.
28. **SimpleSelect Loading State** - Always pass `:loading` prop when data is being fetched asynchronously. This shows spinner and improves UX during API calls.
29. **CustomEntity ID Extraction** - Use `extractCustomEntityId()` helper to extract UUID from `customEntityMeta.href`. Supports both full URL and relative paths. UUID format: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` (36 chars).
30. **EXCLUDED_ATTRIBUTE_TYPES** - Constants in `SyncSettingsController` define attribute types that should NOT be synced (`counterparty`, `employee`, `store`, `organization`, `product`). These are filtered at API level in `getAttributes()` and `getBatchData()`. Never show these types in UI - they are managed via target objects settings.
31. **MoySkladService New Methods** - Three convenience methods added for `StandardEntitySyncService`: `getEntity()`, `getList()`, `createEntity()`. These accept `accountId` (not token), fetch token from DB automatically, and return only data (not full response with rate limit info). Use these instead of direct `get()`/`post()` when you need simple entity operations.
32. **auto_create_attributes is DEPRECATED** - Attribute synchronization is now controlled ONLY by `attribute_sync_list` (frontend selection of specific attributes). If `attribute_sync_list` is empty → NO attributes synced at all. If filled → ONLY selected attributes synced. The `auto_create_attributes` database field is ignored by sync services (`ProductSyncService`, `ServiceSyncService`, `BundleSyncService` call `syncAttributes()` unconditionally - the filtering happens inside `syncAttributes()` based on `attribute_sync_list`).

