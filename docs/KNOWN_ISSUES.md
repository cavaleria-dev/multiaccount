# Известные проблемы и решения

Этот файл содержит документацию по проблемам, с которыми мы сталкивались при разработке, и их решениям.

---

## Проблема: Пустой список типов цен (2025-10-14)

### Симптомы
- Endpoint `/api/sync-settings/{accountId}/price-types` возвращал пустой ответ: `{"main":[],"child":[]}`
- Выпадающие списки в разделе "Сопоставление типов цен" были пустыми

### Причины

#### 1. Неправильный API endpoint (главная причина)
**Проблема:** Использовался несуществующий endpoint `context/companysettings/pricetype`

**Решение:** Изменен на правильный endpoint `context/companysettings`

**Объяснение:**
- МойСклад API не имеет отдельного endpoint для типов цен
- Endpoint `context/companysettings` возвращает **все** настройки компании, включая массив `priceTypes`
- Структура ответа:
```json
{
  "meta": {...},
  "currency": {...},
  "priceTypes": [
    {
      "meta": {
        "href": "https://api.moysklad.ru/api/remap/1.2/context/companysettings/pricetype/672559f1-cbf3-11e1-9eb9-889ffa6f2222",
        "type": "pricetype",
        "mediaType": "application/json"
      },
      "id": "672559f1-cbf3-11e1-9eb9-889ffa6f2222",
      "name": "Цена для друзей",
      "externalCode": "cbcf493b-55bc-11d9-848a-00112f432222"
    }
  ],
  "discountStrategy": "bySum",
  "globalOperationNumbering": true,
  ...
}
```

#### 2. Неправильное обращение к структуре данных
**Проблема:** Код обращался к данным напрямую без учета обертки `['data']`

**Решение:** Исправлено обращение с учетом структуры ответа `MoySkladService`

**Объяснение:**
- `MoySkladService::get()` возвращает структуру: `['data' => $response->json(), 'rateLimitInfo' => [...]]`
- Правильное обращение: `$response['data']['priceTypes']` вместо `$response['priceTypes']`

### Исправленные файлы

1. **SyncSettingsController.php** (строки 172-173, 178-190)
   - Изменен endpoint: `context/companysettings/pricetype` → `context/companysettings`
   - Исправлено обращение: `$mainPriceTypes['priceTypes']` → `$mainPriceTypes['data']['priceTypes']`

2. **ProductSyncService.php** (строка 536)
   - Изменен endpoint в методе `getOrCreatePriceType()`

3. **ServiceSyncService.php** (строка 448)
   - Изменен endpoint в методе `getOrCreatePriceType()`

4. **MoySkladService.php** (строки 365-366)
   - Изменен endpoint в методе `getPriceTypes()`
   - Исправлено обращение к данным

### Коммиты
- `834551c` - fix: Исправлена структура доступа к данным API в SyncSettingsController
- `10268a8` - fix: Исправлен endpoint для получения типов цен

### Как избежать в будущем
1. **Всегда проверяйте документацию МойСклад API** перед использованием endpoint'ов
2. **Помните о структуре ответа `MoySkladService`** - все методы возвращают `['data' => ..., 'rateLimitInfo' => ...]`
3. **Тестируйте endpoint'ы вручную** через curl/Postman перед интеграцией
4. **Логируйте полную структуру ответов** при отладке новых endpoint'ов

### Пример правильного использования

```php
// Получить типы цен
$response = $moyskladService
    ->setAccessToken($account->access_token)
    ->get('context/companysettings');

// Правильное обращение к данным
$priceTypes = $response['data']['priceTypes'] ?? [];

// Обработка
foreach ($priceTypes as $priceType) {
    echo $priceType['id'] . ': ' . $priceType['name'] . "\n";
}
```

### Связанные документы
- [CLAUDE.md](../CLAUDE.md) - раздел "Important Gotchas" #9-10
- [CLAUDE.md](../CLAUDE.md) - раздел "API Endpoints"
- [МойСклад API: Настройки компании](https://dev.moysklad.ru/doc/api/remap/1.2/#mojsklad-json-api-obschie-swedeniq-nastrojki-kompanii)

---

## Проблема: Ошибка 1021 при batch синхронизации модификаций (2025-10-26)

### Симптомы
- При batch синхронизации модификаций (variants) МойСклад API возвращает ошибку 1021
- Множественные ошибки вида:
  ```json
  {
    "error": "Объект с типом 'pricetype' и идентификатором 'bcce9661-9d9c-11ee-0a80-153f0024c570' не найден",
    "code": 1021
  }
  ```
- Продукты (products) и услуги (services) синхронизируются без ошибок
- Проблема появляется только при синхронизации variants

### Причины

**Корневая причина:** В методе `VariantSyncService::prepareVariantForBatch()` цены модификаций копировались напрямую с ID типов цен из главного аккаунта без маппинга на соответствующие ID в дочернем аккаунте.

**Проблемный код:**
```php
// ❌ БЫЛО (app/Services/VariantSyncService.php:1356-1358)
if (isset($variant['salePrices'])) {
    $variantData['salePrices'] = $variant['salePrices'];  // ID из main account!
}
```

**Что происходило:**
1. Загружался variant из **main account** с expand: `expand=product.salePrices`
2. В `salePrices` приходила структура с ID типа цены из main account:
   ```json
   {
     "value": 1000,
     "priceType": {
       "meta": {
         "href": "https://.../pricetype/bcce9661-9d9c-11ee-0a80-153f0024c570"
       },
       "id": "bcce9661-9d9c-11ee-0a80-153f0024c570"  // ← ID из main account!
     }
   }
   ```
3. Эта структура **копировалась как есть** в POST запрос в **child account**
4. Child account не находил ID `bcce9661-...` у себя → **ошибка 1021**

**Почему это работало для products/services:**
- В `ProductSyncService::prepareProductForBatch()` и `ServiceSyncService::prepareServiceForBatch()` использовался метод `syncPrices()` из трейта `SyncHelpers`
- `syncPrices()` выполняет маппинг ID типов цен через таблицу `price_type_mappings` или настройки `price_mappings`

### Решение

Заменено прямое копирование `salePrices` на вызов метода `syncPrices()` с логикой проверки custom prices (аналогично методам `createVariant()` и `updateVariant()`).

**Исправленный код:**
```php
// ✅ СТАЛО (app/Services/VariantSyncService.php:1374-1428)

// 1. Проверить, имеет ли variant собственные цены (отличные от parent product)
$mainProduct = $variant['product'] ?? null;
$hasCustomPrices = false;

if ($mainProduct && isset($mainProduct['salePrices'])) {
    $hasCustomPrices = $this->variantHasCustomPrices($variant, $mainProduct);
} else {
    // Если parent product не expand-нут - безопасный fallback (синхронизируем цены)
    $hasCustomPrices = true;
}

// 2. Синхронизировать цены ТОЛЬКО если variant имеет собственные цены
if ($hasCustomPrices) {
    // Использовать syncPrices() для маппинга ID типов цен
    $prices = $this->syncPrices(
        $mainAccountId,
        $childAccountId,
        $variant,
        $syncSettings
    );

    if (!empty($prices['salePrices'])) {
        $variantData['salePrices'] = $prices['salePrices'];  // ← Замапленные ID из child account
    }

    if (isset($prices['buyPrice'])) {
        $variantData['buyPrice'] = $prices['buyPrice'];
    }
} else {
    // Variant наследует цены от product - НЕ отправляем salePrices/buyPrice
    // НЕ добавляем salePrices и buyPrice в $variantData
}
```

**Дополнительные улучшения:**
- Добавлены недостающие поля: `externalCode`, `description`
- Исправлен вызов `syncPacks()` с параметром `baseUomId`
- Добавлена обработка `buyPrice` (ранее отсутствовала)
- Добавлен вызов `addAdditionalFields()` для НДС, маркировки и т.д.
- Добавлено подробное логирование для отладки

### Исправленные файлы

1. **app/Services/VariantSyncService.php** (метод `prepareVariantForBatch()`, строки 1303-1437)
   - Заменено прямое копирование `salePrices` на вызов `syncPrices()`
   - Добавлена проверка custom prices через `variantHasCustomPrices()`
   - Добавлены недостающие поля и логирование

### Коммиты
- `8a99bd4` - fix: Исправлена ошибка 1021 при batch синхронизации модификаций

### Как избежать в будущем

1. **Всегда используйте `syncPrices()` для синхронизации цен** - этот метод обеспечивает корректный маппинг ID типов цен между аккаунтами
2. **Не копируйте данные напрямую из main в child account** - всегда проверяйте, нужен ли маппинг ID (для типов цен, атрибутов, характеристик и т.д.)
3. **Проверяйте работающие аналоги** - если добавляете новый метод синхронизации, посмотрите как это реализовано для других типов сущностей
4. **Тестируйте на реальных данных** - ошибки маппинга проявляются только при синхронизации между разными аккаунтами

### Пример правильного использования

```php
// ❌ НЕПРАВИЛЬНО - прямое копирование
$variantData['salePrices'] = $variant['salePrices'];

// ✅ ПРАВИЛЬНО - через syncPrices() с маппингом
$prices = $this->syncPrices(
    $mainAccountId,
    $childAccountId,
    $variant,
    $syncSettings
);

if (!empty($prices['salePrices'])) {
    $variantData['salePrices'] = $prices['salePrices'];
}
```

### Связанные документы
- [17-variant-assortment-sync.md](17-variant-assortment-sync.md) - архитектура синхронизации модификаций
- [04-batch-sync.md](04-batch-sync.md) - batch синхронизация
- [10-common-patterns.md](10-common-patterns.md) - общие паттерны

---

## Проблема: Существующие товары не обновлялись при batch retry (2025-10-28)

### Симптомы
- При падении batch задачи товары, которые уже существовали в child account, **НЕ обновлялись**
- В логах появлялось сообщение: `Entity already exists in child account, skipping retry`
- Для существующих товаров retry задачи **не создавались** вообще
- Новые товары синхронизировались корректно, но обновление существующих не происходило

### Причины

**Корневая причина:** В методе `ProcessSyncQueueJob::handleBatchTaskFailure()` при падении batch задачи система проверяла существование товаров в child account и **полностью пропускала** создание retry задач для уже существующих товаров.

**Проблемный код:**
```php
// ❌ БЫЛО (app/Jobs/ProcessSyncQueueJob.php:407-416)
if ($existingEntity && !($existingEntity['archived'] ?? false)) {
    Log::info('Entity already exists in child account, skipping retry', [...]);
    $skippedExisting++;
    continue;  // ← НЕ создаём retry задачу!
}
```

**Что происходило:**
1. Batch задача падала (например, временная ошибка сети, 500 ошибка)
2. Система создавала индивидуальные retry задачи для каждой сущности
3. Для каждой сущности проверялось: существует ли она в child account?
4. Если **ДА** → retry задача **НЕ создавалась** → товар **НЕ обновлялся**!
5. Если **НЕТ** (404) → удалялся stale mapping, создавалась retry с `operation='create'`

### Решение

Изменена логика: **всегда создавать retry задачу** с правильной операцией (`update` vs `create`).

**Исправленный код:**
```php
// ✅ СТАЛО (app/Jobs/ProcessSyncQueueJob.php:407-463)
$entityExistsInChild = false;

if ($existingMapping && $childAccount) {
    // Проверить существует ли сущность в child account
    if ($existingEntity && !($existingEntity['archived'] ?? false)) {
        $entityExistsInChild = true;
        Log::info('Entity exists in child account, will create UPDATE retry', [...]);
    }

    // Удалить stale mapping ТОЛЬКО если сущность НЕ существует
    if (!$entityExistsInChild) {
        $existingMapping->delete();
        $deletedMappingsCount++;
    }
}

// Создать retry с операцией UPDATE (если существует) или CREATE (если нет)
$operation = $entityExistsInChild ? 'update' : 'create';

SyncQueue::create([
    'operation' => $operation,  // ⭐ UPDATE для существующих, CREATE для новых
    // ...
]);
```

### Исправленные файлы
- **app/Jobs/ProcessSyncQueueJob.php** (метод `handleBatchTaskFailure()`, строки 389-481)

### Коммиты
- `1787ae9` - fix: Исправлена проблема с не обновлением существующих товаров при batch retry

### Как избежать в будущем
1. **Всегда создавайте retry задачи** - даже если сущность уже существует
2. **Используйте правильную операцию** - `update` для существующих, `create` для новых
3. **Не пропускайте обработку** - пропуск допустим только для фильтрации
4. **Тестируйте fallback сценарии** - проверяйте, что происходит при падении batch

### Пример правильного использования

```php
// ❌ НЕПРАВИЛЬНО - пропуск существующих
if ($existingEntity) {
    continue;  // НЕ создаём retry!
}

// ✅ ПРАВИЛЬНО - retry с правильной операцией
$operation = $existingEntity ? 'update' : 'create';
SyncQueue::create(['operation' => $operation, ...]);
```

### Связанные документы
- [04-batch-sync.md](04-batch-sync.md) - batch синхронизация
- [16-sync-handlers.md](16-sync-handlers.md) - архитектура обработчиков

---

## Проблема: Группы товаров (productFolder) не синхронизировались для услуг (2025-10-28)

### Симптомы
- При синхронизации услуг (services) поле `productFolder` **НЕ передавалось** в child account
- Услуги создавались/обновлялись **без группы**
- Товары (products) и комплекты (bundles) синхронизировали productFolder корректно

### Причины

**Корневая причина:** В `ServiceSyncService` полностью отсутствовала логика синхронизации `productFolder`.

Отсутствовала:
- ❌ Зависимость `ProductFolderSyncService`
- ❌ Синхронизация productFolder в `createService()`
- ❌ Синхронизация productFolder в `updateService()`
- ❌ Синхронизация productFolder в `prepareServiceForBatch()`

**МойСклад API поддерживает** поле `productFolder` для услуг.

### Решение

Добавлена полная поддержка синхронизации productFolder в `ServiceSyncService`:

1. **Зависимость** - добавлен `ProductFolderSyncService` в конструктор
2. **createService()** - синхронизация через `syncProductFolder()` (рекурсивно)
3. **updateService()** - идентичная логика
4. **prepareServiceForBatch()** - использует CACHED mapping (0 GET запросов)

**Исправленный код:**
```php
// 1. Зависимость
protected ProductFolderSyncService $productFolderSyncService;

// 2. В createService/updateService
if (isset($service['productFolder'])) {
    if ($settings->create_product_folders) {
        $folderId = $this->extractEntityId($service['productFolder']['meta']['href']);
        $childFolderId = $this->productFolderSyncService->syncProductFolder($mainAccountId, $childAccountId, $folderId);
        if ($childFolderId) {
            $serviceData['productFolder'] = [
                'meta' => [
                    'href' => config('moysklad.api_url') . "/entity/productfolder/{$childFolderId}",
                    'type' => 'productfolder',
                    'mediaType' => 'application/json'
                ]
            ];
        }
    }
}

// 3. В prepareServiceForBatch (cached mapping)
if ($settings->create_product_folders && isset($service['productFolder']['id'])) {
    $folderMapping = EntityMapping::where([
        'parent_account_id' => $mainAccountId,
        'child_account_id' => $childAccountId,
        'entity_type' => 'productfolder',
        'parent_entity_id' => $service['productFolder']['id']
    ])->first();

    if ($folderMapping) {
        $serviceData['productFolder'] = [...];
    }
}
```

### Исправленные файлы
- **app/Services/ServiceSyncService.php** (4 изменения, +74 строки)

### Коммиты
- `378f8cc` - feat: Добавлена синхронизация productFolder для услуг (services)

### Как избежать в будущем
1. **Проверяйте паритет между sync services** - если поле работает для product, проверьте для service/bundle
2. **Изучайте документацию МойСклад API** - убедитесь в поддержке поля
3. **Используйте одинаковые паттерны** - копируйте working implementation
4. **Тестируйте все методы** - индивидуальную и batch синхронизацию

### Пример правильного использования

```php
// ✅ ПРАВИЛЬНО - синхронизация productFolder для любого типа

// 1. Зависимость
protected ProductFolderSyncService $productFolderSyncService;

// 2. Индивидуальная синхронизация
if (isset($entity['productFolder']) && $settings->create_product_folders) {
    $folderId = $this->extractEntityId($entity['productFolder']['meta']['href']);
    $childFolderId = $this->productFolderSyncService->syncProductFolder($mainAccountId, $childAccountId, $folderId);
    if ($childFolderId) {
        $entityData['productFolder'] = [...];
    }
}

// 3. Batch синхронизация (cached)
if ($settings->create_product_folders && isset($entity['productFolder']['id'])) {
    $folderMapping = EntityMapping::where([...])->first();
    if ($folderMapping) {
        $entityData['productFolder'] = [...];
    }
}
```

### Связанные документы
- [05-services.md](05-services.md) - Service Layer архитектура
- [14-product-folder-sync.md](14-product-folder-sync.md) - синхронизация групп

---

## Шаблон для новых проблем

### Проблема: [Краткое описание]

### Симптомы
- [Что наблюдается]
- [Сообщения об ошибках]

### Причины
[Корневая причина проблемы]

### Решение
[Как исправлено]

### Исправленные файлы
- [Список файлов с номерами строк]

### Коммиты
- [Хеши коммитов]

### Как избежать в будущем
- [Рекомендации]

### Пример правильного использования
```php
// Код
```

---

**Последнее обновление:** 28 октября 2025
