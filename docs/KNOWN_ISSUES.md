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

**Последнее обновление:** 26 октября 2025
