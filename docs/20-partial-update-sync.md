# Partial Update Synchronization - Technical Plan

> **Status:** 📋 Planning
> **Priority:** High
> **Estimated effort:** 40-60 hours
> **Dependencies:** Webhook system (18-webhook-system.md)

---

## 📚 Table of Contents

1. [Problem Statement](#problem-statement)
2. [Solution Architecture](#solution-architecture)
3. [МойСклад updatedFields Format](#moysklad-updatedfields-format)
4. [Phase 1: Infrastructure](#phase-1-infrastructure)
5. [Phase 2: Field Classification](#phase-2-field-classification)
6. [Phase 3: Update Strategy](#phase-3-update-strategy)
7. [Phase 4: Partial Update Service](#phase-4-partial-update-service)
8. [Phase 5: Integration](#phase-5-integration)
9. [Phase 6: Testing](#phase-6-testing)
10. [Migration from Full Sync](#migration-from-full-sync)

---

## Problem Statement

### Current Situation

**Full synchronization on every UPDATE webhook:**
- Product name changes → full product sync (all fields + dependencies)
- Price changes → full product sync (unnecessary attributes, folders, etc.)
- Attribute changes → full product sync (redundant API calls)

**Impact:**
- ❌ Excessive API calls (could trigger rate limits)
- ❌ Slower sync (100 fields updated when only 1 changed)
- ❌ No audit trail of what actually changed
- ❌ Wasted resources syncing unchanged data

### Desired State

**Intelligent partial synchronization:**
- Price change → update ONLY prices (1 PUT request with price data)
- Attribute change → update ONLY attributes
- Name change → update ONLY base fields
- Complex dependency change (productFolder) → fallback to full sync

**Benefits:**
- ✅ 80-95% reduction in unnecessary API calls for UPDATE webhooks
- ✅ Faster sync (seconds vs minutes for large products)
- ✅ Audit trail in `entity_update_logs` table
- ✅ Configurable filtering by `sync_settings`

---

## Solution Architecture

### High-Level Flow

```
МойСклад UPDATE webhook
    ↓
WebhookController → WebhookReceiverService
    ↓ Extract updatedFields from payload
webhook_logs (save updatedFields)
    ↓
ProcessWebhookJob → WebhookProcessorService
    ↓
BatchSyncService (add updatedFields to sync_queue payload)
    ↓
sync_queue tasks created (one per child account)
    ↓
ProductSyncHandler::handle()
    ↓
┌─────────────────────────────────────────┐
│ IF updated_fields present:              │
│   1. FieldClassifierService              │
│   2. UpdateStrategyService               │
│   3. PartialUpdateService                │
│   4. Log to entity_update_logs           │
│ ELSE:                                    │
│   Fallback → Full sync                   │
└─────────────────────────────────────────┘
```

### Key Components

| Component | Purpose |
|-----------|---------|
| **entity_update_logs** | Audit trail table (universal for all entities) |
| **FieldClassifierService** | Classify updatedFields into categories |
| **UpdateStrategyService** | Determine update strategy based on fields + settings |
| **PartialUpdateService** | Execute partial updates (universal service) |
| **HandlesPartialUpdates** | Trait for all sync handlers to reuse logic |

---

## МойСклад updatedFields Format

### Real-World Examples

**Example 1: Mixed update (attributes + folder + custom prices)**
```json
{
  "events": [{
    "meta": {"type": "product", "href": "..."},
    "updatedFields": [
      "Франшиза москва",      // Custom attribute (by name!)
      "Тест цены",            // Custom price type (by name!)
      "productFolder",        // Standard API field
      "Франшиза 2",           // Custom attribute
      "Цена упаковки"         // Custom price type
    ],
    "action": "UPDATE",
    "accountId": "..."
  }]
}
```

**Example 2: Price update**
```json
{
  "events": [{
    "updatedFields": [
      "buyPrice",       // Standard field (API name)
      "minPrice",       // Standard field
      "salePrices",     // Standard field
      "Тест цены"       // Custom price type (by name!)
    ],
    "action": "UPDATE"
  }]
}
```

### Key Insights

**Three types of fields in updatedFields:**

1. **Standard API fields** → by API name
   - `buyPrice`, `salePrices`, `minPrice`, `productFolder`, `name`, `description`, `article`, `code`, `uom`, `country`, etc.

2. **Custom attributes** → by human-readable name
   - "Франшиза москва", "Франшиза 2", "Цвет", "Размер", etc.
   - Need to lookup attribute UUID by name, then find mapping

3. **Custom price types** → by human-readable name
   - "Тест цены", "Цена упаковки", "Оптовая цена", etc.
   - Need to lookup price type UUID by name, then check `price_mappings`

---

## Phase 1: Infrastructure

### 1.1 Migration: Add `updated_fields` to webhook_logs

**File:** `database/migrations/YYYY_MM_DD_add_updated_fields_to_webhook_logs.php`

```php
public function up(): void
{
    Schema::table('webhook_logs', function (Blueprint $table) {
        $table->json('updated_fields')->nullable()->after('payload');
    });
}

public function down(): void
{
    Schema::table('webhook_logs', function (Blueprint $table) {
        $table->dropColumn('updated_fields');
    });
}
```

### 1.2 Migration: Create entity_update_logs table

**File:** `database/migrations/YYYY_MM_DD_create_entity_update_logs_table.php`

```php
public function up(): void
{
    Schema::create('entity_update_logs', function (Blueprint $table) {
        $table->id();

        // Accounts and entity identification
        $table->uuid('main_account_id');
        $table->uuid('child_account_id');
        $table->string('entity_type', 50); // product, service, variant, order, etc.
        $table->string('main_entity_id');
        $table->string('child_entity_id')->nullable();

        // Update strategy and classification
        $table->string('update_strategy', 50); // SKIP, FULL_SYNC, PRICES_ONLY, etc.
        $table->json('updated_fields_received'); // Raw from МойСклад
        $table->json('fields_classified'); // After FieldClassifier
        $table->json('fields_applied'); // Actually updated
        $table->json('fields_skipped')->nullable(); // Filtered by settings

        // Traceability
        $table->string('webhook_request_id')->nullable();
        $table->unsignedBigInteger('sync_queue_id')->nullable();

        // Status and performance
        $table->string('status', 20); // processing, completed, failed
        $table->text('error_message')->nullable();
        $table->integer('processing_time_ms')->nullable();

        $table->timestamps();

        // Indexes for analytics and monitoring
        $table->index(['main_account_id', 'entity_type', 'created_at']);
        $table->index(['child_account_id', 'entity_type', 'created_at']);
        $table->index(['status', 'created_at']);
        $table->index('webhook_request_id');
        $table->index('sync_queue_id');
    });
}
```

### 1.3 Model: EntityUpdateLog

**File:** `app/Models/EntityUpdateLog.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntityUpdateLog extends Model
{
    protected $fillable = [
        'main_account_id',
        'child_account_id',
        'entity_type',
        'main_entity_id',
        'child_entity_id',
        'update_strategy',
        'updated_fields_received',
        'fields_classified',
        'fields_applied',
        'fields_skipped',
        'webhook_request_id',
        'sync_queue_id',
        'status',
        'error_message',
        'processing_time_ms',
    ];

    protected $casts = [
        'updated_fields_received' => 'array',
        'fields_classified' => 'array',
        'fields_applied' => 'array',
        'fields_skipped' => 'array',
    ];

    // Relationships
    public function mainAccount()
    {
        return $this->belongsTo(Account::class, 'main_account_id', 'account_id');
    }

    public function childAccount()
    {
        return $this->belongsTo(Account::class, 'child_account_id', 'account_id');
    }

    public function webhookLog()
    {
        return $this->belongsTo(WebhookLog::class, 'webhook_request_id', 'request_id');
    }

    public function syncTask()
    {
        return $this->belongsTo(SyncQueue::class, 'sync_queue_id');
    }

    // Helper methods
    public function isSuccessful(): bool
    {
        return $this->status === 'completed';
    }

    public function markAsCompleted(array $appliedFields, int $processingTimeMs): void
    {
        $this->update([
            'status' => 'completed',
            'fields_applied' => $appliedFields,
            'processing_time_ms' => $processingTimeMs,
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }
}
```

### 1.4 Update WebhookLog model

**File:** `app/Models/WebhookLog.php`

```php
protected $fillable = [
    // ... existing fields
    'updated_fields', // NEW
];

protected $casts = [
    // ... existing casts
    'updated_fields' => 'array', // NEW
];
```

---

## Phase 2: Field Classification

### 2.1 FieldClassifierService

**File:** `app/Services/Webhook/FieldClassifierService.php`

```php
<?php

namespace App\Services\Webhook;

use App\Models\CustomAttribute;
use App\Models\PriceType;
use Illuminate\Support\Facades\Cache;

/**
 * FieldClassifierService
 *
 * Classifies updatedFields from МойСклад webhook into categories:
 * - Standard API fields (buyPrice, productFolder, name, etc.)
 * - Custom attributes (by name: "Франшиза москва", etc.)
 * - Custom price types (by name: "Тест цены", etc.)
 */
class FieldClassifierService
{
    /**
     * Standard fields by entity type
     */
    const ENTITY_FIELDS = [
        'product' => [
            'base' => ['name', 'description', 'article', 'code', 'externalCode'],
            'prices' => ['buyPrice', 'salePrices', 'minPrice'],
            'complex_deps' => ['productFolder', 'uom', 'country', 'packs'],
            'simple' => ['barcodes', 'archived', 'vat', 'weight', 'volume', 'onTap', 'weighed', 'trackingType', 'tnved'],
        ],
        'service' => [
            'base' => ['name', 'description', 'article', 'code', 'externalCode'],
            'prices' => ['buyPrice', 'salePrices', 'minPrice'],
            'complex_deps' => ['productFolder'],
            'simple' => ['barcodes', 'archived', 'vat'],
        ],
        'variant' => [
            'base' => ['name', 'code', 'externalCode'],
            'prices' => ['buyPrice', 'salePrices', 'minPrice'],
            'complex_deps' => [],
            'simple' => ['barcodes', 'archived'],
        ],
        'bundle' => [
            'base' => ['name', 'description', 'article', 'code', 'externalCode'],
            'prices' => ['buyPrice', 'salePrices', 'minPrice'],
            'complex_deps' => ['productFolder'],
            'simple' => ['barcodes', 'archived', 'vat', 'weight', 'volume'],
        ],
    ];

    /**
     * Classify updatedFields into categories
     *
     * @param string $entityType Entity type (product, service, variant, etc.)
     * @param array $updatedFields Fields from МойСклад webhook
     * @param string $mainAccountId Main account UUID (for attribute/price lookup)
     * @return array Classification result
     */
    public function classify(string $entityType, array $updatedFields, string $mainAccountId): array
    {
        // Get known standard fields for this entity type
        $entityConfig = self::ENTITY_FIELDS[$entityType] ?? [];
        $allStandardFields = [];
        foreach ($entityConfig as $category => $fields) {
            $allStandardFields = array_merge($allStandardFields, $fields);
        }

        // Classify each field
        $standard = [];
        $customAttributes = [];
        $customPriceTypes = [];

        foreach ($updatedFields as $field) {
            if (in_array($field, $allStandardFields)) {
                // Standard API field
                $standard[] = $field;
            } else {
                // Custom field - determine if attribute or price type
                if ($this->isCustomAttribute($field, $mainAccountId, $entityType)) {
                    $customAttributes[] = $field;
                } else {
                    // Assume it's a custom price type
                    $customPriceTypes[] = $field;
                }
            }
        }

        // Determine flags
        $complexDepFields = $entityConfig['complex_deps'] ?? [];
        $priceFields = $entityConfig['prices'] ?? [];

        return [
            'entity_type' => $entityType,
            'standard' => $standard,
            'custom_attributes' => $customAttributes,
            'custom_price_types' => $customPriceTypes,
            'has_complex_deps' => !empty(array_intersect($standard, $complexDepFields)),
            'has_prices' => !empty(array_intersect($standard, $priceFields)) || !empty($customPriceTypes),
            'has_base_fields' => !empty(array_intersect($standard, $entityConfig['base'] ?? [])),
        ];
    }

    /**
     * Check if field name is a custom attribute
     *
     * @param string $fieldName Field name (e.g., "Франшиза москва")
     * @param string $accountId Account UUID
     * @param string $entityType Entity type
     * @return bool
     */
    protected function isCustomAttribute(string $fieldName, string $accountId, string $entityType): bool
    {
        $cacheKey = "custom_attr:{$accountId}:{$entityType}:{$fieldName}";

        return Cache::remember($cacheKey, 600, function () use ($fieldName, $accountId, $entityType) {
            return CustomAttribute::where('account_id', $accountId)
                ->where('entity_type', $entityType)
                ->where('name', $fieldName)
                ->exists();
        });
    }
}
```

---

## Phase 3: Update Strategy

### 3.1 UpdateStrategyService

**File:** `app/Services/Webhook/UpdateStrategyService.php`

```php
<?php

namespace App\Services\Webhook;

use App\Models\SyncSetting;
use App\Models\PriceType;
use Illuminate\Support\Facades\Cache;

/**
 * UpdateStrategyService
 *
 * Determines the best update strategy based on:
 * - Classified fields (from FieldClassifierService)
 * - Child account sync_settings
 */
class UpdateStrategyService
{
    const STRATEGY_SKIP = 'SKIP';
    const STRATEGY_FULL_SYNC = 'FULL_SYNC';
    const STRATEGY_PRICES_ONLY = 'PRICES_ONLY';
    const STRATEGY_ATTRIBUTES_ONLY = 'ATTRIBUTES_ONLY';
    const STRATEGY_BASE_FIELDS_ONLY = 'BASE_FIELDS_ONLY';
    const STRATEGY_MIXED_SIMPLE = 'MIXED_SIMPLE';

    /**
     * Determine update strategy
     *
     * @param array $classified Result from FieldClassifierService
     * @param SyncSetting $settings Child account sync settings
     * @param string $mainAccountId Main account UUID (for price lookup)
     * @return array Strategy data
     */
    public function determine(array $classified, SyncSetting $settings, string $mainAccountId): array
    {
        // 1. Filter fields by sync_settings
        $filtered = $this->filterBySettings($classified, $settings, $mainAccountId);

        // 2. Check if everything was filtered out
        if ($this->isEmpty($filtered)) {
            return [
                'strategy' => self::STRATEGY_SKIP,
                'reason' => 'All fields filtered by sync_settings',
                'filtered' => $filtered,
            ];
        }

        // 3. Check for complex dependencies → always full sync
        if ($classified['has_complex_deps']) {
            $complexFields = array_intersect(
                $classified['standard'],
                FieldClassifierService::ENTITY_FIELDS[$classified['entity_type']]['complex_deps'] ?? []
            );

            return [
                'strategy' => self::STRATEGY_FULL_SYNC,
                'reason' => 'Complex dependencies detected',
                'complex_fields' => $complexFields,
            ];
        }

        // 4. Determine strategy by composition
        $hasOnlyPrices = $this->hasOnlyPrices($filtered);
        $hasOnlyAttributes = $this->hasOnlyAttributes($filtered);
        $hasOnlyBaseFields = $this->hasOnlyBaseFields($filtered, $classified['entity_type']);

        if ($hasOnlyPrices) {
            return [
                'strategy' => self::STRATEGY_PRICES_ONLY,
                'standard_prices' => $filtered['standard_prices'],
                'custom_prices' => $filtered['custom_price_types'],
            ];
        }

        if ($hasOnlyAttributes) {
            return [
                'strategy' => self::STRATEGY_ATTRIBUTES_ONLY,
                'attributes' => $filtered['custom_attributes'],
            ];
        }

        if ($hasOnlyBaseFields) {
            return [
                'strategy' => self::STRATEGY_BASE_FIELDS_ONLY,
                'base_fields' => $filtered['base_fields'],
            ];
        }

        // 5. Mixed strategy (multiple simple field types)
        return [
            'strategy' => self::STRATEGY_MIXED_SIMPLE,
            'standard_fields' => $filtered['standard_non_price'],
            'base_fields' => $filtered['base_fields'],
            'standard_prices' => $filtered['standard_prices'],
            'custom_prices' => $filtered['custom_price_types'],
            'attributes' => $filtered['custom_attributes'],
        ];
    }

    /**
     * Filter classified fields by sync_settings
     */
    protected function filterBySettings(array $classified, SyncSetting $settings, string $mainAccountId): array
    {
        $entityType = $classified['entity_type'];
        $entityConfig = FieldClassifierService::ENTITY_FIELDS[$entityType] ?? [];

        // Base fields - always allowed if sync enabled
        $baseFields = array_intersect($classified['standard'], $entityConfig['base'] ?? []);

        // Price fields - filter by sync_prices and price_mappings
        $priceFields = array_intersect($classified['standard'], $entityConfig['prices'] ?? []);
        $standardPrices = $settings->sync_prices ? $priceFields : [];

        $customPriceTypes = $settings->sync_prices
            ? $this->filterPricesByMappings($classified['custom_price_types'], $settings, $mainAccountId)
            : [];

        // Attributes - filter by attribute_sync_list
        $customAttributes = $this->filterAttributesByList(
            $classified['custom_attributes'],
            $settings->attribute_sync_list ?? [],
            $mainAccountId
        );

        // Other standard fields (non-base, non-price)
        $otherStandard = array_diff(
            $classified['standard'],
            $baseFields,
            $priceFields
        );

        return [
            'base_fields' => $baseFields,
            'standard_prices' => $standardPrices,
            'custom_price_types' => $customPriceTypes,
            'custom_attributes' => $customAttributes,
            'standard_non_price' => $otherStandard,
        ];
    }

    /**
     * Filter custom price types by price_mappings
     * CRITICAL: Only sync prices that are mapped in sync_settings!
     */
    protected function filterPricesByMappings(array $customPriceNames, SyncSetting $settings, string $mainAccountId): array
    {
        if (empty($settings->price_mappings)) {
            return []; // No price mappings configured
        }

        $priceMappings = $settings->price_mappings; // ['main_uuid' => 'child_uuid']
        $allowedPrices = [];

        foreach ($customPriceNames as $priceName) {
            // Find price type UUID by name in main account
            $priceType = $this->findPriceTypeByName($priceName, $mainAccountId);

            if ($priceType && isset($priceMappings[$priceType->uuid])) {
                // This price type is mapped → allow it
                $allowedPrices[] = $priceName;
            }
        }

        return $allowedPrices;
    }

    /**
     * Filter custom attributes by attribute_sync_list
     */
    protected function filterAttributesByList(array $customAttributeNames, array $syncList, string $mainAccountId): array
    {
        if (empty($syncList)) {
            return []; // No attributes configured for sync
        }

        // TODO: Implement attribute filtering by sync_list
        // For now, allow all custom attributes if sync_list is not empty
        return $customAttributeNames;
    }

    /**
     * Find price type by name (with caching)
     */
    protected function findPriceTypeByName(string $name, string $accountId): ?PriceType
    {
        $cacheKey = "price_type:name:{$accountId}:{$name}";

        return Cache::remember($cacheKey, 600, function () use ($name, $accountId) {
            return PriceType::where('account_id', $accountId)
                ->where('name', $name)
                ->first();
        });
    }

    // Helper methods
    protected function isEmpty(array $filtered): bool
    {
        return empty($filtered['base_fields'])
            && empty($filtered['standard_prices'])
            && empty($filtered['custom_price_types'])
            && empty($filtered['custom_attributes'])
            && empty($filtered['standard_non_price']);
    }

    protected function hasOnlyPrices(array $filtered): bool
    {
        return (
            !empty($filtered['standard_prices']) || !empty($filtered['custom_price_types'])
        ) && (
            empty($filtered['base_fields'])
            && empty($filtered['custom_attributes'])
            && empty($filtered['standard_non_price'])
        );
    }

    protected function hasOnlyAttributes(array $filtered): bool
    {
        return !empty($filtered['custom_attributes']) && (
            empty($filtered['base_fields'])
            && empty($filtered['standard_prices'])
            && empty($filtered['custom_price_types'])
            && empty($filtered['standard_non_price'])
        );
    }

    protected function hasOnlyBaseFields(array $filtered, string $entityType): bool
    {
        return !empty($filtered['base_fields']) && (
            empty($filtered['standard_prices'])
            && empty($filtered['custom_price_types'])
            && empty($filtered['custom_attributes'])
            && empty($filtered['standard_non_price'])
        );
    }
}
```

---

## Phase 4: Partial Update Service

### 4.1 PartialUpdateService

**File:** `app/Services/Webhook/PartialUpdateService.php`

```php
<?php

namespace App\Services\Webhook;

use App\Models\Account;
use App\Models\EntityUpdateLog;
use App\Models\SyncSetting;
use App\Services\MoySkladService;
use Illuminate\Support\Facades\Log;

/**
 * PartialUpdateService
 *
 * Universal service for executing partial entity updates
 * Works with any entity type (product, service, variant, order, etc.)
 */
class PartialUpdateService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Execute partial update based on strategy
     *
     * @param string $entityType Entity type (product, service, etc.)
     * @param Account $mainAccount Main account
     * @param Account $childAccount Child account
     * @param string $mainEntityId Entity ID in main account
     * @param string $childEntityId Entity ID in child account
     * @param array $strategyData Strategy from UpdateStrategyService
     * @param SyncSetting $settings Child account settings
     * @param array $originalFields Original updatedFields from webhook
     * @return EntityUpdateLog
     */
    public function update(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId,
        array $strategyData,
        SyncSetting $settings,
        array $originalFields
    ): EntityUpdateLog {
        // Create audit log
        $updateLog = EntityUpdateLog::create([
            'main_account_id' => $mainAccount->account_id,
            'child_account_id' => $childAccount->account_id,
            'entity_type' => $entityType,
            'main_entity_id' => $mainEntityId,
            'child_entity_id' => $childEntityId,
            'update_strategy' => $strategyData['strategy'],
            'updated_fields_received' => $originalFields,
            'fields_classified' => $strategyData['classified'] ?? [],
            'status' => 'processing',
        ]);

        try {
            $startTime = microtime(true);

            // Execute update by strategy
            $appliedFields = match ($strategyData['strategy']) {
                UpdateStrategyService::STRATEGY_SKIP => [],
                UpdateStrategyService::STRATEGY_FULL_SYNC => $this->fullUpdate($entityType, $mainAccount, $childAccount, $mainEntityId, $childEntityId),
                UpdateStrategyService::STRATEGY_PRICES_ONLY => $this->updatePricesOnly($entityType, $mainAccount, $childAccount, $mainEntityId, $childEntityId, $strategyData, $settings),
                UpdateStrategyService::STRATEGY_ATTRIBUTES_ONLY => $this->updateAttributesOnly($entityType, $mainAccount, $childAccount, $mainEntityId, $childEntityId, $strategyData),
                UpdateStrategyService::STRATEGY_BASE_FIELDS_ONLY => $this->updateBaseFields($entityType, $mainAccount, $childAccount, $mainEntityId, $childEntityId, $strategyData),
                UpdateStrategyService::STRATEGY_MIXED_SIMPLE => $this->updateMixedFields($entityType, $mainAccount, $childAccount, $mainEntityId, $childEntityId, $strategyData, $settings),
                default => throw new \Exception("Unknown strategy: {$strategyData['strategy']}"),
            };

            $processingTime = (int) ((microtime(true) - $startTime) * 1000);

            // Update log
            $updateLog->markAsCompleted($appliedFields, $processingTime);

            Log::info('Partial update completed', [
                'entity_type' => $entityType,
                'main_entity_id' => $mainEntityId,
                'child_entity_id' => $childEntityId,
                'strategy' => $strategyData['strategy'],
                'fields_applied' => count($appliedFields),
                'processing_time_ms' => $processingTime,
            ]);

        } catch (\Exception $e) {
            $updateLog->markAsFailed($e->getMessage());

            Log::error('Partial update failed', [
                'entity_type' => $entityType,
                'main_entity_id' => $mainEntityId,
                'child_entity_id' => $childEntityId,
                'strategy' => $strategyData['strategy'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $updateLog;
    }

    /**
     * Update prices only
     * CRITICAL: Only prices from price_mappings!
     */
    protected function updatePricesOnly(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId,
        array $strategyData,
        SyncSetting $settings
    ): array {
        // 1. GET entity from main account with expanded salePrices
        $mainEntity = $this->moySkladService
            ->setAccessToken($mainAccount->access_token)
            ->get("entity/{$entityType}/{$mainEntityId}?expand=salePrices");

        $priceData = [];
        $appliedFields = [];

        // 2. Standard prices (buyPrice, minPrice)
        foreach ($strategyData['standard_prices'] ?? [] as $priceField) {
            if (isset($mainEntity[$priceField])) {
                $priceData[$priceField] = $mainEntity[$priceField];
                $appliedFields[] = $priceField;
            }
        }

        // 3. Custom price types - ONLY mapped prices!
        if (!empty($strategyData['custom_prices'])) {
            $salePrices = $this->buildMappedSalePrices(
                $mainEntity['salePrices'] ?? [],
                $settings->price_mappings ?? [],
                $strategyData['custom_prices']
            );

            if (!empty($salePrices)) {
                $priceData['salePrices'] = $salePrices;
                $appliedFields = array_merge($appliedFields, $strategyData['custom_prices']);
            }
        }

        // 4. PUT to child account
        if (!empty($priceData)) {
            $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->put("entity/{$entityType}/{$childEntityId}", $priceData);
        }

        return $appliedFields;
    }

    /**
     * Build salePrices array using ONLY mapped price types
     */
    protected function buildMappedSalePrices(array $mainSalePrices, array $priceMappings, array $allowedPriceNames): array
    {
        $result = [];

        foreach ($mainSalePrices as $salePrice) {
            $priceTypeHref = $salePrice['priceType']['meta']['href'] ?? null;
            if (!$priceTypeHref) continue;

            // Extract price type UUID from href
            $mainPriceTypeId = $this->extractEntityId($priceTypeHref);

            // Check if mapped
            if (isset($priceMappings[$mainPriceTypeId])) {
                $childPriceTypeId = $priceMappings[$mainPriceTypeId];

                $result[] = [
                    'value' => $salePrice['value'],
                    'currency' => $salePrice['currency'],
                    'priceType' => [
                        'meta' => [
                            'href' => "https://api.moysklad.ru/api/remap/1.2/context/companysettings/pricetype/{$childPriceTypeId}",
                            'type' => 'pricetype',
                            'mediaType' => 'application/json',
                        ],
                    ],
                ];
            }
        }

        return $result;
    }

    /**
     * Update custom attributes only
     */
    protected function updateAttributesOnly(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId,
        array $strategyData
    ): array {
        // TODO: Implement attribute-only update
        // 1. GET main entity with expand=attributes
        // 2. Find attribute mappings by name
        // 3. Build attributes array for child
        // 4. PUT only attributes field

        return [];
    }

    /**
     * Update base fields only (name, description, article, code)
     */
    protected function updateBaseFields(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId,
        array $strategyData
    ): array {
        // TODO: Implement base fields update
        // 1. GET main entity
        // 2. Extract base fields
        // 3. PUT to child

        return [];
    }

    /**
     * Update mixed simple fields
     */
    protected function updateMixedFields(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId,
        array $strategyData,
        SyncSetting $settings
    ): array {
        // TODO: Implement mixed update strategy
        // Combine prices + attributes + base fields

        return [];
    }

    /**
     * Fallback to full sync
     */
    protected function fullUpdate(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId
    ): array {
        // TODO: Call existing ProductSyncService or appropriate sync service
        // This is fallback for complex dependencies

        return ['full_sync'];
    }

    /**
     * Extract entity ID from МойСклад href
     */
    protected function extractEntityId(string $href): string
    {
        $parts = explode('/', $href);
        return end($parts);
    }
}
```

---

## Phase 5: Integration

### 5.1 Update WebhookReceiverService

**File:** `app/Services/Webhook/WebhookReceiverService.php`

```php
public function receive(array $payload, string $requestId): WebhookLog
{
    // ... existing validation

    $firstEvent = $payload['events'][0];

    // Extract updated_fields (NEW)
    $updatedFields = $firstEvent['updatedFields'] ?? null;

    // Create webhook log
    $webhookLog = WebhookLog::create([
        // ... existing fields
        'updated_fields' => $updatedFields, // NEW
    ]);

    return $webhookLog;
}
```

### 5.2 Update WebhookProcessorService

**File:** `app/Services/Webhook/WebhookProcessorService.php`

```php
protected function processMainAccountEvent(array $event, Account $account, string $entityType, string $action): void
{
    $entityHref = $event['meta']['href'] ?? null;
    $entityId = $this->extractEntityId($entityHref);

    // Extract updated_fields for UPDATE action (NEW)
    $updatedFields = ($action === 'UPDATE') ? ($event['updatedFields'] ?? null) : null;

    match($entityType) {
        'product' => $this->syncProduct($account->account_id, $entityId, $action, $updatedFields),
        'service' => $this->syncService($account->account_id, $entityId, $action, $updatedFields),
        'variant' => $this->syncVariant($account->account_id, $entityId, $action, $updatedFields),
        'bundle' => $this->syncBundle($account->account_id, $entityId, $action, $updatedFields),
        // ...
    };
}

protected function syncProduct(
    string $mainAccountId,
    string $productId,
    string $action,
    ?array $updatedFields = null // NEW
): void {
    if ($action === 'DELETE') {
        $this->batchSyncService->batchArchiveProduct($mainAccountId, $productId);
    } else {
        $this->batchSyncService->batchSyncProduct(
            $mainAccountId,
            $productId,
            $updatedFields // NEW - pass to batch sync
        );
    }
}
```

### 5.3 Update BatchSyncService

**File:** `app/Services/BatchSyncService.php`

```php
public function batchSyncProduct(
    string $mainAccountId,
    string $productId,
    ?array $updatedFields = null // NEW
): void {
    // ... existing code to find child accounts

    foreach ($childAccounts as $childAccount) {
        // Create sync task
        SyncQueue::create([
            // ... existing fields
            'payload' => [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'updated_fields' => $updatedFields, // NEW
            ],
        ]);
    }
}
```

### 5.4 Trait: HandlesPartialUpdates

**File:** `app/Services/Sync/Traits/HandlesPartialUpdates.php`

```php
<?php

namespace App\Services\Sync\Traits;

use App\Models\SyncQueue;
use App\Models\SyncSetting;
use App\Models\Account;
use App\Services\Webhook\FieldClassifierService;
use App\Services\Webhook\UpdateStrategyService;
use App\Services\Webhook\PartialUpdateService;

/**
 * HandlesPartialUpdates Trait
 *
 * Reusable logic for all sync handlers to support partial updates
 * Use in: ProductSyncHandler, ServiceSyncHandler, VariantSyncHandler, etc.
 */
trait HandlesPartialUpdates
{
    /**
     * Handle update (partial or full)
     */
    protected function handleUpdate(SyncQueue $task, SyncSetting $settings): void
    {
        $entityType = $task->entity_type; // product, service, variant, etc.

        if (isset($task->payload['updated_fields']) && !empty($task->payload['updated_fields'])) {
            // Partial update
            $this->handlePartialUpdate($task, $settings, $entityType);
        } else {
            // Full sync (fallback)
            $this->handleFullSync($task, $settings, $entityType);
        }
    }

    /**
     * Handle partial update using classification and strategy
     */
    protected function handlePartialUpdate(
        SyncQueue $task,
        SyncSetting $settings,
        string $entityType
    ): void {
        $mainAccountId = $task->payload['main_account_id'];
        $mainEntityId = $task->payload['entity_id'];
        $updatedFields = $task->payload['updated_fields'];

        // 1. Get accounts
        $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
        $childAccount = Account::where('account_id', $task->account_id)->firstOrFail();

        // 2. Find child entity ID from mapping
        $childEntityId = $this->findChildEntityId($mainAccountId, $task->account_id, $entityType, $mainEntityId);

        if (!$childEntityId) {
            // No mapping found → fallback to full sync (will create entity)
            $this->handleFullSync($task, $settings, $entityType);
            return;
        }

        // 3. Classify fields
        $classified = app(FieldClassifierService::class)->classify(
            $entityType,
            $updatedFields,
            $mainAccountId
        );

        // 4. Determine strategy
        $strategyData = app(UpdateStrategyService::class)->determine(
            $classified,
            $settings,
            $mainAccountId
        );

        $strategyData['classified'] = $classified;

        // 5. Execute partial update
        app(PartialUpdateService::class)->update(
            $entityType,
            $mainAccount,
            $childAccount,
            $mainEntityId,
            $childEntityId,
            $strategyData,
            $settings,
            $updatedFields
        );
    }

    /**
     * Find child entity ID from entity_mappings
     */
    protected function findChildEntityId(
        string $mainAccountId,
        string $childAccountId,
        string $entityType,
        string $mainEntityId
    ): ?string {
        $mapping = \App\Models\EntityMapping::where([
            'parent_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'entity_type' => $entityType,
            'parent_entity_id' => $mainEntityId,
        ])->first();

        return $mapping?->child_entity_id;
    }

    /**
     * Fallback to full sync (must be implemented by handler)
     */
    abstract protected function handleFullSync(SyncQueue $task, SyncSetting $settings, string $entityType): void;
}
```

### 5.5 Update ProductSyncHandler

**File:** `app/Services/Sync/Handlers/ProductSyncHandler.php`

```php
<?php

namespace App\Services\Sync\Handlers;

use App\Services\Sync\Traits\HandlesPartialUpdates;
use App\Models\SyncQueue;
use App\Models\SyncSetting;

class ProductSyncHandler extends BaseSyncHandler
{
    use HandlesPartialUpdates;

    public function handle(SyncQueue $task): void
    {
        $settings = SyncSetting::where('account_id', $task->account_id)->first();

        if (!$settings || !$settings->sync_products) {
            $task->markAsCompleted('Sync disabled for products');
            return;
        }

        // Use trait method for smart update handling
        $this->handleUpdate($task, $settings);
    }

    /**
     * Fallback to full sync (required by HandlesPartialUpdates trait)
     */
    protected function handleFullSync(SyncQueue $task, SyncSetting $settings, string $entityType): void
    {
        // Call existing ProductSyncService for full sync
        app(\App\Services\ProductSyncService::class)->syncProduct(
            $task->payload['main_account_id'],
            $task->account_id,
            $task->payload['product_id']
        );
    }
}
```

---

## Phase 6: Testing

### 6.1 Unit Tests

**File:** `tests/Unit/Services/FieldClassifierServiceTest.php`

Test field classification logic:
- Standard fields recognized correctly
- Custom attributes identified by name
- Custom price types identified by name
- Flags (has_complex_deps, has_prices) set correctly

### 6.2 Integration Tests

**File:** `tests/Feature/PartialUpdateTest.php`

Test end-to-end partial update flow:
1. Mock webhook payload with updatedFields
2. Process through WebhookReceiver → WebhookProcessor → SyncHandler
3. Verify correct strategy chosen
4. Verify EntityUpdateLog created with correct data
5. Verify only specified fields updated in child account

### 6.3 Real-World Test Cases

**Test Case 1: Price-only update**
```json
{
  "events": [{
    "meta": {"type": "product", "href": "..."},
    "updatedFields": ["buyPrice", "salePrices", "Тест цены"],
    "action": "UPDATE"
  }]
}
```
Expected: PRICES_ONLY strategy, only prices updated

**Test Case 2: Mixed update with complex dependency**
```json
{
  "events": [{
    "updatedFields": ["name", "productFolder", "Франшиза москва"],
    "action": "UPDATE"
  }]
}
```
Expected: FULL_SYNC strategy (productFolder is complex dependency)

**Test Case 3: Attribute-only update**
```json
{
  "events": [{
    "updatedFields": ["Франшиза москва", "Франшиза 2"],
    "action": "UPDATE"
  }]
}
```
Expected: ATTRIBUTES_ONLY strategy (if attributes in sync_list)

---

## Migration from Full Sync

### Backward Compatibility

**Key principle:** Partial update is opt-in via presence of `updated_fields`

```php
// Old behavior (still works):
SyncQueue::create([
    'payload' => [
        'main_account_id' => '...',
        'product_id' => '...',
        // No updated_fields → full sync
    ]
]);

// New behavior:
SyncQueue::create([
    'payload' => [
        'main_account_id' => '...',
        'product_id' => '...',
        'updated_fields' => ['buyPrice', 'salePrices'], // → partial update
    ]
]);
```

### Rollout Plan

**Phase 1:** Infrastructure (migrations, models)
**Phase 2:** Core services (FieldClassifier, UpdateStrategy, PartialUpdate)
**Phase 3:** Integration (WebhookReceiver, WebhookProcessor, handlers)
**Phase 4:** Testing on staging with real webhooks
**Phase 5:** Production rollout with monitoring
**Phase 6:** Extend to other entity types (services, variants, bundles)

---

## Monitoring and Analytics

### Key Metrics (from entity_update_logs)

```sql
-- Update strategy distribution
SELECT update_strategy, COUNT(*) as count
FROM entity_update_logs
WHERE created_at >= NOW() - INTERVAL '7 days'
GROUP BY update_strategy;

-- Average processing time by strategy
SELECT update_strategy, AVG(processing_time_ms) as avg_time_ms
FROM entity_update_logs
WHERE status = 'completed'
GROUP BY update_strategy;

-- Most frequently updated fields
SELECT
  json_array_elements_text(updated_fields_received) as field,
  COUNT(*) as updates
FROM entity_update_logs
WHERE entity_type = 'product'
GROUP BY field
ORDER BY updates DESC
LIMIT 20;

-- Success rate by strategy
SELECT
  update_strategy,
  COUNT(*) FILTER (WHERE status = 'completed') as success,
  COUNT(*) FILTER (WHERE status = 'failed') as failed,
  ROUND(100.0 * COUNT(*) FILTER (WHERE status = 'completed') / COUNT(*), 2) as success_rate
FROM entity_update_logs
GROUP BY update_strategy;
```

---

## Future Enhancements

1. **Batch partial updates** - Multiple products in single PUT request
2. **Diff detection** - Compare old vs new values, skip if no actual change
3. **Conflict resolution** - Handle concurrent updates from main and child
4. **Smart caching** - Cache entity data to avoid GET requests
5. **Webhooks for other entities** - Extend to orders, invoices, etc.

---

## References

- [МойСклад Webhook API](https://dev.moysklad.ru/doc/api/remap/1.2/#uведомления-вебхуки)
- [МойСклад Product API](https://dev.moysklad.ru/doc/api/remap/1.2/#suschnosti-towar)
- [Webhook System Documentation](18-webhook-system.md)
- [Batch Synchronization](04-batch-sync.md)
