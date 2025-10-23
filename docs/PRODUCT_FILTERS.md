# Фильтрация товаров при синхронизации

Система поддерживает гибкую фильтрацию товаров при синхронизации из главного аккаунта в дочерние. Фильтры позволяют настроить, какие товары будут синхронизироваться для каждой франшизы.

## Управление фильтрацией

### Два уровня включения/выключения:

1. **Главный переключатель** - `sync_settings.product_filters_enabled` (boolean)
   - Управляется через toggle в UI: "Включить фильтрацию товаров"
   - Если `false` - фильтры вообще не загружаются, синхронизируется весь ассортимент
   - Если `true` - система пытается применить фильтры из `product_filters`

2. **Автоматическая проверка валидности** - `enabled` внутри JSON
   - Устанавливается backend автоматически на основе наличия валидных условий
   - Если есть валидные условия → `enabled = true` → фильтры применяются
   - Если нет валидных условий → `enabled = false` → синхронизация без фильтров (защита)

### Как это работает:

```
Toggle в UI (product_filters_enabled)
   │
   ├─ false → Фильтры НЕ загружаются → Синхронизация ВСЕГО
   │
   └─ true → Загружаются фильтры из product_filters
              │
              └─ Backend проверяет валидность условий
                 │
                 ├─ Есть валидные условия → enabled = true → Фильтры применяются
                 └─ Нет валидных условий → enabled = false → Синхронизация ВСЕГО
```

## Структура фильтров

### Форматы хранения

Фильтры хранятся в поле `product_filters` таблицы `sync_settings` в формате JSON. Поддерживается два формата:

#### 1. Полный формат (внутренний)

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "AND",
  "conditions": [...]
}
```

#### 2. Упрощенный формат (из UI)

```json
{
  "conditions": [...]
}
```

**Важно:** UI сохраняет упрощенный формат без полей `enabled`, `mode`, `logic`. Backend **автоматически конвертирует** его в полный формат при обработке.

### Автоматическая конвертация

При загрузке фильтров из БД происходит автоматическая конвертация:

```php
// Если формат: {conditions: [...]} без enabled
// → автоматически конвертируется в:
{
  "enabled": true,      // ← Устанавливается автоматически если есть валидные условия
  "mode": "whitelist",  // ← Всегда whitelist
  "logic": "AND",       // ← Всегда AND (OR не поддерживается МойСклад API)
  "conditions": [...]   // ← Условия из UI
}
```

**Логика установки `enabled`:**
- Если после конвертации есть хотя бы одно валидное условие → `enabled = true`
- Если нет валидных условий (пустой массив, невалидные типы) → `enabled = false`

**Исправление (commit 7c50d8c):** Добавлена проверка формата `{conditions: [...]}` без `enabled` для корректной работы фильтров из UI.

### Параметры верхнего уровня:

- **enabled** (boolean) - включить/выключить фильтрацию
  - Устанавливается автоматически на основе наличия валидных условий
- **mode** (string) - режим фильтрации:
  - `whitelist` - синхронизировать только товары, соответствующие условиям
  - `blacklist` - синхронизировать все товары, кроме соответствующих условиям (не используется)
- **logic** (string) - логический оператор для объединения условий:
  - `AND` - все условия должны выполняться (единственный поддерживаемый вариант)
  - `OR` - НЕ поддерживается МойСклад API для /entity/assortment
- **conditions** (array) - массив условий фильтрации

## Типы условий

### 1. Фильтр по группе товаров (folder)

Синхронизировать только товары из определенных групп:

```json
{
  "type": "folder",
  "operator": "in",
  "value": ["folder-uuid-1", "folder-uuid-2"]
}
```

**Операторы:**
- `in` - товар находится в одной из указанных групп
- `not_in` - товар НЕ находится ни в одной из указанных групп

### 2. Фильтр по доп.полю (attribute)

Синхронизировать товары с определенными значениями доп.полей.

**Важно:** Система автоматически определяет тип доп.поля (string, long, double, boolean, time, customentity) и применяет соответствующие операторы сравнения.

#### Типы доп.полей МойСклад:

- **string** - строка
- **long** - целое число
- **double** - дробное число
- **boolean** - флаг (true/false)
- **time** - дата/время (timestamp)
- **customentity** - справочник (ссылка на элемент справочника)
- **text** - многострочный текст
- **link** - ссылка
- **file** - файл

#### Примеры для разных типов:

**Строка (string/text/link):**
```json
{
  "type": "attribute",
  "attribute_id": "region-attr-uuid",
  "operator": "equals",
  "value": "Москва"
}
```

**Число (long/double):**
```json
{
  "type": "attribute",
  "attribute_id": "price-attr-uuid",
  "operator": "greater_than",
  "value": 1000
}
```

**Флаг (boolean):**
```json
{
  "type": "attribute",
  "attribute_id": "for-franchise-uuid",
  "operator": "equals",
  "value": true
}
```

**Справочник (customentity):**
```json
{
  "type": "attribute",
  "attribute_id": "brand-attr-uuid",
  "operator": "in",
  "value": ["brand-element-uuid-1", "brand-element-uuid-2"]
}
```

**Дата/время (time):**
```json
{
  "type": "attribute",
  "attribute_id": "created-date-uuid",
  "operator": "greater_than",
  "value": "2024-01-01"
}
```

**Операторы для строк:**
- `equals` - точное совпадение
- `not_equals` - не равно
- `contains` - содержит подстроку
- `not_contains` - не содержит подстроку
- `starts_with` - начинается с
- `ends_with` - заканчивается на

**Операторы для чисел:**
- `equals` - равно
- `not_equals` - не равно
- `greater_than` - больше
- `less_than` - меньше
- `greater_or_equal` - больше или равно
- `less_or_equal` - меньше или равно

**Операторы для массивов:**
- `in` - значение находится в массиве
- `not_in` - значения нет в массиве

**Операторы для null:**
- `is_null` - значение отсутствует
- `is_not_null` - значение присутствует

### 3. Группа условий (group)

Объединить несколько условий со своей логикой:

```json
{
  "type": "group",
  "logic": "OR",
  "conditions": [
    {
      "type": "attribute",
      "attribute_id": "attr-uuid",
      "operator": "equals",
      "value": "VIP"
    },
    {
      "type": "attribute",
      "attribute_id": "attr-uuid-2",
      "operator": "equals",
      "value": "Премиум"
    }
  ]
}
```

## Примеры использования

### Пример 1: Простой фильтр по группе

Синхронизировать только товары из группы "Косметика":

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "AND",
  "conditions": [
    {
      "type": "folder",
      "operator": "in",
      "value": ["kosmetika-folder-uuid"]
    }
  ]
}
```

### Пример 2: Фильтр по доп.полю

Синхронизировать товары, где "Для франшизы" = "Да":

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "AND",
  "conditions": [
    {
      "type": "attribute",
      "attribute_id": "franchise-attr-uuid",
      "operator": "equals",
      "value": true
    }
  ]
}
```

### Пример 3: Комбинированный фильтр (AND)

Синхронизировать товары из группы "Одежда" И с регионом "Москва":

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "AND",
  "conditions": [
    {
      "type": "folder",
      "operator": "in",
      "value": ["odezhda-folder-uuid"]
    },
    {
      "type": "attribute",
      "attribute_id": "region-attr-uuid",
      "operator": "equals",
      "value": "Москва"
    }
  ]
}
```

### Пример 4: Фильтр с логикой OR

Синхронизировать товары из группы "Косметика" ИЛИ "Парфюмерия":

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "OR",
  "conditions": [
    {
      "type": "folder",
      "operator": "in",
      "value": ["kosmetika-folder-uuid"]
    },
    {
      "type": "folder",
      "operator": "in",
      "value": ["parfum-folder-uuid"]
    }
  ]
}
```

### Пример 5: Сложная группировка

Синхронизировать товары из группы "Одежда" И (регион "Москва" ИЛИ категория "VIP"):

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "AND",
  "conditions": [
    {
      "type": "folder",
      "operator": "in",
      "value": ["odezhda-folder-uuid"]
    },
    {
      "type": "group",
      "logic": "OR",
      "conditions": [
        {
          "type": "attribute",
          "attribute_id": "region-attr-uuid",
          "operator": "equals",
          "value": "Москва"
        },
        {
          "type": "attribute",
          "attribute_id": "category-attr-uuid",
          "operator": "equals",
          "value": "VIP"
        }
      ]
    }
  ]
}
```

### Пример 6: Blacklist - исключить товары

Синхронизировать ВСЕ товары, кроме тех, что в группе "Для офиса":

```json
{
  "enabled": true,
  "mode": "blacklist",
  "logic": "AND",
  "conditions": [
    {
      "type": "folder",
      "operator": "in",
      "value": ["office-folder-uuid"]
    }
  ]
}
```

### Пример 7: Фильтр по цене

Синхронизировать только товары дороже 1000 рублей:

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "AND",
  "conditions": [
    {
      "type": "attribute",
      "attribute_id": "price-attr-uuid",
      "operator": "greater_than",
      "value": 1000
    }
  ]
}
```

### Пример 8: Фильтр по наличию доп.поля

Синхронизировать только товары, у которых заполнено поле "Артикул производителя":

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "AND",
  "conditions": [
    {
      "type": "attribute",
      "attribute_id": "vendor-code-attr-uuid",
      "operator": "is_not_null",
      "value": null
    }
  ]
}
```

## Настройка фильтров через БД

### Включить фильтрацию для франшизы:

```sql
UPDATE sync_settings
SET
  product_filters_enabled = true,
  product_filters = '{
    "enabled": true,
    "mode": "whitelist",
    "logic": "AND",
    "conditions": [
      {
        "type": "folder",
        "operator": "in",
        "value": ["folder-uuid"]
      }
    ]
  }'::jsonb
WHERE account_id = 'child-account-id';
```

### Отключить фильтрацию:

```sql
UPDATE sync_settings
SET product_filters_enabled = false
WHERE account_id = 'child-account-id';
```

## Тестирование фильтров через tinker

```php
use App\Services\ProductFilterService;

$filterService = app(ProductFilterService::class);

// Пример товара
$product = [
    'id' => 'product-uuid',
    'name' => 'Товар 1',
    'productFolder' => [
        'meta' => [
            'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/productfolder/folder-uuid'
        ]
    ],
    'attributes' => [
        [
            'meta' => [
                'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/metadata/attributes/attr-uuid'
            ],
            'name' => 'Регион',
            'type' => 'string',
            'value' => 'Москва'
        ]
    ]
];

// Пример фильтров
$filters = [
    'enabled' => true,
    'mode' => 'whitelist',
    'logic' => 'AND',
    'conditions' => [
        [
            'type' => 'folder',
            'operator' => 'in',
            'value' => ['folder-uuid']
        ]
    ]
];

// Проверка
$passes = $filterService->passes($product, $filters);
var_dump($passes); // true или false

// Валидация фильтров
$errors = $filterService->validate($filters);
var_dump($errors); // массив ошибок или пустой массив
```

## Валидация фильтров

ProductFilterService включает встроенную валидацию:

```php
$errors = $filterService->validate($filters);

if (!empty($errors)) {
    // Есть ошибки в конфигурации
    foreach ($errors as $error) {
        echo $error . "\n";
    }
}
```

Валидация проверяет:
- Наличие обязательных полей
- Корректность типов данных
- Допустимость значений операторов и логических операторов
- Корректность вложенных групп

## Производительность

Фильтрация выполняется **до** создания/обновления товара в дочернем аккаунте, что экономит:
- API запросы к МойСклад
- Место в БД дочернего аккаунта
- Время синхронизации

Если товар не проходит фильтры:
- Он не синхронизируется
- Маппинг не создается
- В логах записывается `debug` сообщение

## Изменение фильтров

При изменении фильтров:
1. Новые товары будут проверяться по новым правилам
2. Уже синхронизированные товары остаются в дочернем аккаунте
3. Для ре-синхронизации нужно:
   - Удалить маппинги: `DELETE FROM entity_mappings WHERE child_account_id = '...'`
   - Запустить массовую синхронизацию заново

## Применение к услугам (services)

**С версии 2025-10-20** фильтры применяются не только к товарам, но и к **услугам**.

### Почему фильтры работают для услуг?

Услуги в МойСклад имеют ту же структуру данных, что и товары:
- ✅ **attributes** (доп.поля) - полностью идентичны товарам
- ✅ **productFolder** (группа) - услуги тоже могут быть в группах
- ✅ Все операторы фильтров универсальны (equals, contains, in, и т.д.)

### Настройка фильтров для услуг

Фильтры для услуг настраиваются **точно так же**, как для товаров:

1. **Включить синхронизацию услуг** в настройках франшизы (`sync_services = true`)
2. **Включить фильтрацию** (`product_filters_enabled = true`)
3. **Настроить условия** в `product_filters` (по атрибутам и/или группам)

**Важно:** Одни и те же фильтры применяются к **товарам И услугам** для данного дочернего аккаунта.

### Пример: Фильтр услуг по региону

Синхронизировать только услуги, где доп.поле "Регион" = "Москва":

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "AND",
  "conditions": [
    {
      "type": "attribute",
      "attribute_id": "region-attr-uuid",
      "operator": "equals",
      "value": "Москва"
    }
  ]
}
```

Этот же фильтр будет применяться к товарам И услугам.

### Пример: Фильтр по флагу "Для франшизы"

Создайте доп.поле "Для франшизы" (тип: Флаг) в обеих номенклатурах (товары и услуги):

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "AND",
  "conditions": [
    {
      "type": "attribute",
      "attribute_id": "for-franchise-attr-uuid",
      "operator": "equals",
      "value": true
    }
  ]
}
```

Теперь синхронизируются только товары и услуги с установленным флагом.

### Пример: Разные фильтры для товаров и услуг

Если нужны **разные** условия для товаров и услуг, используйте **разные доп.поля**:

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "OR",
  "conditions": [
    {
      "type": "attribute",
      "attribute_id": "product-franchise-flag-uuid",
      "operator": "equals",
      "value": true
    },
    {
      "type": "attribute",
      "attribute_id": "service-franchise-flag-uuid",
      "operator": "equals",
      "value": true
    }
  ]
}
```

Создайте два доп.поля:
- "Товар для франшизы" - только в товарах
- "Услуга для франшизы" - только в услугах

Фильтр с логикой OR пропустит любой товар с первым флагом ИЛИ любую услугу со вторым флагом.

### Поле сопоставления для услуг

**Важно:** У услуг и товаров **разные поля сопоставления**:

- **Товары** (`product_match_field`): name, **article**, code, externalCode, barcode
- **Услуги** (`service_match_field`): name, code, externalCode, barcode (**НЕТ article**)

Услуги в МойСклад **не имеют поля "Артикул"** (article). Поэтому:

1. Если для товаров выбран "Артикул" - товары синхронизируются по артикулу
2. Для услуг нужно выбрать другое поле (например, "Код" или "Наименование")
3. Услуги с пустым значением в выбранном поле **НЕ синхронизируются**

Это настраивается в UI:
- **Поле для сопоставления товаров** - отдельный селектор
- **Поле для сопоставления услуг** - отдельный селектор (без артикула)

## Ограничения

- Фильтры применяются к товарам (product/variant/bundle) и услугам (service)
- Фильтры НЕ применяются к заказам (customerorder/retaildemand/purchaseorder)
- Максимальная глубина вложенности групп: не ограничена (но лучше не более 5 уровней)
- Фильтры проверяются при каждом UPDATE товара/услуги, поэтому товар может быть исключен при изменении
- Одни и те же фильтры применяются к товарам И услугам (нельзя настроить отдельно)

## FAQ

**Q: Что произойдет, если товар/услуга перестанет соответствовать фильтрам?**
A: При следующем UPDATE товара/услуги он не будет синхронизирован, но останется в дочернем аккаунте. Для удаления используйте архивацию.

**Q: Можно ли фильтровать по нескольким доп.полям одновременно?**
A: Да, просто добавьте несколько условий типа "attribute" с логикой AND или OR.

**Q: Как узнать UUID группы товара или атрибута?**
A: Используйте API МойСклад или посмотрите в URL при просмотре в веб-интерфейсе.

**Q: Фильтры работают для модификаций (variant)?**
A: Да, фильтры проверяются для модификаций на основе родительского товара (product).

**Q: Фильтры работают для услуг (service)?**
A: Да! С версии 2025-10-20 фильтры применяются к услугам точно так же, как к товарам. Услуги имеют attributes и productFolder, поэтому все условия фильтров работают одинаково.

**Q: Можно ли настроить разные фильтры для товаров и услуг?**
A: Нет, одни и те же фильтры применяются к обеим номенклатурам. Но можно использовать разные доп.поля с логикой OR (см. пример выше).

**Q: Почему услуги не синхронизируются, хотя включены?**
A: Проверьте:
1. Фильтры включены? (`product_filters_enabled = true`)
2. Услуги проходят условия фильтров? (доп.поля заполнены, группа соответствует)
3. Поле сопоставления заполнено? (service_match_field указывает на непустое поле)

**Q: Можно ли использовать регулярные выражения?**
A: Нет, но есть операторы contains, starts_with, ends_with для строк.
