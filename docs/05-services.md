# Service Layer
### Service Layer

**IMPORTANT:** After refactoring (2025-01), synchronization services are split by entity type for better maintainability.

**Core Services** (`app/Services/`):
- `MoySkladService` - Low-level API client, rate limit handling
  * **New methods (added for StandardEntitySyncService):**
    - `getEntity(accountId, entityType, entityId, params)` - Get entity by ID with auto token management
    - `getList(accountId, entityType, params)` - Get entity list with filters
    - `createEntity(accountId, entityType, data)` - Create entity
    - All methods accept `accountId`, fetch token from DB automatically, return only `['data']` (not full response)
- `VendorApiService` - JWT generation, context retrieval
- `ProductSyncService` - **Products ONLY** (товары без модификаций/комплектов) ⭐ **REFACTORED**
- `VariantSyncService` - **Variants** (модификации) ⭐ **NEW**
- `BundleSyncService` - **Bundles** (комплекты) ⭐ **NEW**
- `ProductFolderSyncService` - **Product folders** (группы товаров) ⭐ **NEW**
- `ServiceSyncService` - Services sync (услуги)
- `CustomerOrderSyncService` - Customer orders sync
- `RetailDemandSyncService` - Retail sales sync
- `PurchaseOrderSyncService` - Purchase orders sync (проведенные only)
- `CounterpartySyncService` - Counterparty management
- `CustomEntitySyncService` - Custom entity sync
- `StandardEntitySyncService` - Standard references sync (uom, currency, country, vat)
- `BatchSyncService` - Batch sync with queues
- `WebhookService` - Webhook management
- `ProductFilterService` - Apply visual filters to products and services (universal)
- `RateLimitHandler` - API rate limit handling (45 req/sec burst, exponential backoff)

**Shared Code** (`app/Services/Traits/`):
- `SyncHelpers` - Trait with common methods for all sync services ⭐ **NEW**
  - `extractEntityId()` - Extract UUID from МойСклад href
  - `syncAttributes()` / `createAttributeInChild()` - Attribute synchronization
  - `syncPrices()` / `getOrCreatePriceType()` - Price synchronization with currency mapping
  - `passesFilters()` - Product filter validation

**Key Jobs:**
- `ProcessSyncQueueJob` - Runs every minute via scheduler, processes 50 tasks per batch

### Sync Services Architecture (After Refactoring)

**Sync Order (respecting dependencies):**
1. **ProductFolderSyncService** - Groups (no dependencies)
2. **ProductSyncService** - Products (depends on ProductFolderSyncService)
3. **VariantSyncService** - Variants (depends on ProductSyncService for parent product)
4. **BundleSyncService** - Bundles (depends on ProductSyncService + VariantSyncService for components)
5. **ServiceSyncService** - Services (independent)

**Service Responsibilities:**

| Service | Entity Types | Dependencies | Methods |
|---------|-------------|--------------|---------|
| `ProductFolderSyncService` | productfolder | None | `syncProductFolder()` (recursive) |
| `ProductSyncService` | product | ProductFolderSyncService, **ProductFilterService** | `syncProduct()`, `archiveProduct()` |
| `VariantSyncService` | variant | ProductSyncService | `syncVariant()`, `syncCharacteristics()`, `archiveVariant()` |
| `BundleSyncService` | bundle | ProductSyncService, VariantSyncService | `syncBundle()`, `syncBundleComponents()`, `archiveBundle()` |
| `ServiceSyncService` | service | **ProductFilterService** | `syncService()`, `archiveService()` |

**Circular Dependency Resolution:**

VariantSyncService and BundleSyncService depend on ProductSyncService, but ProductSyncService needs to delegate variant/bundle calls to them for backward compatibility. This creates circular dependency.

**Solution:** Setter injection in `AppServiceProvider::boot()`:

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    $this->app->resolving(\App\Services\ProductSyncService::class, function ($productSyncService, $app) {
        if ($app->bound(\App\Services\VariantSyncService::class)) {
            $productSyncService->setVariantSyncService($app->make(\App\Services\VariantSyncService::class));
        }

        if ($app->bound(\App\Services\BundleSyncService::class)) {
            $productSyncService->setBundleSyncService($app->make(\App\Services\BundleSyncService::class));
        }
    });
}
```

**Delegating Methods (Backward Compatibility):**

ProductSyncService keeps these methods that delegate to specialized services:

```php
// In ProductSyncService.php
public function syncVariant(...) {
    return $this->variantSyncService->syncVariant(...);  // Delegate
}

public function syncBundle(...) {
    return $this->bundleSyncService->syncBundle(...);  // Delegate
}

public function archiveVariant(...) {
    return $this->variantSyncService->archiveVariant(...);  // Delegate
}

public function archiveBundle(...) {
    return $this->bundleSyncService->archiveBundle(...);  // Delegate
}
```

This allows existing code (ProcessSyncQueueJob, webhooks) to continue calling `ProductSyncService::syncVariant()` without changes.

**Benefits of Refactoring:**

- **File sizes**: ProductSyncService reduced from 1730 → 563 lines (↓67%)
- **Code reuse**: SyncHelpers trait eliminates ~500 lines of duplication
- **Separation of concerns**: Each service handles one entity type
- **Easier testing**: Services can be tested independently
- **Better readability**: Smaller files, clearer responsibilities
- **Proper sync order**: Can ensure ProductFolder → Product → Variant → Bundle
- **Maintainability**: Changes to variant logic don't affect product logic

### Product Filter Service

**Purpose:** Universal filter service for entities with `attributes` and `productFolder` fields.

**Used by:**
- `ProductSyncService` - Filter products before sync
- `ServiceSyncService` - Filter services before sync (since 2025-10-20)

**Key features:**
- ✅ **Universal for products AND services** - Both have `attributes` and can have `productFolder`
- ✅ **Filter by attributes** - All attribute types (string, long, double, boolean, time, customentity)
- ✅ **Filter by folders** - productFolder/productGroup filtering
- ✅ **Complex logic** - AND/OR operators, nested groups, whitelist/blacklist modes
- ✅ **Performance** - Filters applied BEFORE API calls (saves requests, time, DB space)

**Integration points:**

1. **In `syncService()` / `syncProduct()`** - Individual entity sync:
   ```php
   // After loading entity data, before checking mapping
   if (!$this->passesFilters($service, $settings, $mainAccountId)) {
       Log::debug('Service does not pass filters', [...]);
       return null; // Skip sync
   }
   ```

2. **In `prepareServiceForBatch()` / `prepareProductForBatch()`** - Batch preparation:
   ```php
   // At the beginning of method, before any processing
   if (!$this->passesFilters($service, $settings, $mainAccountId)) {
       Log::debug('Service filtered out in batch', [...]);
       return null; // Exclude from batch
   }
   ```

3. **SyncHelpers trait** - Shared method for all sync services:
   ```php
   protected function passesFilters(array $entity, SyncSetting $settings, string $mainAccountId): bool
   {
       if (!$settings->product_filters_enabled) {
           return true; // Filters disabled
       }

       $filters = $settings->product_filters;
       if (!$filters) {
           return true; // No filters configured
       }

       return $this->productFilterService->passes($entity, $filters);
   }
   ```

**Why ProductFilterService works for services:**
- Services in МойСклад have `attributes` (additional fields) - same structure as products
- Services can be in `productFolder` (groups) - same as products
- All filter operators (equals, contains, in, greater_than, etc.) are type-agnostic
- Filter logic (AND/OR, whitelist/blacklist) doesn't depend on entity type

**Configuration:**
- Stored in `sync_settings.product_filters` (JSON)
- Enabled via `sync_settings.product_filters_enabled` (boolean)
- Same filters apply to both products and services per child account

**See also:** [Product Filters Documentation](../docs/PRODUCT_FILTERS.md)

### Service Match Field

**Added:** 2025-10-20 (Migration `2025_10_20_120000_add_service_match_field_to_sync_settings.php`)

**Purpose:** Separate field for matching services (since services don't have `article` field in МойСклад API).

**Problem:**
- Previously services used `product_match_field` setting
- Services in МойСклад API **do NOT have `article` field**
- If `product_match_field = 'article'` → services can't be matched → sync fails

**Solution:**
- New field `service_match_field` in `sync_settings` table
- Default value: `'code'`
- Allowed values: `'name'`, `'code'`, `'externalCode'`, `'barcode'` (NO 'article')

**Database:**
```sql
ALTER TABLE sync_settings
ADD COLUMN service_match_field VARCHAR(50) DEFAULT 'code'
COMMENT 'Field for matching services: name, code, externalCode, barcode (NO article)';
```

**Model:**
```php
// app/Models/SyncSetting.php
protected $fillable = [
    // ...
    'product_match_field',
    'service_match_field',  // ⭐ NEW
    // ...
];
```

**UI:**
```vue
<!-- resources/js/components/franchise-settings/ProductSyncSection.vue -->

<!-- Service match field (no article!) -->
<SimpleSelect
  v-model="localSettings.service_match_field"
  label="Поле для сопоставления услуг"
  placeholder="Выберите поле"
  :options="serviceMatchFieldOptions"
/>

<script setup>
// Service match field options (no article field for services!)
const serviceMatchFieldOptions = [
  { id: 'name', name: 'Наименование (name)' },
  { id: 'code', name: 'Код (code)' },
  { id: 'externalCode', name: 'Внешний код (externalCode)' },
  { id: 'barcode', name: 'Штрихкод (первый barcode)' }
]
</script>
```

**Important UI Fix (Commit 5552b3a):**
Previously `service_match_field` was missing from initial `settings` object in `FranchiseSettings.vue`, causing value not to save.

**Fixed in:**
```javascript
// resources/js/pages/FranchiseSettings.vue
const settings = ref({
  // ...
  product_match_field: 'article',
  service_match_field: 'code',  // ⭐ ADDED (was missing!)
  // ...
})
```

**Usage in ServiceSyncService:**
```php
// app/Services/ServiceSyncService.php

// In prepareServiceForBatch()
$matchField = $settings->service_match_field ?? 'code';
$matchValue = $service[$matchField] ?? null;

if (!$matchValue) {
    Log::warning('Service has empty match field, skipping', [
        'service_id' => $service['id'],
        'match_field' => $matchField
    ]);
    return null; // Skip services with empty match value
}

// Check if service exists in child account
$existingService = $this->moySkladService->get(
    "entity/service?filter={$matchField}={$matchValue}"
);

if ($existingService) {
    // UPDATE existing service
    $service['meta'] = ['href' => $existingService['meta']['href']];
    $service['_is_update'] = true;
} else {
    // CREATE new service
    $service['_is_update'] = false;
}
```

**Used in ProcessSyncQueueJob:**
```php
// app/Jobs/ProcessSyncQueueJob.php - processBatchServiceSync()

// Create mapping for successful service
EntityMapping::updateOrCreate(
    [
        'parent_account_id' => $mainAccountId,
        'child_account_id' => $childAccountId,
        'entity_type' => 'service',
        'parent_entity_id' => $originalId
    ],
    [
        'child_entity_id' => $createdService['id'],
        'sync_direction' => 'main_to_child',
        'match_field' => $syncSettings->service_match_field ?? 'code',  // ⭐ Uses service_match_field
        'match_value' => $createdService['code'] ?? $createdService['name']
    ]
);
```

**Benefits:**
- ✅ Services can be matched by appropriate fields (no article available)
- ✅ Independent configuration for products and services
- ✅ Services with empty match field are skipped (logged + not synced)
- ✅ Correct field stored in `entity_mappings.match_field`

**Testing:**
1. Open `/app/accounts/{id}/settings`
2. Change "Поле для сопоставления услуг" (code → name → externalCode)
3. Save settings
4. Reload page → value persists
5. Run "Синхронизировать все товары" → services matched by selected field

**Migration:** `database/migrations/2025_10_20_120000_add_service_match_field_to_sync_settings.php`

