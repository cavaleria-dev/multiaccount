# Entity Mapping Fixes - Existing Entities (2025-10-28)

## Problem Summary

Found **3 critical issues** with entity mapping creation that could cause duplicate entities in child accounts when manually created entities exist.

## Issues Fixed

### 1. EntityMappingService: Incorrect use of `firstOrCreate`

**Files:** `app/Services/EntityMappingService.php`

**Methods affected:**
- `findOrCreateServiceMapping()` (line 90)
- `findOrCreateProductMapping()` (line 176)
- `findOrCreateBundleMapping()` (line 262)

**Problem:**
```php
// BEFORE - sync_direction in search criteria but NOT in unique constraint
$mapping = EntityMapping::firstOrCreate(
    [
        'parent_account_id' => $mainAccountId,
        'child_account_id' => $childAccountId,
        'entity_type' => 'service',
        'parent_entity_id' => $mainServiceId,
        'sync_direction' => 'main_to_child',  // ❌ NOT in unique constraint!
    ],
    [...]
);
```

**Issue:** `sync_direction` field was included in search criteria, but the unique constraint is:
```sql
UNIQUE (parent_account_id, child_account_id, entity_type, parent_entity_id)
```

This mismatch could cause race conditions and inconsistent behavior.

**Fix:**
```php
// AFTER - use updateOrCreate, move sync_direction to update fields
$mapping = EntityMapping::updateOrCreate(
    [
        // Unique keys (must match unique constraint)
        'parent_account_id' => $mainAccountId,
        'child_account_id' => $childAccountId,
        'entity_type' => 'service',
        'parent_entity_id' => $mainServiceId,
    ],
    [
        // Fields to update/create
        'child_entity_id' => $childService['id'],
        'sync_direction' => 'main_to_child',
        'match_field' => $matchField,
        'match_value' => $matchValue,
    ]
);
```

---

### 2. ProductSyncService::prepareProductForBatch() - Missing search for existing products

**File:** `app/Services/ProductSyncService.php:243-248`

**Problem:**
```php
// BEFORE - only checks mapping in DB
$mapping = EntityMapping::where([
    'parent_account_id' => $mainAccountId,
    'child_account_id' => $childAccountId,
    'entity_type' => 'product',
    'parent_entity_id' => $product['id']
])->first();
```

**Issue:** If a product exists in child account (created manually or by previous sync) but has no mapping in DB:
- ✅ Mapping check fails (no mapping found)
- ❌ Code does NOT search for product by match_field in child
- ❌ Creates DUPLICATE product in batch POST
- ❌ Duplicate product fails with error OR creates second copy

**Fix:**
```php
// AFTER - searches for existing product in child by match_field
$matchValue = ($matchField === 'name')
    ? ($product['name'] ?? null)
    : ($product[$matchField] ?? null);

$mapping = $this->entityMappingService->findOrCreateProductMapping(
    $mainAccountId,
    $childAccountId,
    $product['id'],
    $matchField,
    $matchValue
);
```

**What findOrCreateProductMapping() does:**
1. Checks mapping in DB
2. If not found → searches child account for product by `match_field` (article/code/externalCode)
3. If found in child → creates mapping, returns existing
4. If not found → returns null (batch will create new)

---

### 3. ServiceSyncService::prepareServiceForBatch() - Missing search for existing services

**File:** `app/Services/ServiceSyncService.php:533-538`

**Same problem as products** - only checked mapping, didn't search for existing services in child.

**Fix:**
```php
// AFTER
$matchValue = ($matchField === 'name')
    ? ($service['name'] ?? null)
    : ($service[$matchField] ?? null);

$mapping = $this->entityMappingService->findOrCreateServiceMapping(
    $mainAccountId,
    $childAccountId,
    $service['id'],
    $matchField,
    $matchValue
);
```

---

### 4. All createXXX() methods - Replaced `firstOrCreate` with `updateOrCreate`

**Files:**
- `ProductSyncService::createProduct()` - line 627
- `ServiceSyncService::createService()` - line 323
- `BundleSyncService::createBundle()` - line 326
- `VariantSyncService` - line 420

**Change:**
```php
// BEFORE
EntityMapping::firstOrCreate(
    [
        'parent_account_id' => $mainAccountId,
        'child_account_id' => $childAccountId,
        'entity_type' => 'product',
        'parent_entity_id' => $product['id'],
        'sync_direction' => 'main_to_child',  // ❌ Not in unique constraint
    ],
    [
        'child_entity_id' => $newProduct['id'],
    ]
);

// AFTER
EntityMapping::updateOrCreate(
    [
        'parent_account_id' => $mainAccountId,
        'child_account_id' => $childAccountId,
        'entity_type' => 'product',
        'parent_entity_id' => $product['id'],
    ],
    [
        'child_entity_id' => $newProduct['id'],
        'sync_direction' => 'main_to_child',  // ✅ Moved to update fields
        'match_field' => $matchField,
        'match_value' => $matchValue,
    ]
);
```

---

## Impact

### Before Fix

**Scenario:**
1. User manually creates product "Product A" with `article="ABC123"` in child account
2. User creates same product in main account with `article="ABC123"`
3. User runs "Sync All Products" from main to child

**Result:**
- ❌ Batch sync does NOT find existing product (only checks mapping)
- ❌ Creates DUPLICATE "Product A" in child
- ❌ Now have 2 products with same article in child account
- ❌ OR gets 412 error if МойСклад prevents duplicate

### After Fix

**Same scenario:**
1. User manually creates product "Product A" with `article="ABC123"` in child account
2. User creates same product in main account with `article="ABC123"`
3. User runs "Sync All Products" from main to child

**Result:**
- ✅ Batch sync calls `findOrCreateProductMapping()`
- ✅ EntityMappingService searches child by `article="ABC123"`
- ✅ Finds existing product, creates mapping
- ✅ Returns mapping to batch sync
- ✅ Batch sync treats as UPDATE (not CREATE)
- ✅ No duplicate, existing product gets updated

---

## Testing

### Manual Test

**Setup:**
1. Create test product/service manually in child account:
   - Name: "Test Product Manual"
   - Article: "MANUAL-001"
   - Code: "MANUAL-CODE"

2. Create same product in main account:
   - Name: "Test Product Manual"
   - Article: "MANUAL-001"
   - Code: "MANUAL-CODE"

3. Set `product_match_field = article` in sync settings

**Test:**
```bash
# Run batch sync from main to child
# Monitor logs
tail -f storage/logs/laravel.log | grep -E "Product found in child|Creating mapping"
```

**Expected result:**
```
[INFO] Searching for product in child by match field
  match_field: article
  match_value: MANUAL-001

[INFO] Product found in child - creating mapping
  main_product_id: <main_id>
  child_product_id: <child_id>
  product_name: Test Product Manual
  match_field: article
  match_value: MANUAL-001
```

**Verify:**
- ✅ Only ONE product "Test Product Manual" in child account
- ✅ Mapping exists in `entity_mappings` table
- ✅ Product was updated (not created)

---

## Files Changed

1. **app/Services/EntityMappingService.php**
   - `findOrCreateServiceMapping()` - replaced `firstOrCreate` with `updateOrCreate`
   - `findOrCreateProductMapping()` - replaced `firstOrCreate` with `updateOrCreate`
   - `findOrCreateBundleMapping()` - replaced `firstOrCreate` with `updateOrCreate`

2. **app/Services/ProductSyncService.php**
   - `prepareProductForBatch()` - added call to `findOrCreateProductMapping()`
   - `createProduct()` - replaced `firstOrCreate` with `updateOrCreate`

3. **app/Services/ServiceSyncService.php**
   - `prepareServiceForBatch()` - added call to `findOrCreateServiceMapping()`
   - `createService()` - replaced `firstOrCreate` with `updateOrCreate`

4. **app/Services/BundleSyncService.php**
   - `createBundle()` - replaced `firstOrCreate` with `updateOrCreate`

5. **app/Services/VariantSyncService.php**
   - `syncVariant()` - replaced `firstOrCreate` with `updateOrCreate`

---

## Prevention

After this fix:
- ✅ Batch sync checks for existing entities by `match_field` before creating
- ✅ Manually created entities are linked via mapping (not duplicated)
- ✅ `updateOrCreate` correctly aligns with unique constraint
- ✅ Race conditions prevented by proper unique key usage

---

## Related

- [Product Folder Sync Fixes](14-product-folder-sync.md#issue-duplicate-folders-in-child-account) - Similar issue with folders
- [Entity Mapping Service](05-services.md#entitymappingservice) - Service documentation
- [Database Structure](07-database.md#entity_mappings) - entity_mappings table structure

---

## See Also

- **Commit:** (TBD - будет добавлен после коммита)
- **Issue:** Duplicate entities created when manually created entities exist in child account
- **Fix Date:** 2025-10-28
