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

**Последнее обновление:** 14 октября 2025
