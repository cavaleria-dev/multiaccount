# Фильтрация товаров при синхронизации

Система поддерживает гибкую фильтрацию товаров при синхронизации из главного аккаунта в дочерние. Фильтры позволяют настроить, какие товары будут синхронизироваться для каждой франшизы.

## Структура фильтров

Фильтры хранятся в поле `product_filters` таблицы `sync_settings` в формате JSON и имеют следующую структуру:

```json
{
  "enabled": true,
  "mode": "whitelist",
  "logic": "AND",
  "conditions": [...]
}
```

### Параметры верхнего уровня:

- **enabled** (boolean) - включить/выключить фильтрацию
- **mode** (string) - режим фильтрации:
  - `whitelist` - синхронизировать только товары, соответствующие условиям
  - `blacklist` - синхронизировать все товары, кроме соответствующих условиям
- **logic** (string) - логический оператор для объединения условий:
  - `AND` - все условия должны выполняться
  - `OR` - хотя бы одно условие должно выполняться
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

## Ограничения

- Фильтры применяются только к товарам (product/variant/bundle)
- Фильтры не применяются к заказам
- Максимальная глубина вложенности групп: не ограничена (но лучше не более 5 уровней)
- Фильтры проверяются при каждом UPDATE товара, поэтому товар может быть исключен при изменении

## FAQ

**Q: Что произойдет, если товар перестанет соответствовать фильтрам?**
A: При следующем UPDATE товара он не будет синхронизирован, но останется в дочернем аккаунте. Для удаления используйте архивацию.

**Q: Можно ли фильтровать по нескольким доп.полям одновременно?**
A: Да, просто добавьте несколько условий типа "attribute" с логикой AND или OR.

**Q: Как узнать UUID группы товара или атрибута?**
A: Используйте API МойСклад или посмотрите в URL при просмотре в веб-интерфейсе.

**Q: Фильтры работают для модификаций (variant)?**
A: Да, фильтры проверяются для модификаций на основе родительского товара (product).

**Q: Можно ли использовать регулярные выражения?**
A: Нет, но есть операторы contains, starts_with, ends_with для строк.
