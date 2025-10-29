# 17. Variant Synchronization через /entity/assortment

## 📋 Содержание

1. [Обзор](#обзор)
2. [Текущая проблема](#текущая-проблема)
3. [Новая архитектура](#новая-архитектура)
4. [План изменений](#план-изменений)
5. [Cleanup Stale Mappings](#cleanup-stale-mappings)
6. [Порядок выполнения](#порядок-выполнения)
7. [Тестирование](#тестирование)

---

## Обзор

**Цель:** Унифицировать загрузку variants через `/entity/assortment` вместе с products/services/bundles, применяя фильтры на стороне МойСклад API, и использовать batch POST для синхронизации.

**МойСклад API возможность:** `/entity/assortment` с фильтром `type=product;type=service;type=variant` возвращает ВСЕ типы в одном ответе с применением фильтров (атрибуты, папки).

**Преимущества:**
- ✅ Меньше API запросов (1 вместо 2-3)
- ✅ Фильтры применяются автоматически МойСклад API
- ✅ Variants получаем уже отфильтрованные (чьи parent products прошли фильтр)
- ✅ Batch POST для variants (100 per request)
- ✅ Правильный порядок синхронизации через приоритеты

---

## Текущая проблема

### 1. Ошибка в коде

```
Call to undefined method App\Services\ProductSyncService::syncProductVariants()
```

**Причина:** При рефакторинге ProcessSyncQueueJob (коммит f3bada3) логика метода `processBatchVariantSync()` не была перенесена в сервис. BatchVariantSyncHandler вызывает несуществующий метод.

### 2. Архитектурные проблемы

- ❌ Variants загружаются отдельно через `/entity/variant` БЕЗ фильтров (все variants)
- ❌ Группируются по parent product → создаются задачи `product_variants` с productId
- ❌ Индивидуальный POST для каждого variant (медленно)
- ❌ Проблемы с race conditions при создании маппингов

---

## Новая архитектура

### API Flow

```
┌─────────────────────────────────────────────────────────────┐
│ 1. ONE API REQUEST (с фильтрами)                           │
│                                                             │
│ GET /entity/assortment?                                     │
│   filter=type=product;type=service;type=variant;            │
│          productFolder=<folder_id>;                         │
│          <custom_filters>                                   │
│   expand=attributes,productFolder,product,                  │
│          characteristics,packs.uom,salePrices,images        │
│   limit=100                                                 │
│                                                             │
│ RESPONSE: {                                                 │
│   rows: [                                                   │
│     { meta: { type: "product" }, ... },    // Товары       │
│     { meta: { type: "service" }, ... },    // Услуги       │
│     { meta: { type: "bundle" }, ... },     // Комплекты    │
│     { meta: { type: "variant" }, ... },    // Модификации  │
│   ]                                                         │
│ }                                                           │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ 2. CLIENT-SIDE FILTERING                                   │
│                                                             │
│ • Отфильтровать по meta.type                               │
│ • Применить match_field проверки (для services)            │
│ • Группировать по entity_type                               │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ 3. CREATE BATCH TASKS (с приоритетами)                     │
│                                                             │
│ SyncQueue:                                                  │
│   • batch_products  (priority=10) ← Первыми                │
│   • batch_services  (priority=8)                            │
│   • batch_bundles   (priority=6)                            │
│   • batch_variants  (priority=4)  ← Последними             │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ 4. BATCH POST EXECUTION                                     │
│                                                             │
│ BatchVariantSyncService:                                    │
│   1. Cleanup stale characteristic mappings                  │
│   2. Pre-sync ALL characteristics (БЕЗ sleep)               │
│   3. Verify parent product mappings                         │
│   4. Prepare variants (100 per batch)                       │
│   5. POST /entity/variant [...]  ← Batch POST               │
│   6. Create entity_mappings (firstOrCreate)                 │
│   7. Queue image sync tasks                                 │
└─────────────────────────────────────────────────────────────┘
```

### Приоритеты синхронизации

| Entity Type     | Priority | Порядок | Причина                          |
|-----------------|----------|---------|----------------------------------|
| batch_products  | 10       | 1-й     | Variants зависят от products     |
| batch_services  | 8        | 2-й     | Независимые                      |
| batch_bundles   | 6        | 3-й     | Зависят от products/variants     |
| batch_variants  | 4        | 4-й     | Зависят от products              |

---

## План изменений

### 1. EntityConfig.php - Обновить конфигурацию variant

**Файл:** `app/Services/EntityConfig.php`

**Изменения:**

#### A. Добавить `batch_priority` для всех типов (строка 20-70):

```php
'product' => [
    // ... существующие поля
    'batch_priority' => 10,  // NEW: Highest - синхронизируются первыми
],

'service' => [
    // ... существующие поля
    'batch_priority' => 8,   // NEW
],

'bundle' => [
    // ... существующие поля
    'batch_priority' => 6,   // NEW
],

'variant' => [
    // ... см. ниже
    'batch_priority' => 4,   // NEW: Lowest - синхронизируются последними
],
```

#### B. Обновить config для variant (строка 59-70):

```php
'variant' => [
    'endpoint' => '/entity/variant',
    'expand' => 'attributes,product,characteristics,packs.uom,salePrices,images',
    // CHANGED: добавлены packs.uom, salePrices

    'batch_entity_type' => 'batch_variants',
    'filter_metadata_type' => 'product',
    'supports_filters' => true,  // CHANGED: было false
    'use_assortment_for_filters' => true,  // CHANGED: было false
    'assortment_type' => 'variant',  // NEW
    'match_field_setting' => 'product_match_field',
    'default_match_field' => 'code',
    'has_match_field_check' => false,
    'batch_priority' => 4,  // NEW
],
```

---

### 2. BatchEntityLoader.php - Поддержка приоритетов и variants

**Файл:** `app/Services/BatchEntityLoader.php`

**Изменения:**

#### A. Обновить `createBatchTasks()` - динамический приоритет (строка 844):

```php
// БЫЛО:
'priority' => 10,  // High priority (manual sync)

// СТАЛО:
'priority' => $config['batch_priority'] ?? 10,
```

#### B. Добавить `'batch_variants'` в payload key mapping (строка 831-836):

```php
$payloadKey = match($batchEntityType) {
    'batch_products' => 'products',
    'batch_services' => 'services',
    'batch_bundles' => 'bundles',
    'batch_variants' => 'variants',  // NEW
    default => 'entities'
};
```

---

### 3. SyncActionsController.php - Добавить variants в assortment

**Файл:** `app/Http/Controllers/Api/SyncActionsController.php`

**Изменения:**

#### A. Добавить `'variant'` в `enabledTypes` (строка 67-89):

```php
// Определить какие типы сущностей нужно синхронизировать
$enabledTypes = [];
if ($syncSettings->sync_products) {
    $enabledTypes[] = 'product';
}
if ($syncSettings->sync_services ?? false) {
    $enabledTypes[] = 'service';
}
if ($syncSettings->sync_bundles) {
    $enabledTypes[] = 'bundle';
}
// NEW: Добавить variants
if ($syncSettings->sync_variants) {
    $enabledTypes[] = 'variant';
}

// Загрузить все включенные типы одним запросом через assortment
if (!empty($enabledTypes)) {
    $tasksCreated += $batchLoader->loadAndCreateAssortmentBatchTasks(
        $enabledTypes,
        $mainAccountId,
        $accountId,
        $mainAccount->access_token,
        $syncSettings
    );
}
```

#### B. Удалить старую логику variants (строки 91-100, 139-220):

```php
// DELETE ENTIRE BLOCK:
// if ($syncSettings->sync_variants) {
//     $tasksCreated += $this->createBatchVariantTasks(...);
// }

// DELETE ENTIRE METHOD:
// protected function createBatchVariantTasks(...) { ... }
```

---

### 4. CharacteristicSyncService.php - Добавить cleanup stale mappings

**Файл:** `app/Services/CharacteristicSyncService.php`

**Добавить метод:**

```php
/**
 * Проверить и очистить stale маппинги характеристик
 *
 * Загружает все characteristics из child аккаунта и проверяет,
 * существуют ли child_characteristic_id из маппингов.
 * Удаляет stale маппинги (где характеристика удалена в child).
 *
 * @param string $mainAccountId UUID главного аккаунта
 * @param string $childAccountId UUID дочернего аккаунта
 * @return array ['checked' => int, 'deleted' => int]
 */
public function cleanupStaleMappings(
    string $mainAccountId,
    string $childAccountId
): array {
    Log::info('Starting characteristic stale mappings cleanup', [
        'main_account_id' => $mainAccountId,
        'child_account_id' => $childAccountId
    ]);

    // 1. Загрузить ВСЕ characteristics из child аккаунта
    $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

    $childCharacteristics = $this->moySkladService
        ->setAccessToken($childAccount->access_token)
        ->get('/entity/product/metadata/characteristics');

    $childCharacteristicIds = collect($childCharacteristics['data']['rows'] ?? [])
        ->pluck('id')
        ->toArray();

    Log::debug('Loaded child characteristics', [
        'count' => count($childCharacteristicIds)
    ]);

    // 2. Получить все маппинги для этой пары аккаунтов
    $mappings = CharacteristicMapping::where('parent_account_id', $mainAccountId)
        ->where('child_account_id', $childAccountId)
        ->get();

    $checkedCount = $mappings->count();
    $deletedCount = 0;

    // 3. Проверить каждый маппинг
    foreach ($mappings as $mapping) {
        $childCharId = $mapping->child_characteristic_id;

        // Если child_characteristic_id НЕ существует в child аккаунте
        if (!in_array($childCharId, $childCharacteristicIds)) {
            Log::warning('Stale characteristic mapping detected', [
                'mapping_id' => $mapping->id,
                'parent_characteristic_id' => $mapping->parent_characteristic_id,
                'child_characteristic_id' => $childCharId,
                'characteristic_name' => $mapping->characteristic_name
            ]);

            // Удалить stale маппинг
            $mapping->delete();
            $deletedCount++;
        }
    }

    Log::info('Characteristic stale mappings cleanup completed', [
        'main_account_id' => $mainAccountId,
        'child_account_id' => $childAccountId,
        'checked_count' => $checkedCount,
        'deleted_count' => $deletedCount
    ]);

    return [
        'checked' => $checkedCount,
        'deleted' => $deletedCount
    ];
}
```

---

### 5. BatchVariantSyncService.php - Создать новый сервис

**Файл:** `app/Services/BatchVariantSyncService.php` (СОЗДАТЬ)

**Полное содержимое:**

```php
<?php

namespace App\Services;

use App\Models\Account;
use App\Models\EntityMapping;
use App\Models\SyncSetting;
use App\Models\SyncQueue;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для batch синхронизации модификаций (variants)
 */
class BatchVariantSyncService
{
    public function __construct(
        protected MoySkladService $moySkladService,
        protected VariantSyncService $variantSyncService,
        protected CharacteristicSyncService $characteristicSyncService
    ) {}

    /**
     * Batch синхронизация модификаций
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param array $variants Массив variants из МойСклад (уже с expand)
     * @return array ['success' => int, 'failed' => int]
     */
    public function batchSyncVariants(
        string $mainAccountId,
        string $childAccountId,
        array $variants
    ): array {
        if (empty($variants)) {
            return ['success' => 0, 'failed' => 0];
        }

        // Получить accounts и settings
        $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
        $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
        $syncSettings = SyncSetting::where('account_id', $childAccountId)->firstOrFail();

        Log::info('Batch variant sync started', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'variants_count' => count($variants)
        ]);

        // PHASE 0: Cleanup stale characteristic mappings
        try {
            $cleanupResult = $this->characteristicSyncService->cleanupStaleMappings(
                $mainAccountId,
                $childAccountId
            );

            Log::info('Stale characteristic mappings cleaned up', [
                'checked' => $cleanupResult['checked'],
                'deleted' => $cleanupResult['deleted']
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cleanup stale characteristic mappings', [
                'error' => $e->getMessage()
            ]);
            // Не прерываем выполнение - продолжаем синхронизацию
        }

        // PHASE 1: Пре-синхронизация характеристик (один раз для всех variants)
        $this->preSyncCharacteristics($mainAccountId, $childAccountId, $variants);

        // PHASE 2: Проверить parent product mappings
        $validVariants = [];
        $skippedVariants = [];

        foreach ($variants as $variant) {
            $productId = $this->extractProductId($variant);

            if (!$productId) {
                Log::warning('Variant missing product reference', [
                    'variant_id' => $variant['id'] ?? 'unknown'
                ]);
                $skippedVariants[] = $variant;
                continue;
            }

            // Проверить существует ли маппинг parent product
            $productMapping = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_entity_id', $productId)
                ->where('entity_type', 'product')
                ->first();

            if (!$productMapping) {
                // Parent product еще не синхронизирован - создать retry задачу
                Log::info('Parent product not synced yet, creating retry task', [
                    'variant_id' => $variant['id'],
                    'product_id' => $productId
                ]);

                $this->createRetryTask($mainAccountId, $childAccountId, $variant);
                $skippedVariants[] = $variant;
                continue;
            }

            $validVariants[] = $variant;
        }

        if (empty($validVariants)) {
            Log::info('No valid variants to sync (all skipped)', [
                'total_variants' => count($variants),
                'skipped_count' => count($skippedVariants)
            ]);
            return ['success' => 0, 'failed' => count($skippedVariants)];
        }

        // PHASE 3: Подготовить данные для batch POST
        $preparedVariants = [];
        foreach ($validVariants as $variant) {
            try {
                $variantData = $this->variantSyncService->prepareVariantForBatch(
                    $variant,
                    $mainAccountId,
                    $childAccountId,
                    $syncSettings
                );

                if ($variantData) {
                    $preparedVariants[] = [
                        'original' => $variant,
                        'prepared' => $variantData
                    ];
                }
            } catch (\Exception $e) {
                Log::error('Failed to prepare variant for batch', [
                    'variant_id' => $variant['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        // PHASE 4: Batch POST (100 per request)
        $successCount = 0;
        $failedCount = 0;
        $batches = array_chunk($preparedVariants, 100);

        foreach ($batches as $batchIndex => $batch) {
            try {
                $batchData = array_column($batch, 'prepared');

                $result = $this->moySkladService
                    ->setAccessToken($childAccount->access_token)
                    ->post('/entity/variant', $batchData);

                $createdVariants = $result['data'] ?? [];

                // Создать маппинги для успешных
                foreach ($createdVariants as $index => $createdVariant) {
                    if (isset($createdVariant['id'])) {
                        $originalVariant = $batch[$index]['original'];

                        EntityMapping::firstOrCreate(
                            [
                                'parent_account_id' => $mainAccountId,
                                'child_account_id' => $childAccountId,
                                'entity_type' => 'variant',
                                'parent_entity_id' => $originalVariant['id'],
                                'sync_direction' => 'main_to_child',
                            ],
                            [
                                'child_entity_id' => $createdVariant['id'],
                            ]
                        );

                        $successCount++;

                        // Синхронизировать изображения
                        if ($syncSettings->sync_images || $syncSettings->sync_images_all) {
                            $this->queueImageSync(
                                $mainAccountId,
                                $childAccountId,
                                $originalVariant,
                                $createdVariant,
                                $syncSettings
                            );
                        }
                    }
                }

            } catch (\Exception $e) {
                Log::error('Batch variant POST failed', [
                    'batch_index' => $batchIndex,
                    'batch_size' => count($batch),
                    'error' => $e->getMessage()
                ]);

                // Создать индивидуальные retry задачи
                foreach ($batch as $item) {
                    $this->createRetryTask($mainAccountId, $childAccountId, $item['original']);
                    $failedCount++;
                }
            }
        }

        Log::info('Batch variant sync completed', [
            'total_variants' => count($variants),
            'valid_variants' => count($validVariants),
            'skipped_variants' => count($skippedVariants),
            'success_count' => $successCount,
            'failed_count' => $failedCount
        ]);

        return [
            'success' => $successCount,
            'failed' => $failedCount + count($skippedVariants)
        ];
    }

    /**
     * Пре-синхронизация всех уникальных характеристик
     */
    protected function preSyncCharacteristics(
        string $mainAccountId,
        string $childAccountId,
        array $variants
    ): void {
        $allCharacteristics = collect($variants)
            ->pluck('characteristics')
            ->flatten(1)
            ->unique('name')
            ->filter(fn($char) => !empty($char['name']))
            ->values()
            ->toArray();

        if (empty($allCharacteristics)) {
            return;
        }

        try {
            $stats = $this->characteristicSyncService->syncCharacteristics(
                $mainAccountId,
                $childAccountId,
                $allCharacteristics
            );

            Log::info('Characteristics pre-synced for batch variants', [
                'characteristics_count' => count($allCharacteristics),
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to pre-sync characteristics', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'error' => $e->getMessage()
            ]);
            // Не прерываем выполнение - характеристики будут синхронизированы через fallback
        }
    }

    /**
     * Извлечь product ID из variant
     */
    protected function extractProductId(array $variant): ?string
    {
        $href = $variant['product']['meta']['href'] ?? null;
        if (!$href) {
            return null;
        }

        if (preg_match('/\/([a-f0-9-]{36})$/', $href, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Создать индивидуальную retry задачу для variant
     */
    protected function createRetryTask(
        string $mainAccountId,
        string $childAccountId,
        array $variant
    ): void {
        SyncQueue::create([
            'account_id' => $childAccountId,
            'entity_type' => 'variant',
            'entity_id' => $variant['id'],
            'operation' => 'update',
            'priority' => 5,
            'scheduled_at' => now()->addMinutes(5), // Retry через 5 минут
            'status' => 'pending',
            'attempts' => 0,
            'payload' => [
                'main_account_id' => $mainAccountId,
                'batch_retry' => true
            ]
        ]);
    }

    /**
     * Поставить в очередь синхронизацию изображений
     */
    protected function queueImageSync(
        string $mainAccountId,
        string $childAccountId,
        array $originalVariant,
        array $createdVariant,
        SyncSetting $settings
    ): void {
        $images = $originalVariant['images']['rows'] ?? [];
        if (empty($images)) {
            return;
        }

        // Получить лимит изображений
        $imageSyncService = app(ImageSyncService::class);
        $limit = $imageSyncService->getImageLimit($settings);

        if ($limit === 0) {
            return;
        }

        $imagesToSync = array_slice($images, 0, $limit);

        SyncQueue::create([
            'account_id' => $childAccountId,
            'entity_type' => 'image_sync',
            'entity_id' => $originalVariant['id'],
            'operation' => 'sync',
            'priority' => 50,
            'status' => 'pending',
            'scheduled_at' => now(),
            'payload' => [
                'main_account_id' => $mainAccountId,
                'parent_entity_type' => 'variant',
                'parent_entity_id' => $originalVariant['id'],
                'child_entity_id' => $createdVariant['id'],
                'images' => $imagesToSync
            ]
        ]);
    }
}
```

---

### 6. VariantSyncService.php - Добавить prepareVariantForBatch()

**Файл:** `app/Services/VariantSyncService.php`

**Добавить метод:**

```php
/**
 * Подготовить данные variant для batch POST
 *
 * Аналог prepareProductForBatch() из ProductSyncService.
 * Подготавливает данные БЕЗ выполнения POST запроса.
 *
 * @param array $variant Данные variant из МойСклад (с expand)
 * @param string $mainAccountId UUID главного аккаунта
 * @param string $childAccountId UUID дочернего аккаунта
 * @param SyncSetting $syncSettings Настройки синхронизации
 * @return array|null Подготовленные данные или null если невозможно
 */
public function prepareVariantForBatch(
    array $variant,
    string $mainAccountId,
    string $childAccountId,
    SyncSetting $syncSettings
): ?array {
    // Проверить маппинг parent product (должен существовать)
    $productId = $this->extractProductId($variant['product']['meta']['href'] ?? '');
    if (!$productId) {
        return null;
    }

    $productMapping = EntityMapping::where('parent_account_id', $mainAccountId)
        ->where('child_account_id', $childAccountId)
        ->where('parent_entity_id', $productId)
        ->where('entity_type', 'product')
        ->first();

    if (!$productMapping) {
        return null; // Parent product не синхронизирован
    }

    // Подготовить данные (аналогично syncVariantData, но без POST)
    $variantData = [
        'name' => $variant['name'],
        'product' => [
            'meta' => [
                'href' => $this->buildChildProductHref($childAccountId, $productMapping->child_entity_id),
                'type' => 'product',
                'mediaType' => 'application/json'
            ]
        ],
    ];

    // Добавить характеристики
    if (isset($variant['characteristics']) && !empty($variant['characteristics'])) {
        $variantData['characteristics'] = $this->prepareCharacteristics(
            $variant['characteristics'],
            $mainAccountId,
            $childAccountId
        );
    }

    // Синхронизировать упаковки (если есть)
    // Для variant используем UOM родительского товара (product.uom)
    if (isset($variant['packs']) && !empty($variant['packs'])) {
        $baseUomId = $this->extractEntityId($variant['product']['uom']['meta']['href'] ?? '');
        $variantData['packs'] = $this->productSyncService->syncPacks(
            $mainAccountId,
            $childAccountId,
            $variant['packs'],
            $baseUomId
        );
    }

    // Добавить базовые поля
    // ВАЖНО: code НЕ синхронизируется (может вызвать конфликт уникальности в child)
    // Variants сопоставляются по parent product + characteristics, НЕ по code
    // См: commit 13c385e (2025-10-29)

    if (isset($variant['externalCode'])) {
        $variantData['externalCode'] = $variant['externalCode'];
    }
    if (isset($variant['description'])) {
        $variantData['description'] = $variant['description'];
    }

    // Добавить штрихкоды
    if (isset($variant['barcodes'])) {
        $variantData['barcodes'] = $variant['barcodes'];
    }

    // Проверить, имеет ли variant собственные цены (отличные от parent product)
    $mainProduct = $variant['product'] ?? null;
    $hasCustomPrices = false;

    if ($mainProduct && isset($mainProduct['salePrices'])) {
        $hasCustomPrices = $this->variantHasCustomPrices($variant, $mainProduct);
    } else {
        // Если parent product не expand-нут - безопасный fallback (синхронизируем цены)
        $hasCustomPrices = true;
        Log::warning('Parent product not expanded in variant batch, assuming custom prices', [
            'child_account_id' => $childAccountId,
            'main_variant_id' => $variant['id']
        ]);
    }

    Log::debug('Variant custom prices check (batch prepare)', [
        'child_account_id' => $childAccountId,
        'main_variant_id' => $variant['id'],
        'has_custom_prices' => $hasCustomPrices,
        'variant_prices_count' => count($variant['salePrices'] ?? []),
        'product_prices_count' => count($mainProduct['salePrices'] ?? [])
    ]);

    // Синхронизировать цены ТОЛЬКО если variant имеет собственные цены
    if ($hasCustomPrices) {
        // Цены (используя трейт SyncHelpers с маппингом ID типов цен)
        $prices = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $variant,
            $syncSettings
        );

        Log::debug('Variant prices synced (batch prepare)', [
            'child_account_id' => $childAccountId,
            'main_variant_id' => $variant['id'],
            'main_sale_prices_count' => count($variant['salePrices'] ?? []),
            'synced_sale_prices_count' => count($prices['salePrices']),
            'has_buy_price' => isset($prices['buyPrice']),
            'price_mappings_enabled' => !empty($syncSettings->price_mappings)
        ]);

        if (!empty($prices['salePrices'])) {
            $variantData['salePrices'] = $prices['salePrices'];
        }

        if (isset($prices['buyPrice'])) {
            $variantData['buyPrice'] = $prices['buyPrice'];
        }
    } else {
        // Variant наследует цены от product - НЕ отправляем salePrices/buyPrice
        Log::debug('Variant prepared without custom prices (inherits from product, batch)', [
            'child_account_id' => $childAccountId,
            'main_variant_id' => $variant['id'],
            'variant_prices_match_product' => true
        ]);
        // НЕ добавляем salePrices и buyPrice в $variantData
    }

    // Добавить дополнительные поля (НДС, физ.характеристики, маркировка и т.д.)
    // Используем метод из ProductSyncService через композицию
    $variantData = $this->productSyncService->addAdditionalFields($variantData, $variant, $syncSettings);

    return $variantData;
}

/**
 * Построить href для child product
 *
 * @param string $childAccountId UUID дочернего аккаунта
 * @param string $childProductId UUID child product
 * @return string
 */
protected function buildChildProductHref(string $childAccountId, string $childProductId): string
{
    return "https://api.moysklad.ru/api/remap/1.2/entity/product/{$childProductId}";
}

/**
 * Извлечь product ID из href
 *
 * @param string $href URL вида https://api.moysklad.ru/api/remap/1.2/entity/product/UUID
 * @return string|null UUID или null
 */
protected function extractProductId(string $href): ?string
{
    if (preg_match('/\/([a-f0-9-]{36})$/', $href, $matches)) {
        return $matches[1];
    }
    return null;
}
```

---

### 7. BatchVariantSyncHandler.php - Обновить handler

**Файл:** `app/Services/Sync/Handlers/BatchVariantSyncHandler.php`

**Заменить содержимое:**

```php
<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\BatchVariantSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для пакетной синхронизации модификаций
 *
 * Обрабатывает entity_type: 'batch_variants'
 */
class BatchVariantSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected BatchVariantSyncService $batchVariantSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'batch_variants';
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $mainAccountId = $payload['main_account_id'];
        $childAccountId = $task->account_id;

        // Новый формат: массив variants в payload
        $variants = $payload['variants'] ?? [];

        // Graceful degradation: старый формат (product_variants с productId)
        if (empty($variants) && $task->entity_id) {
            Log::warning('Old format batch variant task detected, skipping', [
                'task_id' => $task->id,
                'entity_id' => $task->entity_id,
                'entity_type' => $task->entity_type
            ]);
            return;
        }

        if (empty($variants)) {
            throw new \Exception('Invalid payload: missing variants array');
        }

        Log::info('Batch variant sync started', [
            'task_id' => $task->id,
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'variants_count' => count($variants)
        ]);

        // Выполнить batch синхронизацию
        $result = $this->batchVariantSyncService->batchSyncVariants(
            $mainAccountId,
            $childAccountId,
            $variants
        );

        $this->logSuccess($task, [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'variants_count' => count($variants),
            'success_count' => $result['success'] ?? 0,
            'failed_count' => $result['failed'] ?? 0
        ]);
    }
}
```

---

### 8. AppServiceProvider.php - Регистрация BatchVariantSyncService

**Файл:** `app/Providers/AppServiceProvider.php`

**Добавить в метод `register()`:**

```php
// Batch sync services
$this->app->singleton(BatchVariantSyncService::class);
```

---

## Cleanup Stale Mappings

### Проблема

**Stale mappings** возникают когда:
- Маппинг характеристики существует в `characteristic_mappings`
- Но характеристика удалена в child аккаунте (через UI или API)
- При попытке использовать `child_characteristic_id` → ошибка 404

### Решение

**Proactive cleanup** перед batch POST variants:
1. Загрузить ВСЕ characteristics из child аккаунта (`GET /entity/product/metadata/characteristics`)
2. Получить все маппинги для пары (mainAccountId, childAccountId)
3. Проверить существует ли `child_characteristic_id` в загруженных characteristics
4. Удалить stale маппинги
5. При пре-синхронизации характеристик они будут созданы заново

### Artisan команда для ручной очистки

**Создать:** `app/Console/Commands/CleanupStaleCharacteristicMappings.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\ChildAccount;
use App\Services\CharacteristicSyncService;
use Illuminate\Console\Command;

class CleanupStaleCharacteristicMappings extends Command
{
    protected $signature = 'sync:cleanup-stale-characteristic-mappings
                            {--dry-run : Preview changes without deleting}';

    protected $description = 'Remove stale characteristic mappings where child characteristic was deleted';

    public function handle(CharacteristicSyncService $characteristicSyncService)
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $this->info('Starting stale characteristic mappings cleanup...');

        // Получить все пары аккаунтов
        $childAccounts = ChildAccount::all();

        $totalChecked = 0;
        $totalDeleted = 0;

        foreach ($childAccounts as $link) {
            $this->info("Processing: {$link->parent_account_id} → {$link->child_account_id}");

            if ($isDryRun) {
                // TODO: Implement dry-run preview
                $this->warn('Dry-run not yet implemented for this command');
                continue;
            }

            try {
                $result = $characteristicSyncService->cleanupStaleMappings(
                    $link->parent_account_id,
                    $link->child_account_id
                );

                $totalChecked += $result['checked'];
                $totalDeleted += $result['deleted'];

                $this->line("  Checked: {$result['checked']}, Deleted: {$result['deleted']}");

            } catch (\Exception $e) {
                $this->error("  Error: {$e->getMessage()}");
            }
        }

        $this->info("\nCleanup completed!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Mappings Checked', $totalChecked],
                ['Stale Mappings Deleted', $totalDeleted],
            ]
        );

        return 0;
    }
}
```

---

## Порядок выполнения

### 1. Изменения кода (в этом порядке):

1. ✅ `EntityConfig.php` - обновить config для variant + добавить batch_priority
2. ✅ `BatchEntityLoader.php` - динамический приоритет + payload key для variants
3. ✅ `CharacteristicSyncService.php` - добавить cleanupStaleMappings()
4. ✅ `BatchVariantSyncService.php` - создать новый сервис (с cleanup в PHASE 0)
5. ✅ `VariantSyncService.php` - добавить prepareVariantForBatch()
6. ✅ `BatchVariantSyncHandler.php` - обновить handler
7. ✅ `SyncActionsController.php` - добавить variant в enabledTypes, удалить createBatchVariantTasks()
8. ✅ `AppServiceProvider.php` - зарегистрировать BatchVariantSyncService
9. ✅ `CleanupStaleCharacteristicMappings.php` - создать artisan команду (optional)

### 2. Cleanup (на сервере):

```bash
# OPTION A: Удалить старые задачи вручную (SQL)
psql -U postgres -d multiaccount -c "DELETE FROM sync_queue WHERE entity_type = 'product_variants' AND status = 'pending';"

# OPTION B: Создать migration (если нужна история)
php artisan make:migration cleanup_old_variant_tasks
```

### 3. Deploy:

```bash
./deploy.sh
```

### 4. После деплоя:

```bash
# Перезапустить queue worker (обязательно!)
./restart-queue.sh

# Опционально: Cleanup stale characteristic mappings
php artisan sync:cleanup-stale-characteristic-mappings --dry-run  # Preview
php artisan sync:cleanup-stale-characteristic-mappings            # Execute
```

---

## Тестирование

### 1. Проверить очередь

```bash
# Мониторинг логов
tail -f storage/logs/laravel.log | grep -E "Batch variant|characteristic"

# Статистика очереди
php artisan queue:monitor
```

### 2. Запустить синхронизацию

- Открыть админку
- Выбрать дочерний аккаунт
- Нажать "Синхронизировать всё"

### 3. Проверить результаты

**Созданные задачи:**
```sql
SELECT entity_type, priority, COUNT(*) as count
FROM sync_queue
WHERE status = 'pending'
GROUP BY entity_type, priority
ORDER BY priority DESC;
```

**Ожидаемый результат:**
```
entity_type      | priority | count
-----------------|----------|------
batch_products   | 10       | 15
batch_services   | 8        | 5
batch_bundles    | 6        | 3
batch_variants   | 4        | 8    ← NEW!
```

**Созданные маппинги:**
```sql
SELECT entity_type, sync_direction, COUNT(*) as count
FROM entity_mappings
WHERE child_account_id = '<child_account_id>'
GROUP BY entity_type, sync_direction;
```

### 4. Проверить stale mappings cleanup

```bash
# Проверить количество characteristic маппингов до cleanup
SELECT COUNT(*) FROM characteristic_mappings
WHERE parent_account_id = '<main_account_id>'
AND child_account_id = '<child_account_id>';

# Запустить cleanup
php artisan sync:cleanup-stale-characteristic-mappings

# Проверить после
SELECT COUNT(*) FROM characteristic_mappings
WHERE parent_account_id = '<main_account_id>'
AND child_account_id = '<child_account_id>';
```

### 5. Проверить batch POST

**Логи должны содержать:**
```
[INFO] Batch variant sync started (variants_count: 150)
[INFO] Stale characteristic mappings cleaned up (checked: 25, deleted: 3)
[INFO] Characteristics pre-synced for batch variants (characteristics_count: 12)
[INFO] Parent product not synced yet, creating retry task (variant_id: xxx)
[INFO] Batch variant sync completed (success_count: 140, failed_count: 10)
```

---

## Результаты

### До изменений:

- ❌ 2 отдельных API запроса (`/entity/assortment` + `/entity/variant`)
- ❌ Variants БЕЗ фильтров (все variants)
- ❌ Группировка по productId → задачи `product_variants`
- ❌ Вызов несуществующего метода `ProductSyncService::syncProductVariants()`
- ❌ Индивидуальный POST для каждого variant
- ❌ Риск stale characteristic mappings → ошибки 404

### После изменений:

- ✅ 1 унифицированный запрос `/entity/assortment?filter=type=product;type=service;type=variant;...`
- ✅ Variants уже отфильтрованные (чьи parent products прошли фильтр)
- ✅ Задачи `batch_variants` с массивом variants
- ✅ Batch POST для variants (100 per request)
- ✅ Правильный порядок через приоритеты (products → variants)
- ✅ Proactive cleanup stale characteristic mappings
- ✅ Пре-синхронизация характеристик БЕЗ sleep()
- ✅ Проверка parent product mapping с retry механизмом

---

## Важные особенности и ограничения

### ⚠️ Поле `code` НЕ синхронизируется (2025-10-29)

**Проблема:** Поле `code` (артикул) имеет ограничение уникальности в МойСклад. При синхронизации модификаций возникали конфликты, когда child аккаунт уже имел модификацию с таким же кодом.

**Пример:**
```
Main account:  Variant "Товар А (Размер M)" с code="ART-001"
Child account: Уже есть Variant "Товар Б (Цвет Красный)" с code="ART-001"
Sync attempt:  ❌ Ошибка: "Артикул не уникален"
```

**Решение (commit [13c385e](https://github.com/cavaleria-dev/multiaccount/commit/13c385e)):**

Поле `code` **исключено** из синхронизации модификаций. Изменения внесены в 3 метода `VariantSyncService`:

1. **createVariant()** (line 303)
2. **updateVariant()** (line 460)
3. **prepareVariantDataForBatchUpdate()** (lines 1386-1388)

**Что синхронизируется:**
- ✅ `externalCode` - внешний код (НЕ имеет ограничения уникальности)
- ✅ `name` - название модификации
- ✅ `characteristics` - характеристики (цвет, размер и т.д.)
- ✅ `product` - ссылка на родительский товар
- ✅ `salePrices`, `buyPrice` - цены (если отличаются от product)
- ✅ `barcodes` - штрихкоды
- ✅ `packs` - упаковки

**Что НЕ синхронизируется:**
- ❌ `code` - артикул (удален для избежания конфликтов уникальности)

**Почему это работает:**

Модификации сопоставляются между main и child аккаунтами по:
- **Parent product** (через entity_mappings для product)
- **Characteristics** (через characteristic_mappings)

Поле `code` НЕ используется для сопоставления модификаций, поэтому его отсутствие не влияет на корректность синхронизации.

**Код (после fix):**
```php
// createVariant(), updateVariant(), prepareVariantDataForBatchUpdate()
$variantData = [
    'name' => $variant['name'],
    // code удален - избегаем конфликтов уникальности артикула
    'externalCode' => $variant['externalCode'] ?? null,
    'product' => [...],
    'characteristics' => [...],
    // ... остальные поля
];
```

**Рекомендации:**
- Если необходимо синхронизировать артикулы, используйте поле `externalCode` (не имеет ограничения уникальности)
- Артикулы в child аккаунтах можно назначить вручную через МойСклад UI после синхронизации
- Для отслеживания используйте entity_mappings (parent_entity_id → child_entity_id)

---

## См. также

- [Batch Synchronization](04-batch-sync.md) - общая архитектура batch sync
- [Characteristic Synchronization](15-characteristic-sync.md) - синхронизация характеристик
- [Common Patterns & Gotchas](10-common-patterns.md) - частые проблемы
