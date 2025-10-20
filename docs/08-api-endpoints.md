# API Endpoints
## API Endpoints

### Sync Settings - Extended

**GET** `/api/sync-settings/{accountId}/price-types`
- Получить типы цен из main и child аккаунтов
- **МойСклад API endpoint**: `GET context/companysettings` (возвращает все настройки компании)
- Структура ответа МойСклад: `{meta, currency, priceTypes: [{id, name, externalCode}], ...}`
- Возвращает: `{main: [{id, name}], child: [{id, name}]}`

**GET** `/api/sync-settings/{accountId}/attributes`
- Получить все доп.поля из main аккаунта
- Возвращает: `{data: [{id, name, type}]}`

**GET** `/api/sync-settings/{accountId}/folders`
- Получить дерево групп товаров из main аккаунта
- Возвращает иерархическую структуру папок

**GET** `/api/sync-settings/{accountId}/batch` ⭐ **NEW - Batch Loading**
- Batch load initial data for settings page (optimization)
- Returns in single request:
  1. `settings` - Sync settings object
  2. `accountName` - Child account name
  3. `priceTypes` - { main: [...], child: [...] } with buyPrice prepended
  4. `attributes` - [{id, name, type}]
  5. `folders` - Hierarchical folder tree
- **Performance:** 4-5 API calls → 1 API call (3-4x faster page load)
- Graceful degradation: if one resource fails, others still load
- Returns: `{data: {settings, accountName, priceTypes, attributes, folders}}`

### Sync Actions

**POST** `/api/sync/{accountId}/products/all`
- Запустить синхронизацию всей номенклатуры
- Обрабатывает постранично (по 1000 товаров)
- Применяет фильтры из настроек
- Создаёт задачи в `sync_queue` с приоритетом 10
- Возвращает: `{tasks_created, status, message}`

**ВАЖНО:** Постраничная обработка критична для больших каталогов (10000+ товаров).
МойСклад API лимиты: max 1000 без expand, 100 с expand.

## Rate Limiting

МойСклад API limits: 45 requests/sec burst, sustained rate lower

`RateLimitHandler` tracks:
- Requests per second
- `X-Lognex-Reset` header for rate limit reset time
- Exponential backoff on 429 responses
- Automatic retry with delays

