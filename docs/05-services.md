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
- `ProductFilterService` - Apply visual filters to products
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
| `ProductSyncService` | product | ProductFolderSyncService | `syncProduct()`, `archiveProduct()` |
| `VariantSyncService` | variant | ProductSyncService | `syncVariant()`, `syncCharacteristics()`, `archiveVariant()` |
| `BundleSyncService` | bundle | ProductSyncService, VariantSyncService | `syncBundle()`, `syncBundleComponents()`, `archiveBundle()` |
| `ServiceSyncService` | service | None | `syncService()`, `archiveService()` |

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

