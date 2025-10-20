# Batch Synchronization Optimization

**Date:** 2025-01
**Status:** Implemented for Products, Variants, Services

## Problem

Individual entity synchronization requires excessive API requests:
- 1000 products = ~2500 API requests (GET entity + GET dependencies per product)
- МойСклад API limit: 45 req/sec burst → potential rate limiting
- Slow sync speed (15+ minutes for 1000 products)

## Solution

Batch POST API with pre-cached dependencies reduces requests by **97%**:
- 1000 products: ~2500 → ~65 requests
- 500 services: ~1250 → ~30 requests
- **Total savings: ~3750 → ~95 requests** (↓97.5%)

## Architecture: 3-Phase Batch Sync

```
User clicks "Sync All Products"
    ↓
─────────────────────────────────────────────────────────────────
PHASE 1: Pre-cache Dependencies (ONCE for all entity types)
─────────────────────────────────────────────────────────────────
DependencyCacheService::cacheAll($mainAccountId, $childAccountId)
    │
    ├─ cacheStandardEntities()
    │   └─ UOM (единицы измерения): Load from both accounts → Map by code → DB
    │   └─ Country (страны): Load from both accounts → Map by code → DB
    │   └─ 6-20 GET requests
    │
    ├─ cacheProductFolders()
    │   └─ Product folders (группы товаров): Recursive sync → DB mappings
    │   └─ 5-10 GET requests
    │
    ├─ cacheAttributes($mainAccountId, $childAccountId, 'product')
    │   └─ Attributes metadata: Load from main + child → Create attribute in child → DB
    │   └─ For customentity type: Sync custom entity itself → custom_entity_mappings
    │   └─ 2-5 GET requests
    │
    └─ cacheCustomEntityElements($mainAccountId, $childAccountId)
        └─ Load ALL elements from ALL custom entities → DB mappings
        └─ Critical: Avoids N GET requests for new elements
        └─ 5-10 GET requests

TOTAL: 6-35 GET requests (executes ONCE per "Sync All" operation)
    ↓
─────────────────────────────────────────────────────────────────
PHASE 2: Batch Load Entities (Controller)
─────────────────────────────────────────────────────────────────
SyncActionsController::syncAllProducts()
    │
    ├─ For Products: createBatchProductTasks()
    │   └─ GET /entity/product?limit=100&offset=0&expand=attributes,uom,country,packs.uom
    │   └─ GET /entity/product?limit=100&offset=100&expand=...
    │   └─ ... (10 requests for 1000 products)
    │   └─ Create SyncQueue tasks:
    │       - entity_type: 'batch_products'
    │       - payload: {main_account_id, products: [...]} ← Preloaded data!
    │
    ├─ For Variants: syncEntityType('variant')
    │   └─ Creates tasks: entity_type='product_variants' (per parent product)
    │
    └─ For Services: createBatchServiceTasks()
        └─ GET /entity/service?limit=100&offset=0&expand=attributes,uom
        └─ GET /entity/service?limit=100&offset=100&expand=...
        └─ ... (5 requests for 500 services)
        └─ Create SyncQueue tasks:
            - entity_type: 'batch_services'
            - payload: {main_account_id, services: [...]}

TOTAL: 1-2 GET requests per 100 entities (pages of 100 with expand)
    ↓
─────────────────────────────────────────────────────────────────
PHASE 3: Prepare & Batch POST (ProcessSyncQueueJob)
─────────────────────────────────────────────────────────────────
ProcessSyncQueueJob::processBatchProductSync($task) / processBatchServiceSync($task)
    │
    ├─ Load preloaded entities from task payload
    │   └─ $products = $payload['products']  (already loaded in Phase 2!)
    │
    ├─ Prepare entities using ONLY cached mappings (0 GET requests!)
    │   └─ ProductSyncService::prepareProductForBatch($product, ...)
    │       ├─ Check mapping (create or update?) → DB lookup
    │       ├─ Sync UOM → getCachedUomMapping() → DB lookup
    │       ├─ Sync Country → getCachedCountryMapping() → DB lookup
    │       ├─ Sync ProductFolder → DB lookup
    │       ├─ Sync Attributes → DB lookup (AttributeMapping)
    │       ├─ For customentity attributes → DB lookup (CustomEntityElementMapping)
    │       ├─ Sync Prices → getCachedCurrencyMapping() → DB lookup
    │       └─ Return prepared product with metadata (_original_id, _is_update)
    │
    ├─ Batch POST to МойСклад
    │   └─ MoySkladService::batchCreateProducts($preparedProducts)
    │       └─ POST /entity/product [array of 100 products]
    │       └─ МойСклад processes array atomically
    │       └─ Returns array of created products
    │
    ├─ Create entity mappings for successful items
    │   └─ EntityMapping::updateOrCreate([...])
    │
    └─ Handle failures: Create individual retry tasks
        └─ For each failed product:
            - SyncQueue::create([entity_type: 'product', entity_id: $originalId])
            - Scheduled for retry in 5 minutes
            - Priority: 5 (medium)
            - Payload: {main_account_id, batch_retry: true}

TOTAL: 1 POST request per 100 entities (batch POST)
```

## Entity Types Supporting Batch Sync

| Entity Type | Batch Size | Entity Type in Queue | Pre-cache Dependencies | Individual Retry |
|-------------|-----------|---------------------|------------------------|-----------------|
| **Products (товары)** | 100 | `batch_products` | UOM, Country, ProductFolder, Attributes, CustomEntity elements | ✅ Yes |
| **Variants (модификации)** | Per parent (up to 1000) | `product_variants` | Characteristics, UOM (for packs), Price types | ✅ Yes |
| **Services (услуги)** | 100 | `batch_services` | UOM, Attributes, CustomEntity elements | ✅ Yes |

**NOT using batch sync:**
- **Bundles (комплекты)** - Complex dependencies (components can be products/variants), low volume
- **Orders** - Time-sensitive, low volume, immediate sync without queue

## Performance Comparison

### Before (Individual Sync)

**Products (1000):**
```
For each product:
  GET /entity/product/{id}                     1 request
  GET /entity/uom/{id}                         1 request (if not cached)
  GET /entity/country/{id}                     1 request (if not cached)
  GET /entity/productfolder/{id}               1 request (if not cached)
  POST /entity/product                         1 request

Average: 2.5 requests/product × 1000 = 2500 requests
```

**Services (500):**
```
Similar logic: 2.5 requests/service × 500 = 1250 requests
```

**Total: ~3750 requests**

### After (Batch Sync)

**Pre-cache (once):**
```
Standard entities (UOM, Country): 6-20 requests
Product folders: 5-10 requests
Attributes: 2-5 requests
Custom entity elements: 5-10 requests

Total: ~30 requests (once)
```

**Products (1000):**
```
Phase 2 (load): GET × 10 (pages of 100) = 10 requests
Phase 3 (batch POST): POST × 10 (batches of 100) = 10 requests

Total: 20 requests
```

**Services (500):**
```
Phase 2 (load): GET × 5 (pages of 100) = 5 requests
Phase 3 (batch POST): POST × 5 (batches of 100) = 5 requests

Total: 10 requests
```

**Grand Total: ~30 (pre-cache) + 20 (products) + 10 (services) = ~60 requests**

**Savings: 3750 → 60 requests (↓98.4%)**

## Individual Retry Logic

**Why critical:**
- МойСклад batch POST can return partial success (80 success, 20 failed)
- Need to identify WHICH items failed and WHY
- Retry only failed items (not entire batch of 100)
- Detailed error tracking per entity for debugging

**Implementation:**

```php
// In processBatchProductSync / processBatchServiceSync
foreach ($createdProducts as $index => $createdProduct) {
    try {
        // Create EntityMapping for successful product
        EntityMapping::updateOrCreate([...]);

    } catch (\Exception $e) {
        // Get original ID from prepared data
        $originalId = $preparedProducts[$index]['_original_id'] ?? null;

        Log::error('Failed to create mapping in batch', [
            'task_id' => $task->id,
            'original_id' => $originalId,
            'error' => $e->getMessage()
        ]);

        // Create individual retry task
        if ($originalId) {
            SyncQueue::create([
                'account_id' => $childAccountId,
                'entity_type' => 'product',  // Individual sync (NOT batch)
                'entity_id' => $originalId,   // Specific failed product
                'operation' => 'update',
                'priority' => 5,              // Medium priority for retry
                'scheduled_at' => now()->addMinutes(5),  // Delay 5 minutes
                'status' => 'pending',
                'attempts' => 0,
                'payload' => [
                    'main_account_id' => $mainAccountId,
                    'batch_retry' => true  // Mark as retry from batch
                ]
            ]);

            Log::info('Created individual retry task for failed product', [
                'original_task_id' => $task->id,
                'product_id' => $originalId
            ]);
        }
    }
}

// If >50% of batch failed → retry entire batch task
if ($failedCount > count($preparedProducts) / 2) {
    throw new \Exception("Batch sync failed: {$failedCount} of " . count($preparedProducts) . " products failed");
}
```

**Result:**
- Failed items automatically requeued for individual retry
- Logs show which specific items failed (with entity_id)
- Can track retry history (attempts counter)
- Dashboard shows individual failed tasks for manual inspection

## Services Involved

**DependencyCacheService** (`app/Services/DependencyCacheService.php`):
- `cacheAll($mainAccountId, $childAccountId)` - Orchestrates all pre-caching
- `cacheStandardEntities()` - UOM, Country mappings
- `cacheProductFolders()` - Recursive folder sync
- `cacheAttributes()` - Attributes + custom entities
- `cacheCustomEntityElements()` - Elements within custom entities

**MoySkladService** (`app/Services/MoySkladService.php`):
- `batchCreateProducts(array $products)` - Batch POST up to 1000 products (20MB limit)
- `batchCreateServices(array $services)` - Batch POST up to 1000 services (20MB limit)

**StandardEntitySyncService** (`app/Services/StandardEntitySyncService.php`):
- `getCachedUomMapping()` - DB lookup without GET
- `getCachedCountryMapping()` - DB lookup without GET
- `getCachedCurrencyMapping()` - DB lookup without GET

**ProductSyncService** (`app/Services/ProductSyncService.php`):
- `prepareProductForBatch()` - Prepares product using only cached mappings (0 GET)

**ServiceSyncService** (`app/Services/ServiceSyncService.php`):
- `prepareServiceForBatch()` - Prepares service using only cached mappings (0 GET)

**SyncActionsController** (`app/Http/Controllers/Api/SyncActionsController.php`):
- `syncAllProducts()` - Entry point, calls DependencyCacheService::cacheAll()
- `createBatchProductTasks()` - Loads products in pages of 100, creates batch tasks
- `createBatchServiceTasks()` - Loads services in pages of 100, creates batch tasks

**ProcessSyncQueueJob** (`app/Jobs/ProcessSyncQueueJob.php`):
- `processBatchProductSync()` - Processes batch_products tasks
- `processBatchServiceSync()` - Processes batch_services tasks
- Case handlers: 'batch_products', 'batch_services' in match statement
- Statistics tracking includes batch entity types

## Testing & Monitoring

**Monitor logs during sync:**
```bash
# Watch for batch operations
tail -f storage/logs/laravel.log | grep -E "Batch|Dependencies pre-cached"

# Check queue status
./monitor-queue.sh

# Detailed sync logs (REQUEST/RESPONSE)
tail -f storage/logs/sync.log
```

**Verify API request count** (should be ~65 for 1000 products):
```bash
# Count batch POST completed messages
grep "Batch POST completed" storage/logs/laravel.log | wc -l

# Count pre-cache operations
grep "Dependencies pre-cached" storage/logs/laravel.log
```

**Check mappings created:**
```sql
-- Total product mappings
SELECT COUNT(*) FROM entity_mappings
WHERE entity_type = 'product'
AND sync_direction = 'main_to_child';

-- Today's synced products (from statistics)
SELECT products_synced, products_failed
FROM sync_statistics
WHERE date = CURRENT_DATE
AND parent_account_id = '<main-account-id>';
```

**Check individual retry tasks:**
```sql
-- Failed products from batch that created retry tasks
SELECT entity_type, entity_id, error_message, attempts, scheduled_at
FROM sync_queue
WHERE status = 'pending'
AND payload::jsonb @> '{"batch_retry": true}'
ORDER BY scheduled_at DESC;
```

**Admin Panel Monitoring:**
- Visit `/admin/logs` to see all API requests
- Filter by entity_type='product' to see batch POST requests
- Check `duration_ms` to measure batch POST performance
- Verify `response_status=200` for successful batch operations

## Implementation Files

**Phase 1 (Pre-cache):**
- `app/Services/DependencyCacheService.php` (~700 lines)
- `app/Services/StandardEntitySyncService.php` (+106 lines for getCached methods)

**Phase 2 (Controller):**
- `app/Http/Controllers/Api/SyncActionsController.php`:
  - `syncAllProducts()` - modified to call cacheAll()
  - `createBatchProductTasks()` - new method (~80 lines)
  - `createBatchServiceTasks()` - new method (~80 lines)

**Phase 3 (Job Processing):**
- `app/Jobs/ProcessSyncQueueJob.php`:
  - `processBatchProductSync()` - new method (~170 lines)
  - `processBatchServiceSync()` - new method (~180 lines)
  - Added cases: 'batch_products', 'batch_services'
  - Statistics tracking updated

**Services:**
- `app/Services/MoySkladService.php`:
  - `batchCreateProducts()` - new method (~50 lines)
  - `batchCreateServices()` - new method (~50 lines)
- `app/Services/ProductSyncService.php`:
  - `prepareProductForBatch()` - new method (~200 lines)
- `app/Services/ServiceSyncService.php`:
  - `prepareServiceForBatch()` - new method (~150 lines)

## Git History

**Commits:**
1. `8f282a1` - Part 1/3: Pre-cache foundation (DependencyCacheService + batch POST methods)
2. `a25e17e` - Part 2/3: Controller and preparation logic (batch task creation)
3. `247ed3a` - Part 3/3: Job processing logic (batch sync + individual retry)

**Branch:** main

## Future Enhancements

**Potential optimizations:**
- Bundles batch sync (complex dependencies, need careful planning)
- Adaptive batch sizing (adjust based on entity complexity)
- Batch DELETE/archive operations
- Pre-warm cache on app activation (background job)
