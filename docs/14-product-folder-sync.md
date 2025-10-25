# Product Folder Synchronization

### Product Folder Synchronization

**Last updated:** 2025-10-25

## Overview

Product folder (productFolder) synchronization manages the replication of product groups from main accounts to child accounts. As of October 2025, this system has been refactored to support **filtered folder sync** - only syncing folders that contain at least one filtered product/bundle/service.

## Key Concepts

### What are Product Folders?

- **МойСклад term:** "Группы товаров" (product groups)
- **API entity type:** `productfolder`
- **Purpose:** Organize products, bundles, and services into hierarchical categories
- **Structure:** Tree structure with parent-child relationships
- **Shared by:** Products, bundles, AND services (all can have `productFolder` field)

### Why Filter Folders?

**Problem (before Oct 2025):**
- Main account has 1000 product folders
- Only 50 products pass filters → sync needed
- System synced ALL 1000 folders (unnecessary API calls)
- Child accounts cluttered with empty folders

**Solution (after Oct 2025):**
- Identify folders needed for filtered entities
- Sync ONLY those folders (50 instead of 1000)
- 95% reduction in folder sync API calls
- Cleaner child account structure

## The `create_product_folders` Setting

**Database field:** `sync_settings.create_product_folders` (boolean)

**Default value:** `true`

**UI location:** Franchise Settings → Product Sync Section → "Создавать группы товаров"

### Behavior

#### When `true` (default):

1. **Filter-aware sync:**
   - Only sync folders containing at least ONE filtered product/bundle/service
   - Example: If product "Товар А" passes filters and belongs to "Группа 1" → sync "Группа 1"

2. **Hierarchy preservation:**
   - Build complete parent chain for each needed folder
   - If "Группа 1" → "Подгруппа 1.1" → "Подгруппа 1.1.1" and product is in 1.1.1
   - Sync ALL three folders (1 → 1.1 → 1.1.1) to preserve structure

3. **Timing:**
   - Folders synced in **PHASE 2.5** of batch sync
   - After filtering entities, before batch POST
   - Mappings cached for reuse in prepare methods

4. **Performance:**
   - Folders synced ONCE per batch (not per entity)
   - Typical: ~50 folders for 1000 filtered products (vs 1000 without filtering)
   - 0 additional GET requests per entity (uses cached mappings)

#### When `false`:

1. **No folder sync:**
   - Skip `ProductFolderSyncService` entirely
   - Don't create any folder mappings

2. **Root-level entities:**
   - All products/bundles/services created WITHOUT `productFolder` field
   - Entities appear in root level of child account catalog

3. **Use cases:**
   - Franchises with flat catalog structure
   - Sync scope limited to specific products (no grouping needed)
   - Custom organization in child accounts

## Architecture

### Service: ProductFolderSyncService

**Location:** `app/Services/ProductFolderSyncService.php`

**Dependencies:** `MoySkladService`, `SyncHelpers` trait

**Key Methods:**

#### 1. syncProductFolder() - Individual sync

```php
public function syncProductFolder(
    string $mainAccountId,
    string $childAccountId,
    string $folderId,
    ?SyncSetting $settings = null
): ?string
```

**Purpose:** Sync single folder with full parent hierarchy (recursive)

**Use case:** Individual product/bundle/service sync (webhooks, manual sync)

**Algorithm:**
1. Load folder from main account
2. Recursively sync parent folder (if exists)
3. Check if folder exists in child (by name + parent match)
4. Create or update folder in child
5. Store mapping in `entity_mappings`
6. Return child folder ID

**Returns:** Child folder UUID or null on failure

#### 2. syncFoldersForEntities() - Batch sync ⭐ NEW

```php
public function syncFoldersForEntities(
    string $mainAccountId,
    string $childAccountId,
    array $entities
): array
```

**Purpose:** Sync ONLY folders needed for filtered entities (batch optimization)

**Use case:** Batch sync operations (PHASE 2.5)

**Algorithm:**

```php
// 1. Collect unique productFolder IDs from filtered entities
$folderIds = collect($entities)
    ->pluck('productFolder.meta.href')
    ->filter()
    ->map(fn($href) => $this->extractEntityId($href))
    ->unique()
    ->values()
    ->all();

if (empty($folderIds)) {
    return []; // No folders needed
}

// 2. Load folders from main account (batch GET with expand)
$folders = $this->moySkladService->getList(
    $mainAccountId,
    'entity/productfolder',
    [
        'filter' => 'id=' . implode(';', $folderIds),
        'expand' => 'productFolder',
        'limit' => 1000
    ]
);

// 3. Build dependency tree - find ALL parent folders
$allFolders = [];
foreach ($folders as $folder) {
    $allFolders[$folder['id']] = $folder;

    // Walk up parent chain
    $parent = $folder['productFolder'] ?? null;
    while ($parent) {
        $parentId = $this->extractEntityId($parent['meta']['href']);

        if (!isset($allFolders[$parentId])) {
            // Load parent folder (not expanded in batch)
            $parentFolder = $this->moySkladService->getEntity(
                $mainAccountId,
                'productfolder',
                $parentId,
                ['expand' => 'productFolder']
            );

            $allFolders[$parentId] = $parentFolder;
            $parent = $parentFolder['productFolder'] ?? null;
        } else {
            break; // Already loaded
        }
    }
}

// 4. Sort folders by hierarchy (parents first, then children)
$sorted = $this->sortFoldersByHierarchy(array_values($allFolders));

// 5. Sync folders in correct order, create mappings
$mappings = [];
foreach ($sorted as $folder) {
    $childFolderId = $this->syncProductFolder(
        $mainAccountId,
        $childAccountId,
        $folder['id'],
        null // Skip settings check in nested call
    );

    if ($childFolderId) {
        $mappings[$folder['id']] = $childFolderId;
    }
}

return $mappings;
```

**Returns:** Array of mappings `[mainFolderId => childFolderId]`

**Performance:**
- 1 batch GET for direct folders (filter by IDs)
- N individual GETs for parent folders (N = depth of hierarchy, typically 1-3)
- 1 POST/PUT per folder to child account
- Typical: ~50 API calls for 50 folders (vs 1000 for all folders)

### Helper Method: sortFoldersByHierarchy()

```php
private function sortFoldersByHierarchy(array $folders): array
{
    $sorted = [];
    $indexed = collect($folders)->keyBy('id')->all();
    $processed = [];

    foreach ($folders as $folder) {
        $this->addFolderWithParents($folder, $indexed, $sorted, $processed);
    }

    return $sorted;
}

private function addFolderWithParents($folder, $indexed, &$sorted, &$processed)
{
    $folderId = $folder['id'];

    if (isset($processed[$folderId])) {
        return; // Already added
    }

    // Add parent first (if exists)
    if (isset($folder['productFolder'])) {
        $parentId = $this->extractEntityId($folder['productFolder']['meta']['href']);
        if (isset($indexed[$parentId]) && !isset($processed[$parentId])) {
            $this->addFolderWithParents($indexed[$parentId], $indexed, $sorted, $processed);
        }
    }

    // Add current folder
    $sorted[] = $folder;
    $processed[$folderId] = true;
}
```

**Purpose:** Ensure parent folders are synced before children (respects hierarchy)

**Algorithm:** Depth-first traversal with memoization

## Integration Points

### 1. BatchEntityLoader::loadAndCreateAssortmentBatchTasks()

**Location:** `app/Services/BatchEntityLoader.php` (lines 207-228)

**When:** After filtering entities, before creating batch tasks

```php
// 4. Pre-sync групп товаров для ВСЕХ отфильтрованных сущностей (если настройка включена)
if ($syncSettings && $syncSettings->create_product_folders && !empty($allFilteredEntities)) {
    try {
        Log::info('Pre-syncing product folders for filtered entities', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'entity_count' => count($allFilteredEntities)
        ]);

        $productFolderSyncService = app(\App\Services\ProductFolderSyncService::class);
        $folderMappings = $productFolderSyncService->syncFoldersForEntities(
            $mainAccountId,
            $childAccountId,
            $allFilteredEntities
        );

        Log::info('Product folders pre-synced', [
            'folders_synced' => count($folderMappings)
        ]);
    } catch (\Exception $e) {
        Log::error('Failed to pre-sync product folders', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        // Continue with batch sync (folders will be synced individually if needed)
    }
}
```

**Why here:**
- After `$allFilteredEntities` is populated
- Before batch task creation
- Ensures folders exist before entities reference them

### 2. ProcessSyncQueueJob::processBatchProductSync()

**Location:** `app/Jobs/ProcessSyncQueueJob.php` (lines 542-575)

**When:** Processing batch of product sync tasks

```php
// 1. Фильтровать товары ПЕРЕД синхронизацией групп
$filteredProducts = [];
foreach ($products as $product) {
    if ($productSyncService->passesFilters($product, $syncSettings, 'product')) {
        $filteredProducts[] = $product;
    } else {
        // Log skipped products
        Log::debug('Product filtered out in batch', [
            'product_id' => $product['id'],
            'product_name' => $product['name'] ?? 'N/A'
        ]);
    }
}

Log::info('Products filtered', [
    'total' => count($products),
    'filtered' => count($filteredProducts),
    'skipped' => count($products) - count($filteredProducts)
]);

// 2. Pre-sync групп товаров (если настройка включена)
if ($syncSettings->create_product_folders && !empty($filteredProducts)) {
    try {
        $productFolderSyncService = app(\App\Services\ProductFolderSyncService::class);
        $folderMappings = $productFolderSyncService->syncFoldersForEntities(
            $mainAccountId,
            $childAccountId,
            $filteredProducts
        );

        Log::info('Product folders pre-synced for batch', [
            'folders_synced' => count($folderMappings)
        ]);
    } catch (\Exception $e) {
        Log::error('Failed to pre-sync product folders in batch', [
            'error' => $e->getMessage()
        ]);
    }
}

// 3. Prepare batch (uses cached folder mappings)
$batchData = [];
foreach ($filteredProducts as $product) {
    $prepared = $productSyncService->prepareProductForBatch($product, $syncSettings, $mainAccountId, $childAccountId);
    if ($prepared) {
        $batchData[] = $prepared;
    }
}
```

**Why here:**
- After filtering products
- Before `prepareProductForBatch()` (which expects cached folder mappings)
- Ensures folders synced only for products that will actually be created

### 3. ProcessSyncQueueJob::processBatchBundleSync()

**Similar implementation for bundles** (lines 677-710)

### 4. ProcessSyncQueueJob::processBatchServiceSync()

**Similar implementation for services** (lines 812-845)

### 5. ProductSyncService::prepareProductForBatch()

**Location:** `app/Services/ProductSyncService.php` (lines 311-332)

**When:** Preparing product data for batch POST

```php
// 8. ProductFolder - использовать CACHED маппинг (группы уже синхронизированы в processBatchProductSync)
if ($settings->create_product_folders && isset($product['productFolder']['id'])) {
    $folderId = $product['productFolder']['id'];

    // Check for existing folder mapping (created in PHASE 2.5)
    $folderMapping = \App\Models\EntityMapping::where([
        'parent_account_id' => $mainAccountId,
        'child_account_id' => $childAccountId,
        'entity_type' => 'productfolder',
        'parent_entity_id' => $folderId
    ])->first();

    if ($folderMapping) {
        // Use cached mapping → 0 additional API calls!
        $productData['productFolder'] = [
            'meta' => [
                'href' => "https://api.moysklad.ru/api/remap/1.2/entity/productfolder/{$folderMapping->child_entity_id}",
                'type' => 'productfolder',
                'mediaType' => 'application/json'
            ]
        ];
    } else {
        Log::warning('Product folder mapping not found (should have been pre-synced)', [
            'product_id' => $product['id'],
            'folder_id' => $folderId
        ]);
        // Don't include productFolder if mapping not found
        // This prevents API errors
    }
} else {
    // Setting disabled OR product has no folder → skip productFolder
}
```

**Why here:**
- Uses CACHED mapping (created in PHASE 2.5)
- NO additional API calls
- Graceful fallback if mapping missing

### 6. ProductSyncService::createProduct() / updateProduct()

**Location:** `app/Services/ProductSyncService.php` (lines 461-483, 529-551)

**When:** Individual product sync (webhooks, manual sync)

```php
// In createProduct() / updateProduct()

// Синхронизировать группу товара (если настройка включена)
if (isset($product['productFolder'])) {
    if ($settings->create_product_folders) {
        // Extract folder ID from href
        $folderHref = $product['productFolder']['meta']['href'] ?? null;
        if ($folderHref) {
            $folderId = $this->extractEntityId($folderHref);

            if ($folderId) {
                // Sync folder recursively (includes parent chain)
                $childFolderId = $this->productFolderSyncService->syncProductFolder(
                    $settings->account->parent_account_id ?? $settings->account->account_id,
                    $settings->account_id,
                    $folderId,
                    $settings
                );

                if ($childFolderId) {
                    $productData['productFolder'] = [
                        'meta' => [
                            'href' => "https://api.moysklad.ru/api/remap/1.2/entity/productfolder/{$childFolderId}",
                            'type' => 'productfolder',
                            'mediaType' => 'application/json'
                        ]
                    ];
                }
            }
        }
    }
    // If setting disabled → don't include productFolder in payload
}
```

**Why here:**
- Individual sync needs recursive folder sync (not pre-cached)
- Webhook-triggered updates (not part of batch)
- Manual product sync (not part of batch)

### 7. BundleSyncService::prepareBundleForBatch() / createBundle() / updateBundle()

**Similar implementation for bundles** (same pattern as ProductSyncService)

## Database Schema

### entity_mappings Table

```sql
CREATE TABLE entity_mappings (
    id BIGSERIAL PRIMARY KEY,
    parent_account_id UUID NOT NULL,
    child_account_id UUID NOT NULL,
    entity_type VARCHAR(50) NOT NULL,  -- 'productfolder' for folders
    parent_entity_id UUID NOT NULL,     -- Folder ID in main account
    child_entity_id UUID NOT NULL,      -- Folder ID in child account
    sync_direction VARCHAR(20) DEFAULT 'main_to_child',
    match_field VARCHAR(50),            -- 'name' for folders
    match_value TEXT,                   -- Folder name
    created_at TIMESTAMP,
    updated_at TIMESTAMP,

    UNIQUE(parent_account_id, child_account_id, entity_type, parent_entity_id)
);

-- Index for fast lookups during batch prepare
CREATE INDEX idx_entity_mappings_lookup ON entity_mappings(
    parent_account_id,
    child_account_id,
    entity_type,
    parent_entity_id
);
```

**Example mapping:**

```
parent_account_id: 12345678-1234-1234-1234-123456789012
child_account_id:  87654321-4321-4321-4321-210987654321
entity_type:       productfolder
parent_entity_id:  aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa  (Группа 1 in main)
child_entity_id:   bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb  (Группа 1 in child)
match_field:       name
match_value:       Группа 1
```

### sync_settings Table

```sql
ALTER TABLE sync_settings
ADD COLUMN create_product_folders BOOLEAN DEFAULT true
COMMENT 'Sync product folders (only for filtered entities). If false - create entities without groups';
```

## Batch Sync Flow (PHASE 2.5)

### Complete Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│ PHASE 1: Pre-cache Dependencies (DependencyCacheService)   │
├─────────────────────────────────────────────────────────────┤
│ ✅ Cache attributes (main → child mappings)                 │
│ ✅ Cache price types (main → child mappings)                │
│ ❌ DON'T cache folders (will be filtered in PHASE 2.5)      │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ PHASE 2: Load & Filter Entities (BatchEntityLoader)        │
├─────────────────────────────────────────────────────────────┤
│ 1. Load 100 products from main account (with expand)       │
│ 2. Apply ProductFilterService → 50 products pass           │
│ 3. Load 100 bundles → filter → 30 bundles pass             │
│ 4. Load 100 services → filter → 20 services pass           │
│                                                             │
│ Result: $allFilteredEntities = [50 products + 30 bundles   │
│                                  + 20 services]             │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ PHASE 2.5: Pre-sync Product Folders ⭐ NEW                  │
├─────────────────────────────────────────────────────────────┤
│ IF create_product_folders = true:                          │
│                                                             │
│ 1. Collect folder IDs from filtered entities:              │
│    - Extract productFolder.meta.href from all 100 entities │
│    - Unique IDs: [folder1, folder2, ..., folder15]         │
│                                                             │
│ 2. Load folders from main (batch GET):                     │
│    GET /entity/productfolder?filter=id=f1;f2;...;f15       │
│    → Returns 15 folders                                    │
│                                                             │
│ 3. Build dependency tree:                                  │
│    - Walk up parent chain for each folder                  │
│    - Load missing parents (individual GETs)                │
│    - Total folders needed: 15 + 5 parents = 20 folders     │
│                                                             │
│ 4. Sort by hierarchy (parents → children)                  │
│                                                             │
│ 5. Sync folders to child account:                          │
│    FOR each folder in sorted order:                        │
│      - Check if exists in child (by name + parent)         │
│      - Create/update folder                                │
│      - Store mapping in entity_mappings                    │
│                                                             │
│ Result: 20 folder mappings cached in DB                    │
│         (vs 1000 folders without filtering!)               │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ PHASE 3: Prepare Batch (ProductSyncService)                │
├─────────────────────────────────────────────────────────────┤
│ FOR each filtered entity:                                  │
│   1. Check if has productFolder field                      │
│   2. Lookup CACHED folder mapping (DB query)               │
│      → Uses mapping from PHASE 2.5                         │
│      → 0 additional API calls! ✅                           │
│   3. Build productData with child folder href              │
│   4. Add to batch array                                    │
│                                                             │
│ Result: $batchData = [product1, product2, ..., product50]  │
│         Each with correct child folder references          │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ PHASE 4: Batch POST (MoySkladService)                      │
├─────────────────────────────────────────────────────────────┤
│ POST /entity/product (batch create/update)                 │
│ Body: [product1, product2, ..., product50]                 │
│                                                             │
│ Response: [created1, created2, ..., created50]             │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ PHASE 5: Create Entity Mappings                            │
├─────────────────────────────────────────────────────────────┤
│ FOR each created entity:                                   │
│   - Store mapping (parent_id → child_id)                   │
│   - Mark sync_queue task as completed                      │
└─────────────────────────────────────────────────────────────┘
```

### Performance Comparison

**Before (all folders cached):**
- Pre-cache: Load ALL 1000 folders from main account
- Sync: Create/update 1000 folders in child account
- API calls: ~2000 (1000 GET + 1000 POST/PUT)
- Time: ~60 seconds (rate limited)

**After (filtered folders only):**
- Filter: 50 products pass filters
- Collect: 15 unique folders needed
- Load: 15 folders + 5 parents = 20 folders
- Sync: Create/update 20 folders in child account
- API calls: ~40 (20 GET + 20 POST/PUT)
- Time: ~2 seconds

**Improvement:** 98% fewer API calls, 97% faster!

## Filter Integration

### How Filters Affect Folder Sync

**Product Filters** (`sync_settings.product_filters`):
- Attribute-based: "Только товары с атрибутом 'Для франшизы' = true"
- Folder-based: "Только товары из групп [Группа 1, Группа 2]"
- Combined: "Атрибут X = Y AND группа in [...]"

**Folder Sync Logic:**

```php
// Step 1: Apply filters to products
$filteredProducts = array_filter($products, function($product) use ($filters) {
    return $this->productFilterService->passes($product, $filters);
});

// Step 2: Sync only folders from filtered products
if ($settings->create_product_folders) {
    $folderMappings = $this->productFolderSyncService->syncFoldersForEntities(
        $mainAccountId,
        $childAccountId,
        $filteredProducts  // Only products that passed filters!
    );
}
```

**Example Scenario:**

Main account structure:
```
├── Группа 1 (100 products)
│   ├── Подгруппа 1.1 (50 products) ← 10 products pass filters
│   └── Подгруппа 1.2 (50 products) ← 0 products pass filters
├── Группа 2 (200 products) ← 0 products pass filters
└── Группа 3 (50 products) ← 5 products pass filters
```

Synced folders:
```
✅ Группа 1 (parent of 1.1)
✅ Подгруппа 1.1 (has 10 filtered products)
✅ Группа 3 (has 5 filtered products)
❌ Подгруппа 1.2 (no filtered products)
❌ Группа 2 (no filtered products)
```

Result: 3 folders synced instead of 5

## Troubleshooting

### Issue: "Product folder mapping not found" Warning

**Symptom:**
```
[WARNING] Product folder mapping not found (should have been pre-synced)
product_id: xxxxx
folder_id: yyyyy
```

**Causes:**
1. Folder pre-sync failed in PHASE 2.5 (check error logs)
2. `create_product_folders = false` but prepare method tried to use folder
3. Race condition (unlikely with single queue worker)

**Fix:**
1. Check PHASE 2.5 logs for folder sync errors
2. Verify `create_product_folders` setting
3. Re-run sync for affected products

### Issue: "Entity with UUID xxx not found" in МойСклад API

**Symptom:** API returns 404 for productFolder reference

**Causes:**
1. Folder not synced (wrong UUID used)
2. Mapping points to non-existent folder in child
3. Folder was deleted in child account

**Fix:**
1. Delete folder mapping from `entity_mappings`
2. Re-run sync → folder will be recreated
3. Check child account for manually deleted folders

### Issue: Products Created in Root Level (No Groups)

**Symptom:** All products appear in root catalog, not in groups

**Causes:**
1. `create_product_folders = false` (expected behavior)
2. Products in main account have no `productFolder` field
3. Folder sync disabled by filters

**Fix:**
1. Check `sync_settings.create_product_folders` value
2. Verify products in main account have folders assigned
3. Check filter logic (may be excluding all folders)

### Issue: Empty Folders in Child Account

**Symptom:** Child account has folders but no products in them

**Causes:**
1. Products filtered out, but parent folders created for hierarchy
2. Products deleted from child, folders remain

**Expected Behavior:** This is normal! Folders are NOT deleted when products are filtered out. Only folder creation is controlled, not deletion.

**Fix:** If undesired, manually delete empty folders in child account

## Testing

### Unit Tests (Future)

```php
// tests/Unit/ProductFolderSyncServiceTest.php

public function test_sync_folders_for_entities_with_hierarchy()
{
    // Given: Main account with folder hierarchy
    // And: Child account without folders
    // And: Products in nested folders

    // When: syncFoldersForEntities() called

    // Then: Parent folders created first
    // And: Child folders created second
    // And: Mappings stored correctly
    // And: Hierarchy preserved
}

public function test_sync_folders_respects_create_product_folders_setting()
{
    // Given: create_product_folders = false

    // When: syncFoldersForEntities() called

    // Then: No folders synced
    // And: Products created without productFolder
}
```

### Manual Testing

1. **Setup:**
   ```bash
   # Create test products in main account
   # Assign to different folders (some nested)
   # Configure filters to match some products
   ```

2. **Test filtered sync:**
   ```bash
   # Set create_product_folders = true
   # Run "Sync All Products"
   # Verify: Only folders with filtered products synced
   # Verify: Hierarchy preserved
   ```

3. **Test disabled sync:**
   ```bash
   # Set create_product_folders = false
   # Run "Sync All Products"
   # Verify: No folders created
   # Verify: Products in root level
   ```

4. **Test hierarchy preservation:**
   ```bash
   # Product in: Root → Group 1 → Subgroup 1.1 → Subgroup 1.1.1
   # Run sync
   # Verify: All 4 levels created in child
   # Verify: Correct parent-child links
   ```

5. **Monitor logs:**
   ```bash
   tail -f storage/logs/laravel.log | grep -E "folder|PHASE 2.5"
   ```

## Future Improvements

### Possible Enhancements

1. **Folder Cleanup:**
   - Add option to delete empty folders in child accounts
   - Scheduled job to clean up orphaned folders
   - UI button: "Clean up empty folders"

2. **Folder Mapping UI:**
   - Allow manual folder mapping (main folder → different child folder)
   - Override automatic folder sync for specific folders
   - Exclude specific folders from sync

3. **Batch Optimization:**
   - Cache folder hierarchy in Redis (avoid parent lookups)
   - Pre-load entire folder tree once per account
   - Use expand=productFolder.productFolder.productFolder (3 levels)

4. **Monitoring:**
   - Track folder sync performance in `sync_statistics`
   - Count: `folders_synced`, `folders_skipped`, `folders_failed`
   - Alert on excessive folder sync (may indicate filter issue)

5. **Folder Filters:**
   - Separate filter for folders (independent of product filters)
   - Example: "Only sync folders matching pattern 'Франшиза*'"
   - Combine with product filters (AND logic)

## See Also

- [Batch Synchronization](04-batch-sync.md) - Complete batch sync architecture
- [Service Layer](05-services.md#product-folder-sync-service) - ProductFolderSyncService details
- [Database Structure](07-database.md#sync-settings-create_product_folders) - create_product_folders setting
- [Product Filters](PRODUCT_FILTERS.md) - Product filtering system
