# Architecture Overview
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
- Attributes, characteristics, prices, barcodes, **packs (упаковки)** ⭐
- **Queued sync via `sync_queue` table** (ProcessSyncQueueJob)
- **Deletion/archiving**: Archived in children (NOT deleted) when deleted in main
- **New features:**
  - Price mappings (main ↔ child)
  - Attribute filtering (sync only selected attributes)
  - Product match field (code/article/externalCode/barcode)
  - Optional product folder creation
  - Visual filter constructor for selective sync
  - **VAT & tax sync** with mode selection (from_main/preserve_child) ⭐
  - **Additional fields sync**: weight, volume, tracking, marking, alcoholic, Uzbekistan fields ⭐
  - **Packs (упаковки) sync**: quantity, UOM, barcodes with automatic UOM mapping ⭐

**Дополнительные поля (Attributes) - Логика синхронизации:**

**UI Filtering:**
- Excluded attribute types are NOT shown in selection list (filtered at API level)
- Excluded types: `counterparty`, `employee`, `store`, `organization`, `product`
- These types are managed separately through target objects settings
- Filtering happens in `SyncSettingsController::getAttributes()` and `getBatchData()`

**Sync Logic (`SyncHelpers::syncAttributes()`):**
- **Empty `attribute_sync_list`** → NO attributes synced at all (returns `[]`)
- **Filled `attribute_sync_list`** → Only selected attributes synced
- Attributes NOT in the list are skipped (logged with `debug` level)

**CustomEntity Attribute Handling (`SyncHelpers::createAttributeInChild()`):**
- For `type: customentity` attributes, requires `customEntityMeta` reference
- **Fallback logic:**
  1. Try to get `customEntityMeta.name` from attribute data
  2. If missing, load customEntity by `href` using `MoySkladService::getEntity()`
  3. Extract name from loaded entity
  4. If still no name → return `null` (skip attribute with warning)
- Syncs custom dictionary using `CustomEntitySyncService::syncCustomEntity()`
- Creates mapping in `custom_entity_mappings` table

**Important Notes:**
- Attributes are checked/created BEFORE syncing products (pre-sync)
- Mapping stored in `attribute_mappings` table (checked first, then created if needed)
- Sync by `name` + `type` match (attributes don't have universal codes like standard entities)
- Failed attribute sync doesn't block product sync (gracefully skipped)

**Validation Before Sync:**
- Products/variants/bundles/services are **SKIPPED** if `product_match_field` value is empty
- Example: if `product_match_field = 'article'` but product has no article → skip sync
- Logged as warning: `"Entity skipped: match field '{field}' is empty"`
- Files implementing validation:
  * `ProductSyncService::syncProduct()` - Checks article/code/externalCode
  * `VariantSyncService::syncVariant()` - Checks article/code/externalCode (if applicable)
  * `BundleSyncService::syncBundle()` - Checks article/code/externalCode
  * `ServiceSyncService::syncService()` - Checks code/externalCode (default: code)
- **Why critical:** МойСклад API returns error if match field (article/code) is sent as empty string

**Metadata Merging for CustomEntity Attributes:**

**Problem:** МойСклад API returns attribute metadata and values separately:
- `GET entity/product/{id}?expand=attributes` → Returns attribute **VALUES** only (no `customEntityMeta`)
- `GET entity/product/metadata/attributes` → Returns attribute **METADATA** (includes `customEntityMeta`)

**Impact:** When syncing products/services/bundles with customentity attributes, `customEntityMeta` is missing, causing API error: "поле 'customEntityMeta' не может быть пустым"

**Solution:** Pre-load metadata, merge `customEntityMeta` into attributes before calling `syncAttributes()`

**Implementation:**

1. **Endpoint used:** `entity/product/metadata/attributes` (same for товары, услуги, комплекты)
2. **Caching strategy:** `$attributesMetadataCache` array indexed by `mainAccountId` (prevents duplicate API calls)
3. **Merging location:** In `syncProduct()`, `syncService()`, `syncBundle()` methods AFTER loading entity, BEFORE sync

**Files implementing metadata merging:**
- `AttributeSyncService.php` - contains `loadAttributesMetadata()` method
- `ProductSyncService.php` - calls `$this->attributeSyncService->loadAttributesMetadata()`
- `BundleSyncService.php` - calls `$this->attributeSyncService->loadAttributesMetadata()`
- `ServiceSyncService.php` - calls `$this->attributeSyncService->loadAttributesMetadata()`

**Code example:**

```php
// In AttributeSyncService.php
// Load attributes metadata once per account
protected function loadAttributesMetadata(string $mainAccountId): array
{
    // Check cache first
    if (isset($this->attributesMetadataCache[$mainAccountId])) {
        return $this->attributesMetadataCache[$mainAccountId];
    }

    $response = $this->moySkladService
        ->setAccessToken($mainAccount->access_token)
        ->get('entity/product/metadata/attributes');

    $metadata = [];
    foreach ($response['data']['rows'] ?? [] as $attr) {
        if (isset($attr['id'])) {
            $metadata[$attr['id']] = $attr; // O(1) lookup by ID
        }
    }

    $this->attributesMetadataCache[$mainAccountId] = $metadata;
    return $metadata;
}

// In ProductSyncService.php, BundleSyncService.php, ServiceSyncService.php
// Merge metadata with values before sync
if (isset($product['attributes']) && is_array($product['attributes'])) {
    $attributesMetadata = $this->attributeSyncService->loadAttributesMetadata($mainAccountId);

    foreach ($product['attributes'] as &$attr) {
        $attrId = $attr['id'] ?? null;
        if ($attrId && isset($attributesMetadata[$attrId])) {
            // Add customEntityMeta from metadata (if exists)
            if (isset($attributesMetadata[$attrId]['customEntityMeta'])) {
                $attr['customEntityMeta'] = $attributesMetadata[$attrId]['customEntityMeta'];
            }
        }
    }
    unset($attr); // Release reference
}
```

**Performance benefits:**
- Metadata loaded ONCE per account (cached)
- O(1) lookup by attribute ID
- No additional API calls per product

**Important notes:**
- Модификации (variants) DON'T have доп.поля (no merging needed)
- `customEntityMeta` structure: `{href, type, mediaType}` (NO `name` field in metadata)
- Fallback in `SyncHelpers::createAttributeInChild()` loads customEntity by href if name needed

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

