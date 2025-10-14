# План: Расширенные настройки синхронизации франшизы

**Дата создания:** 2025-10-14
**Статус:** В работе
**Цель:** Добавить визуальный конструктор фильтров, маппинг цен, выбор доп.полей и кнопку "Синхронизировать всю номенклатуру"

---

## Общие требования

### Что синхронизируем:
- ✅ Товары (product) + **упаковки** + **штрихкоды**
- ✅ Модификации (variant)
- ✅ Комплекты (bundle)
- ❌ **Услуги (service)** - добавить
- ✅ Изображения (уже есть)
- ✅ Цены (добавить маппинг)
- ✅ Доп.поля (добавить выбор)

**Важно:** Упаковки (packs) и штрихкоды (barcodes) синхронизируются автоматически вместе с товарами, без дополнительных настроек.

### Поле сопоставления товаров:
Выбор из: `code`, `article`, `externalCode`, `barcode`
По этому полю определяется, существует ли товар во франшизе.

### Создание объектов во франшизе:

**Доп.поля (attributes):**
- Проверять по **названию И типу**
- Если существует → использовать, создать маппинг
- Если нет → создать новое, создать маппинг

**Характеристики (characteristics):**
- Проверять по **названию** (типа у характеристик нет)
- Если существует → использовать, создать маппинг
- Если нет → создать новую, создать маппинг

**Справочники (customentity):**
- ✅ Уже реализовано в `CustomEntitySyncService`
- Проверять по названию
- Элементы справочника тоже по названию

**Типы цен (price types):**
- Маппинг через UI: main_price_id → child_price_id
- Синхронизировать только выбранные цены

**Группы товаров (productFolder):**
- Флаг `create_product_folders` (boolean)
- Если true → создавать группы во франшизе
- Если false → не создавать, товары в корне

---

## Фаза 1: Backend - База данных и модели (30 мин) ⏳

**Файл:** `database/migrations/YYYY_MM_DD_HHMMSS_add_advanced_sync_settings.php`

### Новые колонки в `sync_settings`:

```php
Schema::table('sync_settings', function (Blueprint $table) {
    $table->boolean('sync_services')->default(false)->after('sync_bundles');
    $table->boolean('sync_images')->default(true)->after('sync_services');
    $table->boolean('create_product_folders')->default(true)->after('sync_product_folders');
    $table->json('price_mappings')->nullable()->after('price_types');
    $table->json('attribute_sync_list')->nullable()->after('catalog_filters');
});
```

### Обновить модель `app/Models/SyncSetting.php`:

```php
protected $fillable = [
    // ... existing
    'sync_services',
    'sync_images',
    'create_product_folders',
    'price_mappings',
    'attribute_sync_list',
];

protected $casts = [
    // ... existing
    'sync_services' => 'boolean',
    'sync_images' => 'boolean',
    'create_product_folders' => 'boolean',
    'price_mappings' => 'array',
    'attribute_sync_list' => 'array',
];
```

**Статус:** ❌ Не начато

---

## Фаза 2: Backend - API эндпоинты (1 час) ⏳

### 2.1. Расширить `app/Http/Controllers/Api/SyncSettingsController.php`

#### Метод `getPriceTypes(Request $request, $accountId)`
```php
/**
 * GET /api/sync-settings/{accountId}/price-types
 *
 * Получить типы цен из main и child аккаунтов
 */
public function getPriceTypes(Request $request, $accountId)
{
    $contextData = $request->get('moysklad_context');
    $mainAccountId = $contextData['accountId'];

    // Проверить что это дочерний аккаунт
    $link = DB::table('child_accounts')
        ->where('parent_account_id', $mainAccountId)
        ->where('child_account_id', $accountId)
        ->first();

    if (!$link) {
        return response()->json(['error' => 'Child account not found'], 404);
    }

    // Получить типы цен из обоих аккаунтов через МойСклад API
    $mainAccount = Account::where('account_id', $mainAccountId)->first();
    $childAccount = Account::where('account_id', $accountId)->first();

    $moysklad = app(MoySkladService::class);

    $mainPriceTypes = $moysklad->request($mainAccount->access_token, 'GET', '/entity/pricetype');
    $childPriceTypes = $moysklad->request($childAccount->access_token, 'GET', '/entity/pricetype');

    return response()->json([
        'main' => array_map(fn($pt) => [
            'id' => $pt['id'],
            'name' => $pt['name']
        ], $mainPriceTypes['rows'] ?? []),
        'child' => array_map(fn($pt) => [
            'id' => $pt['id'],
            'name' => $pt['name']
        ], $childPriceTypes['rows'] ?? [])
    ]);
}
```

#### Метод `getAttributes(Request $request, $accountId)`
```php
/**
 * GET /api/sync-settings/{accountId}/attributes
 *
 * Получить все доп.поля из main аккаунта
 */
public function getAttributes(Request $request, $accountId)
{
    $contextData = $request->get('moysklad_context');
    $mainAccountId = $contextData['accountId'];

    // Проверить связь
    $link = DB::table('child_accounts')
        ->where('parent_account_id', $mainAccountId)
        ->where('child_account_id', $accountId)
        ->first();

    if (!$link) {
        return response()->json(['error' => 'Child account not found'], 404);
    }

    // Получить доп.поля product из main аккаунта
    $mainAccount = Account::where('account_id', $mainAccountId)->first();
    $moysklad = app(MoySkladService::class);

    $attributes = $moysklad->request($mainAccount->access_token, 'GET', '/entity/product/metadata');

    $result = [];
    foreach ($attributes['attributes'] ?? [] as $attr) {
        $result[] = [
            'id' => $attr['id'],
            'name' => $attr['name'],
            'type' => $attr['type']
        ];
    }

    return response()->json(['data' => $result]);
}
```

#### Метод `getFolders(Request $request, $accountId)`
```php
/**
 * GET /api/sync-settings/{accountId}/folders
 *
 * Получить дерево групп товаров из main аккаунта
 */
public function getFolders(Request $request, $accountId)
{
    $contextData = $request->get('moysklad_context');
    $mainAccountId = $contextData['accountId'];

    // Проверить связь
    $link = DB::table('child_accounts')
        ->where('parent_account_id', $mainAccountId)
        ->where('child_account_id', $accountId)
        ->first();

    if (!$link) {
        return response()->json(['error' => 'Child account not found'], 404);
    }

    // Получить все папки
    $mainAccount = Account::where('account_id', $mainAccountId)->first();
    $moysklad = app(MoySkladService::class);

    $folders = $moysklad->request($mainAccount->access_token, 'GET', '/entity/productfolder', [
        'limit' => 1000
    ]);

    // Построить дерево
    $folderTree = $this->buildFolderTree($folders['rows'] ?? []);

    return response()->json(['data' => $folderTree]);
}

/**
 * Построить дерево папок из плоского списка
 */
private function buildFolderTree(array $folders): array
{
    $tree = [];
    $indexed = [];

    // Индексировать папки
    foreach ($folders as $folder) {
        $indexed[$folder['id']] = [
            'id' => $folder['id'],
            'name' => $folder['name'],
            'pathName' => $folder['pathName'] ?? $folder['name'],
            'parent_id' => $folder['productFolder']['meta']['href'] ?? null,
            'children' => []
        ];

        if ($indexed[$folder['id']]['parent_id']) {
            $parts = explode('/', $indexed[$folder['id']]['parent_id']);
            $indexed[$folder['id']]['parent_id'] = end($parts);
        }
    }

    // Построить дерево
    foreach ($indexed as $id => $folder) {
        if ($folder['parent_id'] && isset($indexed[$folder['parent_id']])) {
            $indexed[$folder['parent_id']]['children'][] = &$indexed[$id];
        } else {
            $tree[] = &$indexed[$id];
        }
    }

    return $tree;
}
```

#### Обновить `update(Request $request, $accountId)`
```php
$request->validate([
    // ... existing validations
    'sync_services' => 'sometimes|boolean',
    'sync_images' => 'sometimes|boolean',
    'create_product_folders' => 'sometimes|boolean',
    'price_mappings' => 'sometimes|array',
    'price_mappings.*.main_price_id' => 'required|uuid',
    'price_mappings.*.child_price_id' => 'required|uuid',
    'attribute_sync_list' => 'sometimes|nullable|array',
    'attribute_sync_list.*' => 'uuid',
]);

$settings = SyncSetting::updateOrCreate(
    ['account_id' => $accountId],
    $request->only([
        // ... existing fields
        'sync_services',
        'sync_images',
        'create_product_folders',
        'price_mappings',
        'attribute_sync_list',
    ])
);
```

**Статус:** ✅ ГОТОВО

---

### 2.2. Создать `app/Http/Controllers/Api/SyncActionsController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\SyncQueue;
use App\Services\MoySkladService;
use App\Services\ProductFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncActionsController extends Controller
{
    /**
     * POST /api/sync/{accountId}/products/all
     *
     * Синхронизировать всю номенклатуру из main в child
     */
    public function syncAllProducts(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Проверить что это дочерний аккаунт
        $link = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account not found'], 404);
        }

        // Получить настройки синхронизации
        $syncSettings = SyncSetting::where('account_id', $accountId)->first();

        if (!$syncSettings || !$syncSettings->sync_enabled) {
            return response()->json(['error' => 'Sync is not enabled for this account'], 400);
        }

        // Получить main аккаунт
        $mainAccount = Account::where('account_id', $mainAccountId)->first();
        $moysklad = app(MoySkladService::class);
        $filterService = app(ProductFilterService::class);

        $tasksCreated = 0;

        try {
            // Синхронизировать товары (product)
            if ($syncSettings->sync_products) {
                $products = $this->fetchAllProducts($moysklad, $mainAccount->access_token);
                $tasksCreated += $this->createSyncTasks($products, 'product', $accountId, $syncSettings, $filterService);
            }

            // Синхронизировать комплекты (bundle)
            if ($syncSettings->sync_bundles) {
                $bundles = $this->fetchAllBundles($moysklad, $mainAccount->access_token);
                $tasksCreated += $this->createSyncTasks($bundles, 'bundle', $accountId, $syncSettings, $filterService);
            }

            // Синхронизировать услуги (service)
            if ($syncSettings->sync_services) {
                $services = $this->fetchAllServices($moysklad, $mainAccount->access_token);
                $tasksCreated += $this->createSyncTasks($services, 'service', $accountId, $syncSettings, $filterService);
            }

            Log::info('Sync all products initiated', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'tasks_created' => $tasksCreated
            ]);

            return response()->json([
                'tasks_created' => $tasksCreated,
                'status' => 'queued',
                'message' => "Создано {$tasksCreated} задач синхронизации"
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to sync all products', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to create sync tasks: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Получить все товары
     */
    private function fetchAllProducts(MoySkladService $moysklad, string $token): array
    {
        $allProducts = [];
        $offset = 0;
        $limit = 1000;

        do {
            $response = $moysklad->request($token, 'GET', '/entity/product', [
                'limit' => $limit,
                'offset' => $offset
            ]);

            $products = $response['rows'] ?? [];
            $allProducts = array_merge($allProducts, $products);

            $offset += $limit;
        } while (count($products) === $limit);

        return $allProducts;
    }

    /**
     * Получить все комплекты
     */
    private function fetchAllBundles(MoySkladService $moysklad, string $token): array
    {
        $allBundles = [];
        $offset = 0;
        $limit = 1000;

        do {
            $response = $moysklad->request($token, 'GET', '/entity/bundle', [
                'limit' => $limit,
                'offset' => $offset
            ]);

            $bundles = $response['rows'] ?? [];
            $allBundles = array_merge($allBundles, $bundles);

            $offset += $limit;
        } while (count($bundles) === $limit);

        return $allBundles;
    }

    /**
     * Получить все услуги
     */
    private function fetchAllServices(MoySkladService $moysklad, string $token): array
    {
        $allServices = [];
        $offset = 0;
        $limit = 1000;

        do {
            $response = $moysklad->request($token, 'GET', '/entity/service', [
                'limit' => $limit,
                'offset' => $offset
            ]);

            $services = $response['rows'] ?? [];
            $allServices = array_merge($allServices, $services);

            $offset += $limit;
        } while (count($services) === $limit);

        return $allServices;
    }

    /**
     * Создать задачи синхронизации для сущностей
     */
    private function createSyncTasks(
        array $entities,
        string $entityType,
        string $accountId,
        SyncSetting $syncSettings,
        ProductFilterService $filterService
    ): int {
        $tasksCreated = 0;

        foreach ($entities as $entity) {
            // Применить фильтры
            if ($syncSettings->product_filters_enabled && $syncSettings->product_filters) {
                if (!$filterService->passes($entity, $syncSettings->product_filters)) {
                    continue; // Пропустить
                }
            }

            // Создать задачу
            SyncQueue::create([
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'entity_id' => $entity['id'],
                'operation' => 'create', // Или 'update' если проверять entity_mappings
                'priority' => 10, // Высокий приоритет для ручной синхронизации
                'scheduled_at' => now(),
                'status' => 'pending',
                'attempts' => 0
            ]);

            $tasksCreated++;
        }

        return $tasksCreated;
    }
}
```

**Статус:** ✅ ГОТОВО

---

### 2.3. Обновить `routes/api.php`

```php
Route::middleware(['moysklad.context'])->group(function () {
    // ... existing routes

    // Sync settings - расширенные методы
    Route::get('sync-settings/{accountId}/price-types', [SyncSettingsController::class, 'getPriceTypes']);
    Route::get('sync-settings/{accountId}/attributes', [SyncSettingsController::class, 'getAttributes']);
    Route::get('sync-settings/{accountId}/folders', [SyncSettingsController::class, 'getFolders']);

    // Sync actions
    Route::post('sync/{accountId}/products/all', [SyncActionsController::class, 'syncAllProducts']);
});
```

**Статус:** ✅ ГОТОВО

---

## Фаза 2: ЗАВЕРШЕНА ✅

Созданы все API методы и роуты для работы с настройками синхронизации.

---

## Фаза 3: Backend - Сервисы синхронизации (1.5 часа) ⏳

### 3.1. Обновить `app/Services/ProductSyncService.php`

**Добавить логику для учёта новых настроек:**

```php
// 1. Упаковки и штрихкоды синхронизируются всегда
// При синхронизации product включать:
// - packs[] массив упаковок
// - barcodes[] массив штрихкодов

// 2. Учитывать product_match_field
$matchField = $syncSettings->product_match_field ?? 'article';
// Искать существующий товар по этому полю

// 3. Учитывать price_mappings
if ($syncSettings->price_mappings && !empty($syncSettings->price_mappings)) {
    // Синхронизировать только выбранные цены с маппингом
}

// 4. Учитывать attribute_sync_list
if ($syncSettings->attribute_sync_list && !empty($syncSettings->attribute_sync_list)) {
    // Фильтровать атрибуты только выбранные
}

// 5. Учитывать create_product_folders
if (!$syncSettings->create_product_folders) {
    unset($productData['productFolder']);
}
```

### 3.2. Добавить синхронизацию услуг (services)

**Создать метод `syncService()` аналогично `syncProduct()`:**
- Загружать через `/entity/service/{id}`
- Синхронизировать: name, description, code, article, price, attributes
- Услуги НЕ имеют: variants, bundles, packs

### 3.3. Улучшить создание доп.полей

**Создать/обновить `app/Services/AttributeSyncService.php`:**
- Проверять по **названию И типу**
- Использовать существующую таблицу `attribute_mappings`

**Создать/обновить `app/Services/CharacteristicSyncService.php`:**
- Проверять по **названию**
- Использовать существующую таблицу `characteristic_mappings`

**Статус:** ❌ Не начато

---

## Фаза 4-6: Frontend (4.5 часа) ⏳

### Компоненты для создания:
1. `ProductFolderPicker.vue` - модальное окно с деревом папок
2. `FolderTreeNode.vue` - рекурсивный узел дерева
3. `ProductFilterBuilder.vue` - конструктор фильтров
4. Обновить `FranchiseSettings.vue` - добавить все новые секции

### API клиент:
Обновить `resources/js/api/index.js`:
```js
syncSettings: {
  getPriceTypes: (accountId) => axios.get(`/api/sync-settings/${accountId}/price-types`),
  getAttributes: (accountId) => axios.get(`/api/sync-settings/${accountId}/attributes`),
  getFolders: (accountId) => axios.get(`/api/sync-settings/${accountId}/folders`),
},
syncActions: {
  syncAllProducts: (accountId) => axios.post(`/api/sync/${accountId}/products/all`),
}
```

**Статус:** ❌ Не начато

---

## Фаза 7: Тестирование (1 час) ⏳

### Чек-лист тестирования:
- [ ] Миграция выполнена
- [ ] API эндпоинты работают (price-types, attributes, folders)
- [ ] Конструктор фильтров работает
- [ ] Модальное окно выбора папок работает
- [ ] Маппинг цен сохраняется и загружается
- [ ] Выбор доп.полей работает
- [ ] Кнопка "Синхронизировать всё" создаёт задачи
- [ ] Задачи обрабатываются ProcessSyncQueueJob
- [ ] Товары синхронизируются с упаковками и штрихкодами
- [ ] Фильтры применяются корректно

**Статус:** ❌ Не начато

---

## Итоговая оценка: ~9 часов

**Готово к началу работы! 🚀**

Давай начнём с **Фазы 1: Backend - База данных**?
