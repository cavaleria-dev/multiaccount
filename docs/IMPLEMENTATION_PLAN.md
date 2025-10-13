# План реализации системы управления франшизами МойСклад

**Дата создания:** 13.10.2025
**Версия:** 1.0
**Статус:** Утвержден к реализации

---

## Оглавление

1. [Этап 1: Структура БД и модели](#этап-1-структура-бд-и-модели)
2. [Этап 2: Управление справочниками МойСклад](#этап-2-управление-справочниками-мойсклад)
3. [Этап 3: Синхронизация контрагентов](#этап-3-синхронизация-контрагентов)
4. [Этап 4: Синхронизация заказов покупателя](#этап-4-синхронизация-заказов-покупателя)
5. [Этап 5: Синхронизация розничных продаж](#этап-5-синхронизация-розничных-продаж)
6. [Этап 6: Обновленный WebhookController](#этап-6-обновленный-webhookcontroller)
7. [Этап 7: Обновленный WebhookService](#этап-7-обновленный-webhookservice)
8. [Этап 8: Frontend - Компоненты настроек заказов](#этап-8-frontend---компоненты-настроек-заказов)
9. [Этап 9: Тестирование](#этап-9-тестирование)
10. [Этап 10: Оптимизации для множества дочерних аккаунтов](#этап-10-оптимизации-для-множества-дочерних-аккаунтов)
11. [Порядок реализации](#порядок-реализации)

---

## Этап 1: Структура БД и модели

### 1.1 Миграции базы данных

#### Обновить таблицу `accounts`

```sql
ALTER TABLE accounts ADD COLUMN account_name VARCHAR(255);
ALTER TABLE accounts ADD COLUMN account_type ENUM('main', 'child');
ALTER TABLE accounts ADD COLUMN organization_id VARCHAR(255) NULL;
ALTER TABLE accounts ADD COLUMN counterparty_id VARCHAR(255) NULL;
ALTER TABLE accounts ADD COLUMN supplier_counterparty_id VARCHAR(255) NULL;

CREATE INDEX idx_accounts_account_name ON accounts(account_name);
CREATE INDEX idx_accounts_account_type ON accounts(account_type);
```

**Описание полей:**
- `account_name` - название аккаунта (например, "cavaleria")
- `account_type` - тип аккаунта: main (главный) или child (дочерний/франшиза)
- `organization_id` - ID главного юр.лица для main аккаунтов
- `counterparty_id` - ID контрагента франшизы в главном аккаунте (для child)
- `supplier_counterparty_id` - ID контрагента-поставщика (главное юр.лицо) в дочернем (для child)

---

#### Обновить таблицу `sync_settings`

```sql
-- Настройки товаров
ALTER TABLE sync_settings ADD COLUMN purchase_price_type_id VARCHAR(255) NULL;
ALTER TABLE sync_settings ADD COLUMN sale_price_type_id VARCHAR(255) NOT NULL;
ALTER TABLE sync_settings ADD COLUMN product_filter_type ENUM('attribute', 'folder', 'both');
ALTER TABLE sync_settings ADD COLUMN product_filter_attribute_id VARCHAR(255) NULL;
ALTER TABLE sync_settings ADD COLUMN product_filter_attribute_value TEXT NULL;
ALTER TABLE sync_settings ADD COLUMN product_filter_folder_ids JSON NULL;
ALTER TABLE sync_settings ADD COLUMN require_price_type_filled BOOLEAN DEFAULT true;
ALTER TABLE sync_settings ADD COLUMN sync_product_folders BOOLEAN DEFAULT true;
ALTER TABLE sync_settings ADD COLUMN product_folders_filter JSON NULL;
ALTER TABLE sync_settings ADD COLUMN product_match_field ENUM('article', 'code', 'externalCode', 'barcode') DEFAULT 'article';
ALTER TABLE sync_settings ADD COLUMN sync_products BOOLEAN DEFAULT true;
ALTER TABLE sync_settings ADD COLUMN sync_variants BOOLEAN DEFAULT true;
ALTER TABLE sync_settings ADD COLUMN sync_bundles BOOLEAN DEFAULT true;
ALTER TABLE sync_settings ADD COLUMN auto_create_attributes BOOLEAN DEFAULT true;
ALTER TABLE sync_settings ADD COLUMN auto_create_characteristics BOOLEAN DEFAULT true;
ALTER TABLE sync_settings ADD COLUMN auto_create_price_types BOOLEAN DEFAULT true;

-- Настройки заказов поставщику
ALTER TABLE sync_settings ADD COLUMN sync_purchase_orders BOOLEAN DEFAULT false;

-- Настройки заказов покупателя
ALTER TABLE sync_settings ADD COLUMN sync_customer_orders BOOLEAN DEFAULT false;
ALTER TABLE sync_settings ADD COLUMN customer_order_state_id VARCHAR(255) NULL;
ALTER TABLE sync_settings ADD COLUMN customer_order_success_state_id VARCHAR(255) NULL;
ALTER TABLE sync_settings ADD COLUMN customer_order_sales_channel_id VARCHAR(255) NULL;

-- Настройки розничных продаж
ALTER TABLE sync_settings ADD COLUMN sync_retail_demands BOOLEAN DEFAULT false;
ALTER TABLE sync_settings ADD COLUMN retail_demand_state_id VARCHAR(255) NULL;
ALTER TABLE sync_settings ADD COLUMN retail_demand_success_state_id VARCHAR(255) NULL;
ALTER TABLE sync_settings ADD COLUMN retail_demand_sales_channel_id VARCHAR(255) NULL;

-- Общие настройки для заказов/продаж
ALTER TABLE sync_settings ADD COLUMN sync_real_counterparties BOOLEAN DEFAULT false;
ALTER TABLE sync_settings ADD COLUMN stub_counterparty_id VARCHAR(255) NULL;
ALTER TABLE sync_settings ADD COLUMN target_organization_id VARCHAR(255) NULL;
ALTER TABLE sync_settings ADD COLUMN responsible_employee_id VARCHAR(255) NULL;
ALTER TABLE sync_settings ADD COLUMN franchise_counterparty_group VARCHAR(255) NULL;
```

**Описание настроек товаров:**
- `purchase_price_type_id` - ID типа закупочной цены (необязательно)
- `sale_price_type_id` - ID типа цены продажи (обязательно)
- `product_filter_type` - тип фильтра: по доп.полю, по группам товаров, или оба
- `product_filter_attribute_id` - ID доп.поля для фильтра
- `product_filter_attribute_value` - значение доп.поля
- `product_filter_folder_ids` - массив JSON с ID групп товаров
- `require_price_type_filled` - обязательно заполнен тип цены
- `sync_product_folders` - синхронизировать группы товаров
- `product_folders_filter` - фильтр групп товаров (null = автоматически)
- `product_match_field` - поле для сопоставления товаров
- `sync_products/variants/bundles` - что синхронизировать
- `auto_create_*` - автоматически создавать доп.поля/характеристики/типы цен если отсутствуют

**Описание настроек заказов:**
- `sync_purchase_orders` - синхронизировать заказы поставщику → заказы покупателя
- `sync_customer_orders` - синхронизировать заказы покупателя → заказы покупателя
- `customer_order_state_id` - ID входящего статуса для заказов
- `customer_order_success_state_id` - ID статуса успешной продажи
- `customer_order_sales_channel_id` - ID канала продаж для заказов
- `sync_retail_demands` - синхронизировать розничные продажи → заказы покупателя
- `retail_demand_state_id` - ID входящего статуса для розницы
- `retail_demand_success_state_id` - ID статуса успешной продажи розницы
- `retail_demand_sales_channel_id` - ID канала продаж для розницы
- `sync_real_counterparties` - синхронизировать реальных контрагентов или использовать заглушку
- `stub_counterparty_id` - ID контрагента-заглушки
- `target_organization_id` - ID юр.лица в главном аккаунте (на кого приходят заказы)
- `responsible_employee_id` - ID ответственного сотрудника (owner документов)
- `franchise_counterparty_group` - группа контрагентов "Франшизы"

---

#### Создать таблицу `sync_queue`

```sql
CREATE TABLE sync_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    account_id UUID NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(255) NOT NULL,
    operation VARCHAR(20) NOT NULL,
    priority INT DEFAULT 0,
    status VARCHAR(20) NOT NULL,
    payload JSON,
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    error TEXT NULL,
    rate_limit_info JSON NULL,
    scheduled_at TIMESTAMP NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_sync_queue_account_status (account_id, status),
    INDEX idx_sync_queue_scheduled (scheduled_at),
    INDEX idx_sync_queue_priority (priority DESC)
);
```

**Описание:**
- Очередь синхронизации для обработки задач с учетом rate limits МойСклад
- `entity_type` включает: product, variant, bundle, purchaseorder, customerorder, retaildemand
- `operation`: create, update, delete
- `status`: pending, processing, completed, failed
- `rate_limit_info` - JSON с информацией из headers (remaining, reset)
- `scheduled_at` - когда выполнять (для отложенных задач при rate limit)

---

#### Создать таблицу `webhook_health`

```sql
CREATE TABLE webhook_health (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    account_id UUID NOT NULL,
    webhook_id VARCHAR(255),
    entity_type VARCHAR(50) NOT NULL,
    action VARCHAR(20) NOT NULL,
    is_active BOOLEAN DEFAULT true,
    last_check_at TIMESTAMP,
    error_message TEXT NULL,
    check_attempts INT DEFAULT 0,
    last_success_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_webhook_health_account (account_id),
    INDEX idx_webhook_health_active (is_active),
    INDEX idx_webhook_health_check (last_check_at)
);
```

**Описание:**
- Мониторинг здоровья вебхуков на всех аккаунтах
- `entity_type`: product, variant, bundle, purchaseorder, customerorder, retaildemand
- `action`: CREATE, UPDATE, DELETE
- Проверяется каждые 15 минут автоматически (cron)

---

#### Создать таблицу `attribute_mappings`

```sql
CREATE TABLE attribute_mappings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    parent_account_id UUID NOT NULL,
    child_account_id UUID NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    parent_attribute_id VARCHAR(255) NOT NULL,
    child_attribute_id VARCHAR(255) NOT NULL,
    attribute_name VARCHAR(255) NOT NULL,
    attribute_type VARCHAR(50) NOT NULL,
    is_synced BOOLEAN DEFAULT true,
    auto_created BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_attribute_mapping (parent_account_id, child_account_id, entity_type, attribute_name),
    INDEX idx_attribute_mappings_accounts (parent_account_id, child_account_id, entity_type)
);
```

**Описание:**
- Сопоставление доп.полей между главным и дочерним аккаунтами
- `entity_type`: product, variant, bundle
- `attribute_type`: string, number, boolean, date, text, link, file и т.д.
- `auto_created` - было ли доп.поле создано автоматически

---

#### Создать таблицу `characteristic_mappings`

```sql
CREATE TABLE characteristic_mappings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    parent_account_id UUID NOT NULL,
    child_account_id UUID NOT NULL,
    parent_characteristic_id VARCHAR(255) NOT NULL,
    child_characteristic_id VARCHAR(255) NOT NULL,
    characteristic_name VARCHAR(255) NOT NULL,
    auto_created BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_characteristic_mappings_accounts (parent_account_id, child_account_id)
);
```

**Описание:**
- Сопоставление характеристик модификаций (variants)
- Проверка по названию характеристики, но сохранение UUID для быстрого поиска

---

#### Создать таблицу `price_type_mappings`

```sql
CREATE TABLE price_type_mappings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    parent_account_id UUID NOT NULL,
    child_account_id UUID NOT NULL,
    parent_price_type_id VARCHAR(255) NOT NULL,
    child_price_type_id VARCHAR(255) NOT NULL,
    price_type_name VARCHAR(255) NOT NULL,
    auto_created BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_price_type_mapping (parent_account_id, child_account_id, price_type_name)
);
```

**Описание:**
- Сопоставление типов цен между аккаунтами
- Автоматическое создание типов цен при синхронизации если отсутствуют

---

#### Создать таблицу `counterparty_mappings`

```sql
CREATE TABLE counterparty_mappings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    parent_account_id UUID NOT NULL,
    child_account_id UUID NOT NULL,
    parent_counterparty_id VARCHAR(255) NOT NULL,
    child_counterparty_id VARCHAR(255) NOT NULL,
    counterparty_name VARCHAR(255) NOT NULL,
    counterparty_inn VARCHAR(20) NULL,
    is_stub BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_counterparty_mapping (parent_account_id, child_account_id, child_counterparty_id),
    INDEX idx_counterparty_mappings_accounts (parent_account_id, child_account_id)
);
```

**Описание:**
- Сопоставление контрагентов между аккаунтами
- `is_stub` - является ли контрагент заглушкой
- Используется при синхронизации заказов для сопоставления покупателей

---

#### Обновить таблицу `entity_mappings`

```sql
ALTER TABLE entity_mappings ADD COLUMN match_field VARCHAR(50);
ALTER TABLE entity_mappings ADD COLUMN match_value VARCHAR(255);
ALTER TABLE entity_mappings ADD COLUMN sync_direction ENUM('main_to_child', 'child_to_main', 'both') DEFAULT 'main_to_child';
ALTER TABLE entity_mappings ADD COLUMN source_document_type VARCHAR(50) NULL;

CREATE INDEX idx_entity_mappings_direction ON entity_mappings(sync_direction);
```

**Описание дополнительных полей:**
- `match_field` - по какому полю было сопоставление (article/code/externalCode/barcode)
- `match_value` - значение поля сопоставления
- `sync_direction` - направление синхронизации
  - `main_to_child` - товары (главный → дочерний)
  - `child_to_main` - заказы (дочерний → главный)
  - `both` - двусторонняя (будущее)
- `source_document_type` - тип исходного документа (purchaseorder/customerorder/retaildemand)
  - Используется для различения откуда пришел заказ в главном аккаунте

---

### 1.2 Eloquent модели

#### Account (обновить)
```php
class Account extends Model {
    protected $fillable = [
        'app_id', 'account_id', 'account_name', 'account_type',
        'access_token', 'status', 'organization_id',
        'counterparty_id', 'supplier_counterparty_id',
        'installed_at', 'suspended_at', 'uninstalled_at'
    ];

    public function syncSettings() {
        return $this->hasOne(SyncSetting::class, 'account_id', 'account_id');
    }

    public function childAccounts() {
        return $this->hasMany(ChildAccount::class, 'parent_account_id', 'account_id');
    }

    public function syncQueue() {
        return $this->hasMany(SyncQueue::class, 'account_id', 'account_id');
    }
}
```

#### SyncSetting (обновить)
```php
class SyncSetting extends Model {
    protected $casts = [
        'product_filter_folder_ids' => 'array',
        'product_folders_filter' => 'array',
        'sync_products' => 'boolean',
        'sync_variants' => 'boolean',
        'sync_bundles' => 'boolean',
        'auto_create_attributes' => 'boolean',
        'auto_create_characteristics' => 'boolean',
        'auto_create_price_types' => 'boolean',
        'sync_purchase_orders' => 'boolean',
        'sync_customer_orders' => 'boolean',
        'sync_retail_demands' => 'boolean',
        'sync_real_counterparties' => 'boolean',
    ];
}
```

#### SyncQueue (новая)
```php
class SyncQueue extends Model {
    protected $table = 'sync_queue';

    protected $fillable = [
        'account_id', 'entity_type', 'entity_id', 'operation',
        'priority', 'status', 'payload', 'attempts', 'max_attempts',
        'error', 'rate_limit_info', 'scheduled_at'
    ];

    protected $casts = [
        'payload' => 'array',
        'rate_limit_info' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function account() {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }
}
```

#### AttributeMapping, CharacteristicMapping, PriceTypeMapping, CounterpartyMapping (новые)
```php
class AttributeMapping extends Model {
    protected $fillable = [
        'parent_account_id', 'child_account_id', 'entity_type',
        'parent_attribute_id', 'child_attribute_id',
        'attribute_name', 'attribute_type', 'is_synced', 'auto_created'
    ];

    protected $casts = [
        'is_synced' => 'boolean',
        'auto_created' => 'boolean',
    ];
}

// Аналогично для остальных
```

---

## Этап 2: Управление справочниками МойСклад

### 2.1 SalesChannelService

**Путь:** `app/Services/SalesChannelService.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SalesChannelService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Получить список каналов продаж
     */
    public function getSalesChannels(string $accountId): array
    {
        try {
            $account = \App\Models\Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/saleschannel');

            return $result['data']['rows'] ?? [];

        } catch (\Exception $e) {
            Log::error('Failed to get sales channels', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Создать канал продаж
     */
    public function createSalesChannel(string $accountId, string $name, ?string $description = null): array
    {
        try {
            $account = \App\Models\Account::where('account_id', $accountId)->firstOrFail();

            $data = [
                'name' => $name,
            ];

            if ($description) {
                $data['description'] = $description;
            }

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->post('entity/saleschannel', $data);

            Log::info('Sales channel created', [
                'account_id' => $accountId,
                'channel_id' => $result['data']['id'] ?? null,
                'name' => $name
            ]);

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to create sales channel', [
                'account_id' => $accountId,
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Найти канал по названию
     */
    public function findByName(string $accountId, string $name): ?array
    {
        $channels = $this->getSalesChannels($accountId);

        foreach ($channels as $channel) {
            if ($channel['name'] === $name) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * Получить или создать канал (helper)
     */
    public function getOrCreate(string $accountId, string $name, ?string $description = null): array
    {
        $existing = $this->findByName($accountId, $name);

        if ($existing) {
            return $existing;
        }

        return $this->createSalesChannel($accountId, $name, $description);
    }
}
```

---

### 2.2 StateService

**Путь:** `app/Services/StateService.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class StateService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Получить метаданные документа (включая states)
     */
    public function getMetadata(string $accountId, string $entityType = 'customerorder'): array
    {
        try {
            $account = \App\Models\Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get("entity/{$entityType}/metadata");

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to get metadata', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить список статусов для типа документа
     */
    public function getStates(string $accountId, string $entityType = 'customerorder'): array
    {
        $metadata = $this->getMetadata($accountId, $entityType);
        return $metadata['states'] ?? [];
    }

    /**
     * Создать статус
     */
    public function createState(string $accountId, string $entityType, string $name, ?string $color = null): array
    {
        try {
            $account = \App\Models\Account::where('account_id', $accountId)->firstOrFail();

            $data = [
                'name' => $name,
                'stateType' => 'Regular',
            ];

            if ($color) {
                $data['color'] = $color;
            }

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->post("entity/{$entityType}/metadata/states", $data);

            Log::info('State created', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'state_id' => $result['data']['id'] ?? null,
                'name' => $name
            ]);

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to create state', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Найти статус по названию
     */
    public function findStateByName(string $accountId, string $entityType, string $name): ?array
    {
        $states = $this->getStates($accountId, $entityType);

        foreach ($states as $state) {
            if ($state['name'] === $name) {
                return $state;
            }
        }

        return null;
    }

    /**
     * Получить или создать статус (helper)
     */
    public function getOrCreateState(string $accountId, string $entityType, string $name, ?string $color = null): array
    {
        $existing = $this->findStateByName($accountId, $entityType, $name);

        if ($existing) {
            return $existing;
        }

        return $this->createState($accountId, $entityType, $name, $color);
    }
}
```

---

### 2.3 EmployeeService

**Путь:** `app/Services/EmployeeService.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class EmployeeService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Получить список сотрудников
     */
    public function getEmployees(string $accountId): array
    {
        try {
            $account = \App\Models\Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/employee');

            return $result['data']['rows'] ?? [];

        } catch (\Exception $e) {
            Log::error('Failed to get employees', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить сотрудника по ID
     */
    public function getEmployee(string $accountId, string $employeeId): array
    {
        try {
            $account = \App\Models\Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get("entity/employee/{$employeeId}");

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to get employee', [
                'account_id' => $accountId,
                'employee_id' => $employeeId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Поиск сотрудника по имени/email
     */
    public function searchEmployee(string $accountId, string $query): array
    {
        try {
            $account = \App\Models\Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/employee', ['search' => $query]);

            return $result['data']['rows'] ?? [];

        } catch (\Exception $e) {
            Log::error('Failed to search employees', [
                'account_id' => $accountId,
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
```

---

### 2.4 API endpoints для справочников

**Путь:** `routes/api.php`

```php
// Каналы продаж
Route::get('accounts/{accountId}/sales-channels', [SalesChannelController::class, 'index']);
Route::post('accounts/{accountId}/sales-channels', [SalesChannelController::class, 'store']);

// Статусы документов
Route::get('accounts/{accountId}/states/{entityType}', [StateController::class, 'index']);
Route::post('accounts/{accountId}/states/{entityType}', [StateController::class, 'store']);

// Сотрудники
Route::get('accounts/{accountId}/employees', [EmployeeController::class, 'index']);
Route::get('accounts/{accountId}/employees/{employeeId}', [EmployeeController::class, 'show']);
Route::get('accounts/{accountId}/employees/search', [EmployeeController::class, 'search']);
```

**Контроллеры:**

```php
// app/Http/Controllers/Api/SalesChannelController.php
class SalesChannelController extends Controller
{
    public function index(string $accountId, SalesChannelService $service)
    {
        return response()->json($service->getSalesChannels($accountId));
    }

    public function store(Request $request, string $accountId, SalesChannelService $service)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $channel = $service->createSalesChannel(
            $accountId,
            $validated['name'],
            $validated['description'] ?? null
        );

        return response()->json($channel, 201);
    }
}

// Аналогично для StateController и EmployeeController
```

---

## Этап 3: Синхронизация контрагентов

### 3.1 CounterpartySyncService (расширенный)

**Путь:** `app/Services/CounterpartySyncService.php`

```php
<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CounterpartyMapping;
use Illuminate\Support\Facades\Log;

class CounterpartySyncService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Создать контрагента франшизы в главном при добавлении child account
     */
    public function createFranchiseCounterparty(string $mainAccountId, array $franchiseData): array
    {
        try {
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();

            // 1. Создать/получить группу "Франшизы"
            $groupId = $this->getOrCreateCounterpartyGroup($mainAccountId, 'Франшизы');

            // 2. Создать контрагента с названием франшизы
            $counterpartyData = [
                'name' => $franchiseData['name'],
                'companyType' => 'legal',
                'group' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/group/{$groupId}",
                        'type' => 'group',
                        'mediaType' => 'application/json'
                    ]
                ]
            ];

            // Добавить ИНН если есть
            if (isset($franchiseData['inn'])) {
                $counterpartyData['inn'] = $franchiseData['inn'];
            }

            $result = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->post('entity/counterparty', $counterpartyData);

            Log::info('Franchise counterparty created', [
                'main_account_id' => $mainAccountId,
                'counterparty_id' => $result['data']['id'] ?? null,
                'name' => $franchiseData['name']
            ]);

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to create franchise counterparty', [
                'main_account_id' => $mainAccountId,
                'franchise_data' => $franchiseData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Создать контрагента-поставщика в дочернем (главное юр.лицо)
     */
    public function createSupplierCounterparty(string $childAccountId, array $organizationData): array
    {
        try {
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            $counterpartyData = [
                'name' => $organizationData['name'],
                'companyType' => 'legal',
            ];

            // Копируем данные из organization
            if (isset($organizationData['inn'])) {
                $counterpartyData['inn'] = $organizationData['inn'];
            }
            if (isset($organizationData['kpp'])) {
                $counterpartyData['kpp'] = $organizationData['kpp'];
            }
            if (isset($organizationData['legalAddress'])) {
                $counterpartyData['legalAddress'] = $organizationData['legalAddress'];
            }
            if (isset($organizationData['actualAddress'])) {
                $counterpartyData['actualAddress'] = $organizationData['actualAddress'];
            }

            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->post('entity/counterparty', $counterpartyData);

            Log::info('Supplier counterparty created', [
                'child_account_id' => $childAccountId,
                'counterparty_id' => $result['data']['id'] ?? null,
                'name' => $organizationData['name']
            ]);

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to create supplier counterparty', [
                'child_account_id' => $childAccountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Синхронизировать контрагента из дочернего в главный (для заказов)
     */
    public function syncCounterparty(string $parentAccountId, string $childAccountId, string $childCounterpartyId): array
    {
        try {
            // Проверить настройку sync_real_counterparties
            $settings = \App\Models\SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || !$settings->sync_real_counterparties) {
                // Использовать заглушку
                if (!$settings->stub_counterparty_id) {
                    throw new \Exception('Stub counterparty not configured');
                }

                return [
                    'id' => $settings->stub_counterparty_id,
                    'is_stub' => true
                ];
            }

            // Проверить существование mapping
            $mapping = CounterpartyMapping::where('parent_account_id', $parentAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('child_counterparty_id', $childCounterpartyId)
                ->first();

            if ($mapping) {
                return [
                    'id' => $mapping->parent_counterparty_id,
                    'is_stub' => false
                ];
            }

            // Получить контрагента из дочернего аккаунта
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
            $childCounterpartyResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->get("entity/counterparty/{$childCounterpartyId}");

            $childCounterparty = $childCounterpartyResult['data'];

            // Создать в главном аккаунте
            $parentAccount = Account::where('account_id', $parentAccountId)->firstOrFail();

            $counterpartyData = [
                'name' => $childCounterparty['name'],
                'companyType' => $childCounterparty['companyType'] ?? 'legal',
            ];

            // Копировать дополнительные данные
            $fieldsToSync = ['inn', 'kpp', 'phone', 'email', 'legalAddress', 'actualAddress'];
            foreach ($fieldsToSync as $field) {
                if (isset($childCounterparty[$field])) {
                    $counterpartyData[$field] = $childCounterparty[$field];
                }
            }

            $result = $this->moySkladService
                ->setAccessToken($parentAccount->access_token)
                ->post('entity/counterparty', $counterpartyData);

            $parentCounterparty = $result['data'];

            // Сохранить mapping
            CounterpartyMapping::create([
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'parent_counterparty_id' => $parentCounterparty['id'],
                'child_counterparty_id' => $childCounterpartyId,
                'counterparty_name' => $childCounterparty['name'],
                'counterparty_inn' => $childCounterparty['inn'] ?? null,
                'is_stub' => false,
            ]);

            Log::info('Counterparty synced', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'parent_counterparty_id' => $parentCounterparty['id'],
                'child_counterparty_id' => $childCounterpartyId
            ]);

            return [
                'id' => $parentCounterparty['id'],
                'is_stub' => false
            ];

        } catch (\Exception $e) {
            Log::error('Failed to sync counterparty', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'child_counterparty_id' => $childCounterpartyId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Создать контрагента-заглушку
     */
    public function createStubCounterparty(string $accountId, string $name = "Заглушка"): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $counterpartyData = [
                'name' => $name,
                'companyType' => 'legal',
            ];

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->post('entity/counterparty', $counterpartyData);

            Log::info('Stub counterparty created', [
                'account_id' => $accountId,
                'counterparty_id' => $result['data']['id'] ?? null
            ]);

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to create stub counterparty', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить или создать группу контрагентов
     */
    public function getOrCreateCounterpartyGroup(string $accountId, string $groupName): string
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            // Попробовать найти группу
            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/group', ['filter' => "name={$groupName}"]);

            if (!empty($result['data']['rows'])) {
                return $result['data']['rows'][0]['id'];
            }

            // Создать группу
            $createResult = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->post('entity/group', ['name' => $groupName]);

            return $createResult['data']['id'];

        } catch (\Exception $e) {
            Log::error('Failed to get/create counterparty group', [
                'account_id' => $accountId,
                'group_name' => $groupName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
```

---

### 3.2 API endpoints

```php
// routes/api.php

Route::post('accounts/{accountId}/sync-counterparty', [CounterpartyController::class, 'sync']);
Route::post('accounts/{accountId}/create-stub-counterparty', [CounterpartyController::class, 'createStub']);
Route::get('counterparty-mappings/{accountId}', [CounterpartyController::class, 'mappings']);
```

---

## Этап 4: Синхронизация заказов покупателя

### 4.1 CustomerOrderSyncService

**Путь:** `app/Services/CustomerOrderSyncService.php`

```php
<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use Illuminate\Support\Facades\Log;

class CustomerOrderSyncService
{
    protected MoySkladService $moySkladService;
    protected CounterpartySyncService $counterpartySyncService;

    public function __construct(
        MoySkladService $moySkladService,
        CounterpartySyncService $counterpartySyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->counterpartySyncService = $counterpartySyncService;
    }

    /**
     * Синхронизировать заказ покупателя из дочернего в главный
     */
    public function syncCustomerOrder(string $childAccountId, string $customerOrderId): void
    {
        try {
            // 1. Получить заказ из дочернего аккаунта
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            $orderResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->get("entity/customerorder/{$customerOrderId}");

            $order = $orderResult['data'];

            // 2. Проверить applicable = true (проведен)
            if (!($order['applicable'] ?? false)) {
                Log::info('Customer order not applicable, skipping', [
                    'child_account_id' => $childAccountId,
                    'order_id' => $customerOrderId
                ]);
                return;
            }

            // 3. Получить настройки синхронизации
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || !$settings->sync_customer_orders) {
                Log::info('Customer order sync disabled', [
                    'child_account_id' => $childAccountId
                ]);
                return;
            }

            // 4. Получить parent_account_id
            $childAccountModel = Account::where('account_id', $childAccountId)->first();
            $parentAccountId = \DB::table('child_accounts')
                ->where('child_account_id', $childAccountId)
                ->value('parent_account_id');

            if (!$parentAccountId) {
                throw new \Exception('Parent account not found');
            }

            // 5. Проверить rate limit (TODO: добавить логику)

            // 6. Синхронизировать контрагента
            $agentId = $this->extractEntityId($order['agent']['meta']['href']);
            $syncedCounterparty = $this->counterpartySyncService->syncCounterparty(
                $parentAccountId,
                $childAccountId,
                $agentId
            );

            // 7. Создать заказ покупателя в главном
            $parentAccount = Account::where('account_id', $parentAccountId)->firstOrFail();

            $newOrderData = [
                'organization' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/organization/{$settings->target_organization_id}",
                        'type' => 'organization',
                        'mediaType' => 'application/json'
                    ]
                ],
                'agent' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/counterparty/{$syncedCounterparty['id']}",
                        'type' => 'counterparty',
                        'mediaType' => 'application/json'
                    ]
                ],
                'state' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/customerorder/metadata/states/{$settings->customer_order_state_id}",
                        'type' => 'state',
                        'mediaType' => 'application/json'
                    ]
                ],
                'owner' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/employee/{$settings->responsible_employee_id}",
                        'type' => 'employee',
                        'mediaType' => 'application/json'
                    ]
                ],
                'salesChannel' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/saleschannel/{$settings->customer_order_sales_channel_id}",
                        'type' => 'saleschannel',
                        'mediaType' => 'application/json'
                    ]
                ],
                'moment' => $order['moment'],
                'applicable' => true,
            ];

            // 8. Сопоставить позиции заказа
            $positions = $this->mapOrderPositions($parentAccountId, $childAccountId, $order['positions']['rows'] ?? []);
            $newOrderData['positions'] = $positions;

            $result = $this->moySkladService
                ->setAccessToken($parentAccount->access_token)
                ->post('entity/customerorder', $newOrderData);

            $newOrder = $result['data'];

            // 9. Сохранить mapping
            EntityMapping::create([
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'customerorder',
                'parent_entity_id' => $newOrder['id'],
                'child_entity_id' => $customerOrderId,
                'sync_direction' => 'child_to_main',
                'source_document_type' => 'customerorder',
            ]);

            Log::info('Customer order synced', [
                'child_account_id' => $childAccountId,
                'child_order_id' => $customerOrderId,
                'parent_order_id' => $newOrder['id']
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to sync customer order', [
                'child_account_id' => $childAccountId,
                'order_id' => $customerOrderId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Обновить заказ покупателя при изменении в дочернем
     */
    public function updateCustomerOrder(string $childAccountId, string $customerOrderId): void
    {
        try {
            // 1. Найти mapping
            $mapping = EntityMapping::where('child_account_id', $childAccountId)
                ->where('child_entity_id', $customerOrderId)
                ->where('entity_type', 'customerorder')
                ->where('sync_direction', 'child_to_main')
                ->first();

            if (!$mapping) {
                Log::info('Customer order mapping not found, creating new', [
                    'child_account_id' => $childAccountId,
                    'order_id' => $customerOrderId
                ]);
                $this->syncCustomerOrder($childAccountId, $customerOrderId);
                return;
            }

            // 2. Получить заказ из дочернего
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
            $orderResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->get("entity/customerorder/{$customerOrderId}");

            $order = $orderResult['data'];

            // 3. Проверить изменился ли state
            $settings = SyncSetting::where('account_id', $childAccountId)->first();
            $stateId = $this->extractEntityId($order['state']['meta']['href']);

            // 4. Обновить заказ в главном
            $parentAccount = Account::where('account_id', $mapping->parent_account_id)->firstOrFail();

            $updateData = [
                'moment' => $order['moment'],
                'sum' => $order['sum'],
            ];

            // Если state стал статусом успешной продажи
            if ($stateId === $settings->customer_order_success_state_id) {
                $updateData['state'] = [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/customerorder/metadata/states/{$settings->customer_order_success_state_id}",
                        'type' => 'state',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Обновить позиции
            $positions = $this->mapOrderPositions($mapping->parent_account_id, $childAccountId, $order['positions']['rows'] ?? []);
            $updateData['positions'] = $positions;

            $this->moySkladService
                ->setAccessToken($parentAccount->access_token)
                ->put("entity/customerorder/{$mapping->parent_entity_id}", $updateData);

            Log::info('Customer order updated', [
                'child_account_id' => $childAccountId,
                'child_order_id' => $customerOrderId,
                'parent_order_id' => $mapping->parent_entity_id
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update customer order', [
                'child_account_id' => $childAccountId,
                'order_id' => $customerOrderId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Удалить заказ покупателя
     */
    public function deleteCustomerOrder(string $childAccountId, string $customerOrderId): void
    {
        try {
            $mapping = EntityMapping::where('child_account_id', $childAccountId)
                ->where('child_entity_id', $customerOrderId)
                ->where('entity_type', 'customerorder')
                ->first();

            if (!$mapping) {
                Log::info('Customer order mapping not found, nothing to delete', [
                    'child_account_id' => $childAccountId,
                    'order_id' => $customerOrderId
                ]);
                return;
            }

            $parentAccount = Account::where('account_id', $mapping->parent_account_id)->firstOrFail();

            $this->moySkladService
                ->setAccessToken($parentAccount->access_token)
                ->delete("entity/customerorder/{$mapping->parent_entity_id}");

            $mapping->delete();

            Log::info('Customer order deleted', [
                'child_account_id' => $childAccountId,
                'child_order_id' => $customerOrderId,
                'parent_order_id' => $mapping->parent_entity_id
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete customer order', [
                'child_account_id' => $childAccountId,
                'order_id' => $customerOrderId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Сопоставить позиции заказа (child → main)
     */
    protected function mapOrderPositions(string $parentAccountId, string $childAccountId, array $positions): array
    {
        $mappedPositions = [];

        foreach ($positions as $position) {
            $childProductId = $this->extractEntityId($position['assortment']['meta']['href']);

            // Найти сопоставление товара
            $productMapping = EntityMapping::where('parent_account_id', $parentAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('child_entity_id', $childProductId)
                ->whereIn('entity_type', ['product', 'variant'])
                ->first();

            if (!$productMapping) {
                Log::warning('Product mapping not found for order position', [
                    'child_product_id' => $childProductId
                ]);
                continue;
            }

            $mappedPositions[] = [
                'assortment' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/{$productMapping->entity_type}/{$productMapping->parent_entity_id}",
                        'type' => $productMapping->entity_type,
                        'mediaType' => 'application/json'
                    ]
                ],
                'quantity' => $position['quantity'],
                'price' => $position['price'],
            ];
        }

        return $mappedPositions;
    }

    /**
     * Извлечь ID из href
     */
    protected function extractEntityId(string $href): string
    {
        $parts = explode('/', $href);
        return end($parts);
    }
}
```

---

## Этап 5: Синхронизация розничных продаж

### 5.1 RetailDemandSyncService

**Путь:** `app/Services/RetailDemandSyncService.php`

```php
<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use Illuminate\Support\Facades\Log;

class RetailDemandSyncService
{
    protected MoySkladService $moySkladService;
    protected CounterpartySyncService $counterpartySyncService;

    public function __construct(
        MoySkladService $moySkladService,
        CounterpartySyncService $counterpartySyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->counterpartySyncService = $counterpartySyncService;
    }

    /**
     * Синхронизировать розничную продажу из дочернего в заказ покупателя в главном
     */
    public function syncRetailDemand(string $childAccountId, string $retailDemandId): void
    {
        try {
            // 1. Получить retaildemand из дочернего
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            $demandResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->get("entity/retaildemand/{$retailDemandId}");

            $demand = $demandResult['data'];

            // 2. Проверить applicable = true (проведен)
            if (!($demand['applicable'] ?? false)) {
                Log::info('Retail demand not applicable, skipping', [
                    'child_account_id' => $childAccountId,
                    'demand_id' => $retailDemandId
                ]);
                return;
            }

            // 3. Получить настройки
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || !$settings->sync_retail_demands) {
                Log::info('Retail demand sync disabled', [
                    'child_account_id' => $childAccountId
                ]);
                return;
            }

            // 4. Получить parent_account_id
            $parentAccountId = \DB::table('child_accounts')
                ->where('child_account_id', $childAccountId)
                ->value('parent_account_id');

            if (!$parentAccountId) {
                throw new \Exception('Parent account not found');
            }

            // 5. Проверить rate limit (TODO)

            // 6. Синхронизировать контрагента (если есть)
            $syncedCounterpartyId = null;
            if (isset($demand['agent'])) {
                $agentId = $this->extractEntityId($demand['agent']['meta']['href']);
                $syncedCounterparty = $this->counterpartySyncService->syncCounterparty(
                    $parentAccountId,
                    $childAccountId,
                    $agentId
                );
                $syncedCounterpartyId = $syncedCounterparty['id'];
            } else {
                // Использовать заглушку или контрагента франшизы
                $childAccountModel = Account::where('account_id', $childAccountId)->first();
                $syncedCounterpartyId = $settings->sync_real_counterparties
                    ? $childAccountModel->counterparty_id
                    : $settings->stub_counterparty_id;
            }

            // 7. Создать ЗАКАЗ ПОКУПАТЕЛЯ (customerorder) в главном
            $parentAccount = Account::where('account_id', $parentAccountId)->firstOrFail();

            $orderData = [
                'organization' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/organization/{$settings->target_organization_id}",
                        'type' => 'organization',
                        'mediaType' => 'application/json'
                    ]
                ],
                'agent' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/counterparty/{$syncedCounterpartyId}",
                        'type' => 'counterparty',
                        'mediaType' => 'application/json'
                    ]
                ],
                'state' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/customerorder/metadata/states/{$settings->retail_demand_state_id}",
                        'type' => 'state',
                        'mediaType' => 'application/json'
                    ]
                ],
                'owner' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/employee/{$settings->responsible_employee_id}",
                        'type' => 'employee',
                        'mediaType' => 'application/json'
                    ]
                ],
                'salesChannel' => [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/saleschannel/{$settings->retail_demand_sales_channel_id}",
                        'type' => 'saleschannel',
                        'mediaType' => 'application/json'
                    ]
                ],
                'moment' => $demand['moment'],
                'applicable' => true,
            ];

            // 8. Сопоставить позиции
            $positions = $this->mapOrderPositions($parentAccountId, $childAccountId, $demand['positions']['rows'] ?? []);
            $orderData['positions'] = $positions;

            $result = $this->moySkladService
                ->setAccessToken($parentAccount->access_token)
                ->post('entity/customerorder', $orderData);

            $newOrder = $result['data'];

            // 9. Сохранить mapping
            EntityMapping::create([
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'customerorder',
                'parent_entity_id' => $newOrder['id'],
                'child_entity_id' => $retailDemandId,
                'sync_direction' => 'child_to_main',
                'source_document_type' => 'retaildemand',
            ]);

            Log::info('Retail demand synced as customer order', [
                'child_account_id' => $childAccountId,
                'retail_demand_id' => $retailDemandId,
                'parent_order_id' => $newOrder['id']
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to sync retail demand', [
                'child_account_id' => $childAccountId,
                'demand_id' => $retailDemandId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Обновить при изменении retaildemand
     */
    public function updateRetailDemand(string $childAccountId, string $retailDemandId): void
    {
        // Аналогично CustomerOrderSyncService::updateCustomerOrder()
        // Но ищем по source_document_type = 'retaildemand'
        // ... (код аналогичен)
    }

    /**
     * Удалить
     */
    public function deleteRetailDemand(string $childAccountId, string $retailDemandId): void
    {
        // Аналогично CustomerOrderSyncService::deleteCustomerOrder()
        // ... (код аналогичен)
    }

    /**
     * Сопоставить позиции (аналогично CustomerOrderSyncService)
     */
    protected function mapOrderPositions(string $parentAccountId, string $childAccountId, array $positions): array
    {
        // Код идентичен CustomerOrderSyncService::mapOrderPositions()
    }

    protected function extractEntityId(string $href): string
    {
        $parts = explode('/', $href);
        return end($parts);
    }
}
```

---

## Этап 6: Обновленный WebhookController

**Путь:** `app/Http/Controllers/Api/WebhookController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\OrderSyncService;
use App\Services\CustomerOrderSyncService;
use App\Services\RetailDemandSyncService;
use App\Services\ProductSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected OrderSyncService $orderSyncService;
    protected CustomerOrderSyncService $customerOrderSyncService;
    protected RetailDemandSyncService $retailDemandSyncService;
    protected ProductSyncService $productSyncService;

    public function __construct(
        OrderSyncService $orderSyncService,
        CustomerOrderSyncService $customerOrderSyncService,
        RetailDemandSyncService $retailDemandSyncService,
        ProductSyncService $productSyncService
    ) {
        $this->orderSyncService = $orderSyncService;
        $this->customerOrderSyncService = $customerOrderSyncService;
        $this->retailDemandSyncService = $retailDemandSyncService;
        $this->productSyncService = $productSyncService;
    }

    /**
     * Обработка вебхуков МойСклад
     */
    public function handle(Request $request)
    {
        try {
            $action = $request->input('action');        // CREATE/UPDATE/DELETE
            $entityType = $request->input('entityType'); // product/variant/bundle/purchaseorder/customerorder/retaildemand

            Log::info('Webhook received', [
                'action' => $action,
                'entity_type' => $entityType,
                'payload' => $request->all()
            ]);

            // Определить account_id из запроса
            $accountId = $this->getAccountIdFromRequest($request);

            if (!$accountId) {
                Log::error('Failed to determine account_id from webhook');
                return response()->json(['error' => 'Account not found'], 404);
            }

            // Получить аккаунт
            $account = Account::where('account_id', $accountId)->first();

            if (!$account) {
                Log::error('Account not found', ['account_id' => $accountId]);
                return response()->json(['error' => 'Account not found'], 404);
            }

            // Извлечь entity_id
            $entityId = $this->extractEntityId($request->input('meta'));

            // Обработать в зависимости от типа сущности
            match($entityType) {
                'product', 'variant', 'bundle' => $this->handleProductSync($account, $entityType, $action, $entityId),
                'purchaseorder' => $this->handlePurchaseOrder($account, $action, $entityId),
                'customerorder' => $this->handleCustomerOrder($account, $action, $entityId),
                'retaildemand' => $this->handleRetailDemand($account, $action, $entityId),
                default => Log::warning("Unknown entity type: {$entityType}")
            };

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Обработка заказа поставщику
     */
    protected function handlePurchaseOrder(Account $account, string $action, string $entityId)
    {
        // Только для child аккаунтов
        if ($account->account_type !== 'child') {
            Log::info('Purchase order webhook for main account, skipping', [
                'account_id' => $account->account_id
            ]);
            return;
        }

        match($action) {
            'CREATE' => $this->orderSyncService->syncPurchaseOrder($account->account_id, $entityId),
            'UPDATE' => $this->orderSyncService->updateCustomerOrder($account->account_id, $entityId),
            'DELETE' => $this->orderSyncService->deleteCustomerOrder($account->account_id, $entityId),
            default => Log::warning("Unknown action for purchase order: {$action}")
        };
    }

    /**
     * Обработка заказа покупателя
     */
    protected function handleCustomerOrder(Account $account, string $action, string $entityId)
    {
        // Только для child аккаунтов
        if ($account->account_type !== 'child') {
            Log::info('Customer order webhook for main account, skipping', [
                'account_id' => $account->account_id
            ]);
            return;
        }

        match($action) {
            'CREATE' => $this->customerOrderSyncService->syncCustomerOrder($account->account_id, $entityId),
            'UPDATE' => $this->customerOrderSyncService->updateCustomerOrder($account->account_id, $entityId),
            'DELETE' => $this->customerOrderSyncService->deleteCustomerOrder($account->account_id, $entityId),
            default => Log::warning("Unknown action for customer order: {$action}")
        };
    }

    /**
     * Обработка розничной продажи
     */
    protected function handleRetailDemand(Account $account, string $action, string $entityId)
    {
        // Только для child аккаунтов
        if ($account->account_type !== 'child') {
            Log::info('Retail demand webhook for main account, skipping', [
                'account_id' => $account->account_id
            ]);
            return;
        }

        match($action) {
            'CREATE' => $this->retailDemandSyncService->syncRetailDemand($account->account_id, $entityId),
            'UPDATE' => $this->retailDemandSyncService->updateRetailDemand($account->account_id, $entityId),
            'DELETE' => $this->retailDemandSyncService->deleteRetailDemand($account->account_id, $entityId),
            default => Log::warning("Unknown action for retail demand: {$action}")
        };
    }

    /**
     * Обработка синхронизации товаров
     */
    protected function handleProductSync(Account $account, string $entityType, string $action, string $entityId)
    {
        // Для main аккаунтов: товары → дочерним
        if ($account->account_type === 'main') {
            // Найти все дочерние аккаунты
            $childAccounts = \DB::table('child_accounts')
                ->where('parent_account_id', $account->account_id)
                ->pluck('child_account_id');

            foreach ($childAccounts as $childAccountId) {
                match($action) {
                    'CREATE', 'UPDATE' => $this->productSyncService->syncProduct(
                        $account->account_id,
                        $childAccountId,
                        $entityId
                    ),
                    'DELETE' => $this->productSyncService->deleteProduct(
                        $account->account_id,
                        $childAccountId,
                        $entityId
                    ),
                };
            }
        }
    }

    /**
     * Определить account_id из запроса
     */
    protected function getAccountIdFromRequest(Request $request): ?string
    {
        // МойСклад передает accountId в meta или в отдельном поле
        $accountId = $request->input('accountId');

        if (!$accountId && $request->has('meta')) {
            $meta = $request->input('meta');
            if (isset($meta['href'])) {
                // Извлечь из href типа: https://api.moysklad.ru/api/remap/1.2/entity/...
                // Обычно accountId можно получить из auditContext или другого места
                // Для упрощения можно искать по другим параметрам
            }
        }

        return $accountId;
    }

    /**
     * Извлечь entity_id из meta
     */
    protected function extractEntityId(array $meta): string
    {
        if (isset($meta['href'])) {
            $parts = explode('/', $meta['href']);
            return end($parts);
        }

        return $meta['id'] ?? '';
    }
}
```

---

## Этап 7: Обновленный WebhookService

**Путь:** `app/Services/WebhookService.php`

```php
<?php

namespace App\Services;

use App\Models\Account;
use App\Models\WebhookHealth;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Установить все необходимые вебхуки для аккаунта
     */
    public function setupWebhooksForAccount(string $accountId): array
    {
        $account = Account::where('account_id', $accountId)->firstOrFail();
        $webhooks = [];

        // Для main аккаунтов: только товары
        if ($account->account_type === 'main') {
            $webhooks = array_merge($webhooks,
                $this->createWebhooksForEntity($accountId, 'product'),
                $this->createWebhooksForEntity($accountId, 'variant'),
                $this->createWebhooksForEntity($accountId, 'bundle')
            );
        }

        // Для child аккаунтов: товары + заказы
        if ($account->account_type === 'child') {
            $webhooks = array_merge($webhooks,
                $this->createWebhooksForEntity($accountId, 'product'),
                $this->createWebhooksForEntity($accountId, 'variant'),
                $this->createWebhooksForEntity($accountId, 'bundle'),
                $this->createWebhooksForEntity($accountId, 'purchaseorder'),
                $this->createWebhooksForEntity($accountId, 'customerorder'),
                $this->createWebhooksForEntity($accountId, 'retaildemand')
            );
        }

        Log::info('Webhooks setup completed', [
            'account_id' => $accountId,
            'webhooks_count' => count($webhooks)
        ]);

        return $webhooks;
    }

    /**
     * Создать вебхуки для сущности
     */
    protected function createWebhooksForEntity(string $accountId, string $entityType): array
    {
        $account = Account::where('account_id', $accountId)->firstOrFail();
        $webhookUrl = config('moysklad.webhook_url');
        $actions = ['CREATE', 'UPDATE', 'DELETE'];
        $webhooks = [];

        foreach ($actions as $action) {
            try {
                $webhook = $this->moySkladService
                    ->setAccessToken($account->access_token)
                    ->createWebhook($webhookUrl, $action, $entityType);

                $webhooks[] = $webhook;

                // Сохранить в webhook_health
                WebhookHealth::create([
                    'account_id' => $accountId,
                    'webhook_id' => $webhook['id'],
                    'entity_type' => $entityType,
                    'action' => $action,
                    'is_active' => true,
                    'last_check_at' => now(),
                    'last_success_at' => now(),
                ]);

                Log::info('Webhook created', [
                    'account_id' => $accountId,
                    'entity_type' => $entityType,
                    'action' => $action,
                    'webhook_id' => $webhook['id']
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to create webhook', [
                    'account_id' => $accountId,
                    'entity_type' => $entityType,
                    'action' => $action,
                    'error' => $e->getMessage()
                ]);

                // Сохранить ошибку
                WebhookHealth::create([
                    'account_id' => $accountId,
                    'webhook_id' => null,
                    'entity_type' => $entityType,
                    'action' => $action,
                    'is_active' => false,
                    'last_check_at' => now(),
                    'error_message' => $e->getMessage(),
                    'check_attempts' => 1,
                ]);
            }
        }

        return $webhooks;
    }

    /**
     * Проверить наличие всех вебхуков (cron job)
     */
    public function checkWebhookHealth(string $accountId): array
    {
        // TODO: Реализовать проверку
        // 1. Получить список вебхуков через API
        // 2. Сравнить с webhook_health
        // 3. Обновить статусы
        // 4. Попытаться восстановить отсутствующие

        return [];
    }

    /**
     * Восстановить вебхук
     */
    public function restoreWebhook(string $accountId, string $entityType, string $action): bool
    {
        // TODO: Реализовать восстановление

        return false;
    }

    /**
     * Получить статус вебхуков
     */
    public function getWebhookStatus(string $accountId = null): array
    {
        $query = WebhookHealth::query();

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        return $query->get()->toArray();
    }

    /**
     * Обработать ошибку "лимит вебхуков исчерпан"
     */
    public function handleWebhookLimitError(string $accountId, \Exception $e): void
    {
        Log::error('Webhook limit exceeded', [
            'account_id' => $accountId,
            'error' => $e->getMessage()
        ]);

        // Сохранить уведомление для UI
        // TODO: Реализовать систему уведомлений
    }
}
```

---

## Этап 8: Frontend - Компоненты настроек заказов

### 8.1 Структура компонентов

```
resources/js/components/
├── common/
│   ├── StatsCard.vue
│   ├── LoadingSpinner.vue
│   ├── ErrorAlert.vue
│   ├── SuccessAlert.vue
│   ├── ConfirmModal.vue
│   ├── Badge.vue
│   └── EmptyState.vue
├── sync/
│   ├── OrderSyncSettings.vue          # Главный компонент настроек заказов
│   ├── CustomerOrderSettings.vue      # Настройки заказов покупателя
│   ├── RetailDemandSettings.vue       # Настройки розничных продаж
│   ├── CommonOrderSettings.vue        # Общие настройки
│   ├── StateSelector.vue              # Выбор статуса + кнопка создания
│   ├── SalesChannelSelector.vue       # Выбор канала продаж + кнопка создания
│   ├── OrganizationSelector.vue       # Выбор юр.лица
│   ├── EmployeeSelector.vue           # Выбор сотрудника
│   ├── CounterpartySelector.vue       # Выбор контрагента-заглушки
│   ├── CreateStateModal.vue           # Модальное окно создания статуса
│   └── CreateSalesChannelModal.vue    # Модальное окно создания канала
└── ...
```

### 8.2 Пример компонента OrderSyncSettings.vue

**Путь:** `resources/js/components/sync/OrderSyncSettings.vue`

```vue
<template>
  <div class="space-y-6">
    <LoadingSpinner v-if="loading" />

    <ErrorAlert v-if="error" :message="error" />

    <!-- Заказы поставщику -->
    <div class="bg-white shadow rounded-lg p-6">
      <h3 class="text-lg font-semibold text-gray-900 mb-2">Заказы поставщику</h3>
      <p class="text-sm text-gray-500 mb-4">
        Заказы от франшизы к вам (purchaseorder → customerorder в главном)
      </p>
      <div class="mt-4">
        <label class="flex items-center">
          <input
            type="checkbox"
            v-model="localSettings.sync_purchase_orders"
            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
          />
          <span class="ml-2 text-sm text-gray-700">Синхронизировать заказы поставщику</span>
        </label>
      </div>
    </div>

    <!-- Заказы покупателя -->
    <CustomerOrderSettings v-model="localSettings" :account-id="accountId" />

    <!-- Розничные продажи -->
    <RetailDemandSettings v-model="localSettings" :account-id="accountId" />

    <!-- Общие настройки -->
    <CommonOrderSettings v-model="localSettings" :account-id="accountId" />
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import CustomerOrderSettings from './CustomerOrderSettings.vue'
import RetailDemandSettings from './RetailDemandSettings.vue'
import CommonOrderSettings from './CommonOrderSettings.vue'
import LoadingSpinner from '../common/LoadingSpinner.vue'
import ErrorAlert from '../common/ErrorAlert.vue'

const props = defineProps({
  modelValue: Object,
  accountId: String,
  loading: Boolean,
  error: String
})

const emit = defineEmits(['update:modelValue'])

const localSettings = ref({ ...props.modelValue })

watch(() => props.modelValue, (newValue) => {
  localSettings.value = { ...newValue }
}, { deep: true })

watch(localSettings, (newValue) => {
  emit('update:modelValue', newValue)
}, { deep: true })
</script>
```

### 8.3 Пример StateSelector.vue

**Путь:** `resources/js/components/sync/StateSelector.vue`

```vue
<template>
  <div class="space-y-2">
    <label class="block text-sm font-medium text-gray-700">{{ label }}</label>
    <div class="flex gap-2">
      <select
        :value="modelValue"
        @change="$emit('update:modelValue', $event.target.value)"
        class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
      >
        <option value="">Выберите статус</option>
        <option v-for="state in states" :key="state.id" :value="state.id">
          {{ state.name }}
        </option>
      </select>
      <button
        @click="showCreateModal = true"
        type="button"
        class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
      >
        <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Создать
      </button>
    </div>

    <CreateStateModal
      v-if="showCreateModal"
      :account-id="accountId"
      :entity-type="entityType"
      @created="handleStateCreated"
      @close="showCreateModal = false"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useStates } from '../../composables/useStates'
import CreateStateModal from './CreateStateModal.vue'

const props = defineProps({
  label: String,
  modelValue: String,
  accountId: String,
  entityType: {
    type: String,
    default: 'customerorder'
  }
})

const emit = defineEmits(['update:modelValue', 'created'])

const showCreateModal = ref(false)

const { states, fetchStates } = useStates(props.accountId, props.entityType)

onMounted(async () => {
  await fetchStates()
})

const handleStateCreated = async (newState) => {
  await fetchStates()
  emit('update:modelValue', newState.id)
  emit('created', newState)
  showCreateModal.value = false
}
</script>
```

### 8.4 Composable useStates.js

**Путь:** `resources/js/composables/useStates.js`

```javascript
import { ref } from 'vue'
import axios from 'axios'

export function useStates(accountId, entityType = 'customerorder') {
  const states = ref([])
  const loading = ref(false)
  const error = ref(null)

  const fetchStates = async () => {
    loading.value = true
    error.value = null

    try {
      const response = await axios.get(`/api/accounts/${accountId}/states/${entityType}`)
      states.value = response.data
    } catch (err) {
      error.value = err.message || 'Ошибка при получении статусов'
      console.error('Error fetching states:', err)
    } finally {
      loading.value = false
    }
  }

  const createState = async (name, color = null) => {
    try {
      const response = await axios.post(`/api/accounts/${accountId}/states/${entityType}`, {
        name,
        color
      })

      await fetchStates() // Refresh list
      return response.data
    } catch (err) {
      error.value = err.message || 'Ошибка при создании статуса'
      throw err
    }
  }

  return {
    states,
    loading,
    error,
    fetchStates,
    createState
  }
}
```

---

## Этап 9: Тестирование

### 9.1 Сценарий тестирования заказов покупателя

1. **Настройка синхронизации customerorder:**
   - Открыть настройки франшизы
   - Включить `sync_customer_orders`
   - Создать статус "От франшизы" (входящий)
   - Создать статус "Отгружен" (успешная продажа)
   - Создать канал продаж "Заказы франшиз"
   - Выбрать юр.лицо
   - Выбрать ответственного сотрудника
   - Сохранить настройки

2. **Создание заказа покупателя в дочернем:**
   - В дочернем аккаунте создать заказ покупателя
   - Указать товары из синхронизированного каталога
   - Провести заказ (applicable = true)

3. **Проверка создания в главном:**
   - Открыть главный аккаунт
   - Найти созданный заказ покупателя
   - Проверить поля:
     - organization = выбранное юр.лицо
     - agent = контрагент франшизы или заглушка
     - state = "От франшизы"
     - owner = выбранный сотрудник
     - salesChannel = "Заказы франшиз"
     - positions = сопоставленные товары

4. **Изменение статуса на успешную продажу:**
   - В дочернем изменить статус заказа на "Отгружен"
   - Проверить обновление статуса в главном

### 9.2 Сценарий тестирования розничных продаж

1. **Настройка синхронизации retaildemand:**
   - Включить `sync_retail_demands`
   - Создать статус "От розницы франшизы" (входящий)
   - Создать статус "Розница завершена" (успешная продажа)
   - Создать канал продаж "Розница франшиз"
   - Выбрать юр.лицо и сотрудника
   - Сохранить

2. **Создание розничной продажи в дочернем:**
   - В дочернем создать retaildemand
   - Провести продажу (applicable = true)

3. **Проверка создания ЗАКАЗА ПОКУПАТЕЛЯ в главном:**
   - Открыть главный аккаунт
   - Найти созданный заказ покупателя (не retaildemand!)
   - Проверить salesChannel = "Розница франшиз"
   - Проверить остальные поля

### 9.3 Сценарий с контрагентами-заглушками

1. **Настройка использования заглушки:**
   - Выбрать `sync_real_counterparties = false`
   - Создать контрагента-заглушку "Франшиза (общий)"
   - Сохранить настройки

2. **Создание заказов с разными контрагентами:**
   - В дочернем создать 3 заказа с разными покупателями
   - Проверить что все 3 заказа в главном с agent = заглушка

### 9.4 Сценарий с реальными контрагентами

1. **Включить синхронизацию контрагентов:**
   - Выбрать `sync_real_counterparties = true`
   - Сохранить

2. **Создание заказа:**
   - В дочернем создать заказ с новым контрагентом
   - Проверить автоматическое создание контрагента в главном
   - Проверить сопоставление в `counterparty_mappings`

3. **Повторный заказ от того же контрагента:**
   - Создать второй заказ от того же контрагента
   - Проверить что используется существующий контрагент (не создается дубликат)

---

## Этап 10: Оптимизации для множества дочерних аккаунтов

**Цель:** Обеспечить эффективную работу системы при наличии большого количества дочерних аккаунтов (100+) на один родительский аккаунт.

### 10.1 Дополнительные индексы БД

```sql
-- Индексы для быстрой выборки дочерних аккаунтов
CREATE INDEX idx_child_accounts_parent_status ON child_accounts(parent_account_id, is_active);

-- Индексы для sync_queue
CREATE INDEX idx_sync_queue_status_scheduled ON sync_queue(status, scheduled_at);
CREATE INDEX idx_sync_queue_account_entity ON sync_queue(account_id, entity_type, entity_id);

-- Индексы для entity_mappings
CREATE INDEX idx_entity_mappings_parent_type ON entity_mappings(parent_account_id, entity_type);
CREATE INDEX idx_entity_mappings_child_type ON entity_mappings(child_account_id, entity_type);

-- Индексы для webhook_health
CREATE INDEX idx_webhook_health_active_check ON webhook_health(is_active, last_check_at);

-- Индексы для sync_logs
CREATE INDEX idx_sync_logs_account_date ON sync_logs(account_id, created_at);
CREATE INDEX idx_sync_logs_status_date ON sync_logs(status, created_at);
```

---

### 10.2 Таблица статистики синхронизации

```sql
CREATE TABLE sync_statistics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    parent_account_id UUID NOT NULL,
    child_account_id UUID NOT NULL,
    date DATE NOT NULL,
    products_synced INT DEFAULT 0,
    products_failed INT DEFAULT 0,
    orders_synced INT DEFAULT 0,
    orders_failed INT DEFAULT 0,
    sync_duration_avg INT DEFAULT 0,
    api_calls_count INT DEFAULT 0,
    last_sync_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_daily_stats (parent_account_id, child_account_id, date),
    INDEX idx_sync_statistics_parent_date (parent_account_id, date),
    INDEX idx_sync_statistics_child_date (child_account_id, date)
);
```

**Описание:**
- Хранит агрегированную статистику синхронизации по дням
- Используется для мониторинга и отчетности
- Позволяет отслеживать производительность каждой франшизы

---

### 10.3 Поля приоритета и задержки в sync_settings

```sql
ALTER TABLE sync_settings ADD COLUMN sync_priority INT DEFAULT 5;
ALTER TABLE sync_settings ADD COLUMN sync_delay_seconds INT DEFAULT 0;

CREATE INDEX idx_sync_settings_priority ON sync_settings(sync_priority DESC);
```

**Описание:**
- `sync_priority` - приоритет синхронизации (1-10, где 10 = высший)
- `sync_delay_seconds` - задержка между синхронизациями для франшизы (распределение нагрузки)
- Позволяет гибко управлять порядком обработки разных франшиз

---

### 10.4 BatchSyncService - Массовая синхронизация

**Путь:** `app/Services/BatchSyncService.php`

```php
<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BatchSyncService
{
    protected MoySkladService $moySkladService;
    protected ProductSyncService $productSyncService;

    public function __construct(
        MoySkladService $moySkladService,
        ProductSyncService $productSyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->productSyncService = $productSyncService;
    }

    /**
     * Массовая синхронизация товара на все дочерние аккаунты
     * Использует очередь для избежания rate limits
     */
    public function batchSyncProduct(string $mainAccountId, string $productId): void
    {
        try {
            // Получить все активные дочерние аккаунты
            $childAccounts = DB::table('child_accounts')
                ->join('sync_settings', 'child_accounts.child_account_id', '=', 'sync_settings.account_id')
                ->where('child_accounts.parent_account_id', $mainAccountId)
                ->where('child_accounts.is_active', true)
                ->where('sync_settings.sync_products', true)
                ->orderBy('sync_settings.sync_priority', 'desc')
                ->select('child_accounts.*', 'sync_settings.sync_delay_seconds', 'sync_settings.sync_priority')
                ->get();

            Log::info('Batch sync product started', [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'child_accounts_count' => $childAccounts->count()
            ]);

            // Распределить задачи с задержками
            $baseDelay = 0;

            foreach ($childAccounts as $index => $childAccount) {
                // Вычислить scheduled_at с учетом приоритета и задержки
                $delay = $baseDelay + ($childAccount->sync_delay_seconds ?? 0);
                $scheduledAt = now()->addSeconds($delay);

                // Добавить в очередь
                SyncQueue::create([
                    'account_id' => $childAccount->child_account_id,
                    'entity_type' => 'product',
                    'entity_id' => $productId,
                    'operation' => 'sync',
                    'priority' => $childAccount->sync_priority ?? 5,
                    'status' => 'pending',
                    'payload' => [
                        'main_account_id' => $mainAccountId,
                        'product_id' => $productId
                    ],
                    'scheduled_at' => $scheduledAt,
                ]);

                // Увеличить базовую задержку для следующих (распределить нагрузку)
                // Примерно 100-200ms на франшизу
                $baseDelay += 0.15; // seconds
            }

            Log::info('Batch sync product queued', [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'queued_count' => $childAccounts->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Batch sync product failed', [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Массовая проверка вебхуков для всех аккаунтов
     */
    public function batchCheckWebhooks(string $parentAccountId = null): void
    {
        $query = DB::table('accounts');

        if ($parentAccountId) {
            $query->where('account_id', $parentAccountId)
                  ->orWhereIn('account_id', function($q) use ($parentAccountId) {
                      $q->select('child_account_id')
                        ->from('child_accounts')
                        ->where('parent_account_id', $parentAccountId);
                  });
        }

        $accounts = $query->where('status', 'activated')->get();

        foreach ($accounts as $account) {
            // Добавить в очередь проверки (низкий приоритет)
            SyncQueue::create([
                'account_id' => $account->account_id,
                'entity_type' => 'webhook',
                'entity_id' => 'health_check',
                'operation' => 'check',
                'priority' => 1, // Низкий приоритет
                'status' => 'pending',
                'scheduled_at' => now()->addMinutes(rand(1, 15)), // Распределить по времени
            ]);
        }
    }
}
```

---

### 10.5 SyncStatisticsService - Сбор статистики

**Путь:** `app/Services/SyncStatisticsService.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncStatisticsService
{
    /**
     * Записать статистику синхронизации
     */
    public function recordSync(
        string $parentAccountId,
        string $childAccountId,
        string $type, // 'product' or 'order'
        bool $success,
        int $duration
    ): void {
        try {
            $today = now()->toDateString();

            // Atomic update/insert
            DB::table('sync_statistics')
                ->updateOrInsert(
                    [
                        'parent_account_id' => $parentAccountId,
                        'child_account_id' => $childAccountId,
                        'date' => $today,
                    ],
                    [
                        $type === 'product' ? 'products_synced' : 'orders_synced' => DB::raw(
                            ($type === 'product' ? 'products_synced' : 'orders_synced') . ' + ' . ($success ? 1 : 0)
                        ),
                        $type === 'product' ? 'products_failed' : 'orders_failed' => DB::raw(
                            ($type === 'product' ? 'products_failed' : 'orders_failed') . ' + ' . ($success ? 0 : 1)
                        ),
                        'sync_duration_avg' => DB::raw("(sync_duration_avg * api_calls_count + {$duration}) / (api_calls_count + 1)"),
                        'api_calls_count' => DB::raw('api_calls_count + 1'),
                        'last_sync_at' => now(),
                        'updated_at' => now(),
                    ]
                );

        } catch (\Exception $e) {
            Log::error('Failed to record sync statistics', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Получить статистику для родительского аккаунта
     */
    public function getStatistics(string $parentAccountId, int $days = 7): array
    {
        $startDate = now()->subDays($days)->toDateString();

        return DB::table('sync_statistics')
            ->where('parent_account_id', $parentAccountId)
            ->where('date', '>=', $startDate)
            ->orderBy('date', 'desc')
            ->get()
            ->groupBy('child_account_id')
            ->toArray();
    }

    /**
     * Получить агрегированную статистику
     */
    public function getAggregatedStats(string $parentAccountId, int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();

        $stats = DB::table('sync_statistics')
            ->where('parent_account_id', $parentAccountId)
            ->where('date', '>=', $startDate)
            ->select([
                DB::raw('SUM(products_synced) as total_products_synced'),
                DB::raw('SUM(products_failed) as total_products_failed'),
                DB::raw('SUM(orders_synced) as total_orders_synced'),
                DB::raw('SUM(orders_failed) as total_orders_failed'),
                DB::raw('AVG(sync_duration_avg) as avg_duration'),
                DB::raw('SUM(api_calls_count) as total_api_calls'),
            ])
            ->first();

        return (array) $stats;
    }
}
```

---

### 10.6 CheckSingleAccountWebhooksJob - Оптимизированная проверка вебхуков

**Путь:** `app/Jobs/CheckSingleAccountWebhooksJob.php`

```php
<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckSingleAccountWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;

    protected string $accountId;

    public function __construct(string $accountId)
    {
        $this->accountId = $accountId;
    }

    public function handle(WebhookService $webhookService): void
    {
        try {
            Log::info('Checking webhooks for account', [
                'account_id' => $this->accountId
            ]);

            $webhookService->checkWebhookHealth($this->accountId);

        } catch (\Exception $e) {
            Log::error('Failed to check webhooks', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
```

---

### 10.7 Redis кэширование

**Конфигурация кэша:** `config/cache.php`

```php
'stores' => [
    // ...
    'moysklad' => [
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_connection' => 'default',
    ],
],
```

**Пример использования в ProductSyncService:**

```php
use Illuminate\Support\Facades\Cache;

public function getChildAccounts(string $parentAccountId): array
{
    $cacheKey = "child_accounts:{$parentAccountId}";

    return Cache::store('moysklad')->remember($cacheKey, 300, function() use ($parentAccountId) {
        return DB::table('child_accounts')
            ->where('parent_account_id', $parentAccountId)
            ->where('is_active', true)
            ->get()
            ->toArray();
    });
}

// Инвалидация кэша при изменении
public function invalidateChildAccountsCache(string $parentAccountId): void
{
    Cache::store('moysklad')->forget("child_accounts:{$parentAccountId}");
}
```

**Кэшируемые данные:**
- Список дочерних аккаунтов (TTL: 5 минут)
- Entity mappings (TTL: 10 минут)
- Sync settings (TTL: 5 минут)
- Sales channels, states (TTL: 30 минут)

---

### 10.8 Оптимизация WebhookController

**Обработка вебхуков через очередь:**

```php
protected function handleProductSync(Account $account, string $entityType, string $action, string $entityId)
{
    if ($account->account_type === 'main') {
        // Не синхронизировать сразу, добавить в очередь
        $batchSyncService = app(BatchSyncService::class);

        match($action) {
            'CREATE', 'UPDATE' => $batchSyncService->batchSyncProduct(
                $account->account_id,
                $entityId
            ),
            'DELETE' => $batchSyncService->batchDeleteProduct(
                $account->account_id,
                $entityId
            ),
        };

        Log::info('Product sync queued for all child accounts', [
            'main_account_id' => $account->account_id,
            'product_id' => $entityId,
            'action' => $action
        ]);
    }
}
```

---

### 10.9 ProcessSyncQueueJob - Обработчик очереди

**Путь:** `app/Jobs/ProcessSyncQueueJob.php`

```php
<?php

namespace App\Jobs;

use App\Models\SyncQueue;
use App\Services\ProductSyncService;
use App\Services\SyncStatisticsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSyncQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;

    public function handle(
        ProductSyncService $productSyncService,
        SyncStatisticsService $statisticsService
    ): void {
        // Обработать задачи из очереди (порциями по 50)
        $tasks = SyncQueue::where('status', 'pending')
            ->where(function($query) {
                $query->whereNull('scheduled_at')
                      ->orWhere('scheduled_at', '<=', now());
            })
            ->orderBy('priority', 'desc')
            ->orderBy('scheduled_at', 'asc')
            ->limit(50)
            ->get();

        if ($tasks->isEmpty()) {
            return;
        }

        foreach ($tasks as $task) {
            try {
                $task->update([
                    'status' => 'processing',
                    'started_at' => now(),
                ]);

                $startTime = microtime(true);

                // Обработать задачу
                match($task->entity_type) {
                    'product' => $this->processProductSync($task, $productSyncService),
                    'variant' => $this->processVariantSync($task, $productSyncService),
                    'bundle' => $this->processBundleSync($task, $productSyncService),
                    'webhook' => $this->processWebhookCheck($task),
                    default => Log::warning("Unknown entity type in queue: {$task->entity_type}")
                };

                $duration = (int)((microtime(true) - $startTime) * 1000); // ms

                // Отметить как выполненное
                $task->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                // Записать статистику
                if (in_array($task->entity_type, ['product', 'variant', 'bundle'])) {
                    $payload = $task->payload;
                    $statisticsService->recordSync(
                        $payload['main_account_id'] ?? '',
                        $task->account_id,
                        'product',
                        true,
                        $duration
                    );
                }

            } catch (\Exception $e) {
                $task->increment('attempts');

                if ($task->attempts >= $task->max_attempts) {
                    $task->update([
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    $task->update([
                        'status' => 'pending',
                        'error' => $e->getMessage(),
                        'scheduled_at' => now()->addMinutes(5), // Retry через 5 минут
                    ]);
                }

                Log::error('Sync queue task failed', [
                    'task_id' => $task->id,
                    'entity_type' => $task->entity_type,
                    'entity_id' => $task->entity_id,
                    'attempts' => $task->attempts,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    protected function processProductSync(SyncQueue $task, ProductSyncService $productSyncService): void
    {
        $payload = $task->payload;
        $productSyncService->syncProduct(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );
    }

    // ... аналогичные методы для variant, bundle, webhook
}
```

---

### 10.10 Scheduler - Периодические задачи

**Путь:** `app/Console/Kernel.php`

```php
protected function schedule(Schedule $schedule): void
{
    // Обработка очереди синхронизации каждую минуту
    $schedule->job(new ProcessSyncQueueJob())->everyMinute();

    // Проверка вебхуков каждые 15 минут (распределенно)
    $schedule->call(function() {
        $accounts = Account::where('status', 'activated')->pluck('account_id');

        foreach ($accounts as $index => $accountId) {
            // Распределить проверки на 15 минут
            $delay = ($index % 15) * 60; // seconds

            CheckSingleAccountWebhooksJob::dispatch($accountId)
                ->delay(now()->addSeconds($delay));
        }
    })->everyFifteenMinutes();

    // Очистка старых завершенных задач из sync_queue (старше 7 дней)
    $schedule->call(function() {
        SyncQueue::where('status', 'completed')
            ->where('completed_at', '<', now()->subDays(7))
            ->delete();
    })->daily();

    // Архивация старой статистики (старше 90 дней)
    $schedule->call(function() {
        DB::table('sync_statistics')
            ->where('date', '<', now()->subDays(90))
            ->delete();
    })->weekly();
}
```

---

### 10.11 Frontend: Дашборд статистики

**Путь:** `resources/js/components/sync/SyncStatsDashboard.vue`

```vue
<template>
  <div class="space-y-6">
    <h2 class="text-2xl font-semibold text-gray-900">Статистика синхронизации</h2>

    <!-- Общая статистика -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
      <StatsCard
        title="Всего синхронизаций"
        :value="aggregatedStats.total_api_calls"
        icon="sync"
        color="blue"
      />
      <StatsCard
        title="Товаров синхронизировано"
        :value="aggregatedStats.total_products_synced"
        icon="package"
        color="green"
      />
      <StatsCard
        title="Заказов синхронизировано"
        :value="aggregatedStats.total_orders_synced"
        icon="shopping-cart"
        color="purple"
      />
      <StatsCard
        title="Среднее время (мс)"
        :value="Math.round(aggregatedStats.avg_duration)"
        icon="clock"
        color="yellow"
      />
    </div>

    <!-- Статистика по франшизам -->
    <div class="bg-white shadow rounded-lg p-6">
      <h3 class="text-lg font-semibold text-gray-900 mb-4">Статистика по франшизам</h3>
      <table class="min-w-full divide-y divide-gray-300">
        <thead>
          <tr>
            <th class="py-3 text-left text-sm font-semibold text-gray-900">Франшиза</th>
            <th class="py-3 text-left text-sm font-semibold text-gray-900">Товаров</th>
            <th class="py-3 text-left text-sm font-semibold text-gray-900">Заказов</th>
            <th class="py-3 text-left text-sm font-semibold text-gray-900">Ошибок</th>
            <th class="py-3 text-left text-sm font-semibold text-gray-900">Последняя синхронизация</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr v-for="stat in franchiseStats" :key="stat.child_account_id">
            <td class="py-4 text-sm text-gray-900">{{ stat.account_name }}</td>
            <td class="py-4 text-sm text-gray-500">{{ stat.products_synced }}</td>
            <td class="py-4 text-sm text-gray-500">{{ stat.orders_synced }}</td>
            <td class="py-4 text-sm text-red-600">{{ stat.products_failed + stat.orders_failed }}</td>
            <td class="py-4 text-sm text-gray-500">{{ formatDate(stat.last_sync_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import StatsCard from '../common/StatsCard.vue'

const props = defineProps({
  accountId: String
})

const aggregatedStats = ref({})
const franchiseStats = ref([])

onMounted(async () => {
  await fetchStats()
})

const fetchStats = async () => {
  try {
    const response = await axios.get(`/api/accounts/${props.accountId}/sync-statistics`)
    aggregatedStats.value = response.data.aggregated
    franchiseStats.value = response.data.by_franchise
  } catch (error) {
    console.error('Failed to fetch statistics:', error)
  }
}

const formatDate = (date) => {
  if (!date) return 'Никогда'
  return new Date(date).toLocaleString('ru-RU')
}
</script>
```

---

### 10.12 Frontend: Массовые операции

**Обновить `ChildAccounts.vue` для добавления массовых действий:**

```vue
<!-- Добавить в ChildAccounts.vue -->
<div class="flex gap-2 mb-4">
  <button
    @click="syncAllProducts"
    :disabled="bulkSyncing"
    class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
  >
    <svg v-if="bulkSyncing" class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
    </svg>
    {{ bulkSyncing ? 'Синхронизация...' : 'Синхронизировать все товары' }}
  </button>

  <button
    @click="checkAllWebhooks"
    class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
  >
    Проверить все вебхуки
  </button>
</div>

<script setup>
// ...

const bulkSyncing = ref(false)

const syncAllProducts = async () => {
  if (!confirm('Запустить полную синхронизацию товаров на все франшизы? Это может занять некоторое время.')) {
    return
  }

  try {
    bulkSyncing.value = true
    await axios.post(`/api/accounts/${accountId}/sync-all-products`)
    alert('Синхронизация запущена. Процесс будет выполнен в фоне.')
  } catch (error) {
    alert('Ошибка при запуске синхронизации: ' + error.message)
  } finally {
    bulkSyncing.value = false
  }
}

const checkAllWebhooks = async () => {
  try {
    await axios.post(`/api/accounts/${accountId}/check-all-webhooks`)
    alert('Проверка вебхуков запущена')
  } catch (error) {
    alert('Ошибка: ' + error.message)
  }
}
</script>
```

---

### 10.13 Мониторинг и алерты

**Создать систему уведомлений при проблемах:**

```php
// app/Services/AlertService.php

class AlertService
{
    /**
     * Отправить алерт при большом количестве ошибок
     */
    public function checkSyncHealth(string $parentAccountId): void
    {
        $stats = DB::table('sync_statistics')
            ->where('parent_account_id', $parentAccountId)
            ->where('date', '>=', now()->subDays(1))
            ->select([
                DB::raw('SUM(products_failed) as total_failed'),
                DB::raw('SUM(products_synced) as total_synced'),
            ])
            ->first();

        $failureRate = $stats->total_synced > 0
            ? ($stats->total_failed / $stats->total_synced) * 100
            : 0;

        // Если более 20% синхронизаций падают - отправить алерт
        if ($failureRate > 20) {
            $this->sendAlert($parentAccountId, [
                'type' => 'high_failure_rate',
                'failure_rate' => $failureRate,
                'total_failed' => $stats->total_failed,
                'total_synced' => $stats->total_synced,
            ]);
        }
    }

    protected function sendAlert(string $accountId, array $data): void
    {
        // Отправить email, slack, telegram и т.д.
        Log::critical('Sync health alert', [
            'account_id' => $accountId,
            'data' => $data
        ]);

        // TODO: Реализовать отправку уведомлений
    }
}
```

---

## Порядок реализации (приоритет)

1. **Миграции БД** (все новые таблицы и поля) ✅
2. **Модели Eloquent** (Account, SyncSetting, SyncQueue, и т.д.) ✅
3. **RateLimitHandler** (парсинг headers из МойСклад) ⏳
4. **Обновить MoySkladService::request()** (извлечение rate limit info) ⏳
5. **SalesChannelService** (управление каналами продаж) ⏳
6. **StateService** (управление статусами документов) ⏳
7. **EmployeeService** (получение сотрудников) ⏳
8. **OrganizationService** (получение юр.лиц) ⏳
9. **CounterpartySyncService** (расширенный - создание франшизы, поставщика, синхронизация) ⏳
10. **CustomerOrderSyncService** (customerorder → customerorder) ⏳
11. **RetailDemandSyncService** (retaildemand → customerorder) ⏳
12. **Обновить WebhookController** (обработка purchaseorder, customerorder, retaildemand) ⏳
13. **Обновить WebhookService** (вебхуки для заказов в зависимости от типа аккаунта) ⏳
14. **API endpoints** (все новые контроллеры) ⏳
15. **Laravel Jobs** (ProcessSyncQueueJob, CheckWebhookHealthJob) ⏳
16. **Scheduler** (настройка cron задач) ⏳
17. **Frontend: Компоненты селекторов** (StateSelector, SalesChannelSelector, и т.д.) ⏳
18. **Frontend: Модальные окна создания** (CreateStateModal, CreateSalesChannelModal) ⏳
19. **Frontend: Компоненты настроек заказов** (OrderSyncSettings, CustomerOrderSettings, и т.д.) ⏳
20. **Frontend: Composables** (useStates, useSalesChannels, useOrganizations, useEmployees) ⏳
21. **Frontend: Обновить SyncSettings.vue** (добавить вкладку "Заказы") ⏳
22. **Тестирование всех сценариев** ⏳
23. **Обновить CLAUDE.md** (добавить информацию о заказах) ⏳
24. **Обновить README.md** (добавить инструкции по настройке заказов) ⏳

### Этап 10: Оптимизации для масштабирования

25. **Дополнительные индексы БД** (для производительности при большом количестве франшиз) ⏳
26. **Миграция sync_statistics** (таблица для статистики) ⏳
27. **Поля sync_priority и sync_delay_seconds** (в sync_settings) ⏳
28. **BatchSyncService** (массовая синхронизация с очередями) ⏳
29. **SyncStatisticsService** (сбор и агрегация статистики) ⏳
30. **CheckSingleAccountWebhooksJob** (оптимизированная проверка вебхуков) ⏳
31. **ProcessSyncQueueJob** (обработчик очереди синхронизации) ⏳
32. **Redis кэширование** (конфигурация и внедрение в сервисы) ⏳
33. **Оптимизация WebhookController** (через очереди вместо синхронной обработки) ⏳
34. **Scheduler (Kernel.php)** (настройка периодических задач) ⏳
35. **AlertService** (мониторинг и уведомления о проблемах) ⏳
36. **Frontend: SyncStatsDashboard.vue** (дашборд статистики) ⏳
37. **Frontend: Массовые операции в ChildAccounts.vue** ⏳
38. **API endpoints для статистики** (контроллеры и роуты) ⏳
39. **Нагрузочное тестирование** (100+ франшиз) ⏳
40. **Документация по масштабированию** ⏳

---

## Примечания

- **Rate Limits МойСклад:** Headers возвращаются в каждом ответе API. Таблица для хранения не нужна - читаем из headers при каждом запросе.
- **Автосоздание:** Доп.поля, характеристики, типы цен создаются автоматически если отсутствуют в дочернем аккаунте.
- **Направление синхронизации:**
  - Товары: main → child (из главного в дочерний)
  - Заказы: child → main (из дочернего в главный)
- **Все заказы в главном = customerorder:** Независимо от источника (purchaseorder, customerorder, retaildemand из дочернего), в главном всегда создается customerorder с разными каналами продаж для различения.
- **Статусы:** Отслеживание успешной продажи через смену state в дочернем аккаунте, а не через отгрузки (demand).

---

**Конец документа**
