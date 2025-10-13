# Документация проекта "Франшиза-синхронизация МойСклад"

**Версия:** 1.0  
**Дата:** 13 октября 2025  
**Для:** AI Assistant / Разработчик

---

## 1. ОБЗОР ПРОЕКТА

### 1.1 Назначение
Приложение для управления сетью дочерних аккаунтов МойСклад с автоматической синхронизацией данных между главным и дочерними аккаунтами франшизной сети.

### 1.2 Основные функции
- Централизованное управление каталогом товаров
- Автоматическая синхронизация товаров, цен, остатков
- Обработка заказов от дочерних аккаунтов
- Распределение заказов между точками
- Аналитика и отчетность по всей сети
- Управление дочерними аккаунтами

### 1.3 Технологический стек

**Backend:**
- PHP 8.4
- Laravel 11.x
- PostgreSQL 18
- Redis 7.x
- Nginx 1.26

**Frontend:**
- Vue 3
- Vite 5
- Tailwind CSS 3
- Axios

**Интеграции:**
- МойСклад JSON API 1.2
- МойСклад Vendor API 1.0
- МойСклад Webhook API

**Инфраструктура:**
- CentOS 9
- SSL: Let's Encrypt
- Автодеплой: GitHub Actions

---

## 2. АРХИТЕКТУРА

### 2.1 Структура проекта

```
app.cavaleria.ru/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/
│   │   │       ├── MoySkladController.php      # Vendor API endpoints
│   │   │       └── WebhookController.php       # Webhook обработка
│   │   └── Middleware/
│   ├── Services/
│   │   └── MoySkladService.php                 # JSON API wrapper
│   ├── Jobs/                                   # Queue jobs (будущее)
│   └── Models/                                 # Eloquent models (будущее)
├── config/
│   └── moysklad.php                            # Конфигурация МойСклад
├── database/
│   └── migrations/
│       ├── 2025_10_13_000001_create_accounts_table.php
│       ├── 2025_10_13_000002_create_child_accounts_table.php
│       ├── 2025_10_13_000003_create_sync_settings_table.php
│       ├── 2025_10_13_000004_create_sync_logs_table.php
│       ├── 2025_10_13_000005_create_entity_mappings_table.php
│       ├── 2025_10_13_000006_create_webhooks_table.php
│       └── 2025_10_13_000007_create_accounts_archive_table.php
├── resources/
│   ├── js/
│   │   ├── components/                         # Vue компоненты (будущее)
│   │   ├── views/                              # Vue страницы
│   │   ├── App.vue                             # Root компонент
│   │   └── app.js                              # Entry point
│   ├── css/
│   │   └── app.css                             # Tailwind CSS
│   └── views/
│       └── app.blade.php                       # Blade шаблон
├── routes/
│   ├── api.php                                 # API маршруты
│   └── web.php                                 # Web маршруты
├── storage/
│   └── logs/
│       ├── laravel.log                         # Application logs
│       └── deploy.log                          # Deployment logs
├── public/
│   ├── build/                                  # Compiled assets
│   └── index.php                               # Entry point
├── .env                                        # Environment variables
├── .env.example                                # Environment template
├── composer.json                               # PHP dependencies
├── package.json                                # JS dependencies
├── vite.config.js                              # Vite configuration
└── deploy.sh                                   # Deployment script
```

### 2.2 Модель данных

#### Таблица: `accounts`
Основная таблица установленных приложений.

```sql
id                    BIGSERIAL PRIMARY KEY
app_id                UUID NOT NULL              -- UUID приложения
account_id            UUID UNIQUE NOT NULL       -- UUID аккаунта МойСклад
access_token          VARCHAR(255) NOT NULL      -- Bearer токен для API
status                VARCHAR(50)                -- activating, activated, suspended
account_type          VARCHAR(20)                -- parent, child
subscription_status   VARCHAR(50)                -- Active, Trial, etc.
tariff_name           VARCHAR(100)               -- Главный, Дочерний
price_per_month       DECIMAL(10,2)              -- Цена подписки
cause                 VARCHAR(50)                -- Install, StatusUpdate, etc.
installed_at          TIMESTAMP
suspended_at          TIMESTAMP
created_at            TIMESTAMP
updated_at            TIMESTAMP
```

#### Таблица: `child_accounts`
Связи между главными и дочерними аккаунтами.

```sql
id                    BIGSERIAL PRIMARY KEY
parent_account_id     UUID FK -> accounts(account_id)
child_account_id      UUID FK -> accounts(account_id)
invitation_code       VARCHAR(100)               -- Код приглашения
status                VARCHAR(50)                -- active, inactive
connected_at          TIMESTAMP
created_at            TIMESTAMP
updated_at            TIMESTAMP

UNIQUE(parent_account_id, child_account_id)
```

#### Таблица: `sync_settings`
Настройки синхронизации для каждого аккаунта.

```sql
id                    BIGSERIAL PRIMARY KEY
account_id            UUID FK -> accounts(account_id)
sync_catalog          BOOLEAN DEFAULT TRUE       -- Синхронизировать каталог
sync_orders           BOOLEAN DEFAULT TRUE       -- Синхронизировать заказы
sync_prices           BOOLEAN DEFAULT TRUE       -- Синхронизировать цены
sync_stock            BOOLEAN DEFAULT TRUE       -- Синхронизировать остатки
sync_images_all       BOOLEAN DEFAULT FALSE      -- Все изображения или только первое
schedule              VARCHAR(100)               -- Cron расписание
catalog_filters       JSON                       -- Фильтры для товаров
price_types           JSON                       -- Типы цен для синхронизации
warehouses            JSON                       -- Склады для синхронизации
product_match_field   VARCHAR(50)                -- article, code, externalCode, barcode
created_at            TIMESTAMP
updated_at            TIMESTAMP
```

#### Таблица: `sync_logs`
Журнал операций синхронизации.

```sql
id                    BIGSERIAL PRIMARY KEY
account_id            UUID NOT NULL
sync_type             VARCHAR(50)                -- catalog, orders_customer, prices, etc.
direction             VARCHAR(50)                -- parent_to_child, child_to_parent
status                VARCHAR(50)                -- success, error, warning, in_progress
message               TEXT
data                  JSON                       -- Дополнительные данные
items_total           INTEGER DEFAULT 0
items_processed       INTEGER DEFAULT 0
items_failed          INTEGER DEFAULT 0
started_at            TIMESTAMP
finished_at           TIMESTAMP
created_at            TIMESTAMP
updated_at            TIMESTAMP
```

#### Таблица: `entity_mappings`
Сопоставление ID сущностей между главным и дочерними аккаунтами.

```sql
id                    BIGSERIAL PRIMARY KEY
parent_account_id     UUID NOT NULL
child_account_id      UUID NOT NULL
entity_type           VARCHAR(50)                -- attribute, characteristic, status, product
parent_entity_id      VARCHAR(255)               -- ID в главном аккаунте
child_entity_id       VARCHAR(255)               -- ID в дочернем аккаунте
entity_name           VARCHAR(255)
metadata              JSON
created_at            TIMESTAMP
updated_at            TIMESTAMP

UNIQUE(parent_account_id, child_account_id, entity_type, parent_entity_id)
```

#### Таблица: `webhooks`
Зарегистрированные вебхуки.

```sql
id                    BIGSERIAL PRIMARY KEY
account_id            UUID FK -> accounts(account_id)
webhook_id            VARCHAR(255)               -- ID вебхука в МойСклад
entity_type           VARCHAR(50)                -- product, customerorder, etc.
action                VARCHAR(50)                -- CREATE, UPDATE, DELETE
enabled               BOOLEAN DEFAULT TRUE
url                   VARCHAR(255)
created_at            TIMESTAMP
updated_at            TIMESTAMP
```

#### Таблица: `accounts_archive`
Архив удаленных аккаунтов.

```sql
id                    BIGSERIAL PRIMARY KEY
account_id            UUID NOT NULL
data                  JSON                       -- Полные данные аккаунта
deleted_at            TIMESTAMP
created_at            TIMESTAMP
updated_at            TIMESTAMP
```

---

## 3. API ENDPOINTS

### 3.1 Vendor API (для МойСклад)

#### PUT /api/moysklad/vendor/1.0/apps/{appId}/{accountId}
**Назначение:** Установка/активация приложения на аккаунте

**Request Body:**
```json
{
  "access_token": "string",
  "cause": "Install|StatusUpdate|TariffChanged",
  "subscription": {
    "status": "Active|Trial|Suspended",
    "tariff": {
      "name": "string",
      "price": number
    },
    "pricePerMonth": number
  }
}
```

**Response:**
```json
{
  "status": "SettingsRequired|Activating|Activated"
}
```

**Логика:**
1. Проверка appId
2. Сохранение/обновление записи в `accounts`
3. Создание дефолтных настроек в `sync_settings`
4. Возврат статуса в зависимости от причины

#### DELETE /api/moysklad/vendor/1.0/apps/{appId}/{accountId}
**Назначение:** Удаление/приостановка приложения

**Request Body:**
```json
{
  "cause": "Uninstall|Suspend"
}
```

**Логика:**
- `Suspend`: статус → suspended, отключение вебхуков
- `Uninstall`: архивирование → `accounts_archive`, удаление всех данных

#### GET /api/moysklad/vendor/1.0/apps/{appId}/{accountId}/status
**Назначение:** Проверка статуса приложения

**Response:**
```json
{
  "status": "NotFound|Activating|Activated|SettingsRequired|Suspended"
}
```

### 3.2 Internal API

#### POST /api/apps/update-status
**Назначение:** Обновление статуса из iframe после настройки

**Request:**
```json
{
  "account_id": "uuid",
  "status": "activated|settings_required"
}
```

#### POST /api/webhooks/moysklad
**Назначение:** Обработка вебхуков от МойСклад

**Request:**
```json
{
  "action": "CREATE|UPDATE|DELETE",
  "entityType": "product|customerorder|demand",
  "events": [
    {
      "meta": {
        "href": "string"
      },
      "accountId": "uuid"
    }
  ]
}
```

#### GET /api/context/{contextKey}
**Назначение:** Получение контекста пользователя для iframe (будущее)

---

## 4. СЕРВИСЫ

### 4.1 MoySkladService

**Расположение:** `app/Services/MoySkladService.php`

**Методы:**

```php
// Базовые HTTP методы
get(string $endpoint, array $params = []): array
post(string $endpoint, array $data = []): array
put(string $endpoint, array $data = []): array
delete(string $endpoint): array

// Товары
getProducts(array $filters = []): array
getProduct(string $productId): array
createProduct(array $data): array
updateProduct(string $productId, array $data): array

// Заказы
getCustomerOrders(array $filters = []): array
getCustomerOrder(string $orderId): array
createCustomerOrder(array $data): array
updateCustomerOrder(string $orderId, array $data): array

// Вебхуки
getWebhooks(): array
createWebhook(string $url, string $action, string $entityType): array
deleteWebhook(string $webhookId): array
setupWebhooks(string $accountId): array

// Метаданные
getMetadata(string $entityType): array
createAttribute(string $entityType, array $attributeData): array

// Склады и остатки
getStores(): array
getStockByStore(string $storeId): array

// Цены
getPriceTypes(): array
```

**Использование:**

```php
use App\Services\MoySkladService;

$service = app(MoySkladService::class);
$service->setAccessToken($accessToken);

// Получить товары
$products = $service->getProducts(['limit' => 100]);

// Создать товар
$product = $service->createProduct([
    'name' => 'Новый товар',
    'article' => 'ART-001'
]);
```

---

## 5. КОНФИГУРАЦИЯ

### 5.1 Переменные окружения (.env)

```env
# Application
APP_NAME="Франшиза МойСклад"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.cavaleria.ru
APP_KEY=base64:...

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=moysklad_db
DB_USERNAME=moysklad_user
DB_PASSWORD=...

# Cache & Queue
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# МойСклад API
MOYSKLAD_APP_ID=uuid-приложения-из-лк
MOYSKLAD_SECRET_KEY=secret-key-из-лк
MOYSKLAD_API_URL=https://api.moysklad.ru/api/remap/1.2
MOYSKLAD_TIMEOUT=30
MOYSKLAD_RETRY_TIMES=3
```

### 5.2 Конфигурация МойСклад (config/moysklad.php)

```php
return [
    'app_id' => env('MOYSKLAD_APP_ID'),
    'secret_key' => env('MOYSKLAD_SECRET_KEY'),
    'api_url' => env('MOYSKLAD_API_URL', 'https://api.moysklad.ru/api/remap/1.2'),
    'timeout' => env('MOYSKLAD_TIMEOUT', 30),
    'retry_times' => env('MOYSKLAD_RETRY_TIMES', 3),
    'webhook_url' => env('APP_URL') . '/api/webhooks/moysklad',
];
```

---

## 6. DEPLOYMENT

### 6.1 Структура деплоя

**GitHub → GitHub Actions → SSH → Server → Deploy Script**

### 6.2 Deploy Script (deploy.sh)

```bash
#!/bin/bash
cd /var/www/app.cavaleria.ru

# Git
git config --global --add safe.directory /var/www/app.cavaleria.ru
git pull origin main

# Composer
composer install --optimize-autoloader --no-dev

# NPM
npm ci --production=false
npm run build

# Laravel
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart

# Permissions
sudo chown -R nginx:nginx .
sudo chmod -R 775 storage bootstrap/cache

# Restart
sudo systemctl restart php-fpm
```

### 6.3 GitHub Actions (.github/workflows/deploy.yml)

```yaml
name: Deploy to Production

on:
  push:
    branches: [ main ]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
    - name: Deploy via SSH
      uses: appleboy/ssh-action@master
      with:
        host: ${{ secrets.SERVER_HOST }}
        username: ${{ secrets.SERVER_USER }}
        key: ${{ secrets.SSH_PRIVATE_KEY }}
        script: |
          cd /var/www/app.cavaleria.ru
          ./deploy.sh
```

**GitHub Secrets:**
- `SERVER_HOST`: IP сервера
- `SERVER_USER`: пользователь SSH
- `SSH_PRIVATE_KEY`: приватный SSH ключ

---

## 7. КОМАНДЫ ДЛЯ РАБОТЫ

### 7.1 Основные команды Laravel

```bash
# Очистка кеша
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Кеширование для production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Миграции
php artisan migrate
php artisan migrate:rollback
php artisan migrate:fresh

# Tinker (консоль)
php artisan tinker

# Просмотр роутов
php artisan route:list

# Просмотр БД
php artisan db:show
```

### 7.2 Composer

```bash
# Установка зависимостей
composer install
composer install --no-dev  # Production

# Обновление
composer update

# Автозагрузка
composer dump-autoload
```

### 7.3 NPM

```bash
# Установка
npm install
npm ci  # Clean install для CI/CD

# Разработка
npm run dev

# Production сборка
npm run build
```

### 7.4 Git

```bash
# Статус
git status

# Коммит
git add .
git commit -m "message"

# Push
git push origin main

# Pull
git pull origin main

# Safe directory
git config --global --add safe.directory /var/www/app.cavaleria.ru
```

### 7.5 Системные команды

```bash
# Права доступа
sudo chown -R nginx:nginx /var/www/app.cavaleria.ru
sudo chmod -R 775 storage bootstrap/cache

# Перезапуск сервисов
sudo systemctl restart php-fpm
sudo systemctl restart nginx
sudo systemctl restart redis
sudo systemctl restart postgresql-18

# Просмотр логов
tail -f storage/logs/laravel.log
tail -f /var/log/nginx/app.cavaleria.ru-error.log
sudo journalctl -u php-fpm -f

# Проверка статуса
sudo systemctl status php-fpm
sudo systemctl status nginx
```

---

## 8. TROUBLESHOOTING

### 8.1 Permission denied ошибки

**Проблема:** Laravel не может писать в storage/logs

**Решение:**
```bash
sudo chown -R nginx:nginx /var/www/app.cavaleria.ru
sudo chmod -R 775 storage bootstrap/cache
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/app.cavaleria.ru/storage(/.*)?"
sudo restorecon -Rv /var/www/app.cavaleria.ru/storage/
```

### 8.2 Git: dubious ownership

**Проблема:** Git отказывается работать из-за владельца директории

**Решение:**
```bash
git config --global --add safe.directory /var/www/app.cavaleria.ru
```

### 8.3 Vite manifest not found

**Проблема:** Не собран фронтенд

**Решение:**
```bash
npm install
npm run build
ls -la public/build/manifest.json
```

### 8.4 Database connection failed

**Проблема:** Не подключается к PostgreSQL

**Решение:**
```bash
# Проверка статуса
sudo systemctl status postgresql-18

# Проверка подключения
psql -h localhost -U moysklad_user -d moysklad_db

# Проверка .env
cat .env | grep DB_
```

### 8.5 500 Internal Server Error

**Проблема:** Общая ошибка сервера

**Решение:**
```bash
# Смотрим логи
tail -100 storage/logs/laravel.log
tail -100 /var/log/nginx/app.cavaleria.ru-error.log

# Очищаем кеш
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# Проверяем права
ls -la storage/
ls -la bootstrap/cache/
```

---

## 9. ТЕСТИРОВАНИЕ

### 9.1 Тест API эндпоинтов

```bash
APP_ID="ваш-app-id"
ACCOUNT_ID="test-$(date +%s)"

# Установка
curl -X PUT "https://app.cavaleria.ru/api/moysklad/vendor/1.0/apps/${APP_ID}/${ACCOUNT_ID}" \
  -H "Content-Type: application/json" \
  -d '{
    "access_token": "test_token",
    "cause": "Install",
    "subscription": {
      "status": "Active",
      "tariff": {"name": "Главный", "price": 1000},
      "pricePerMonth": 1000
    }
  }'

# Статус
curl -X GET "https://app.cavaleria.ru/api/moysklad/vendor/1.0/apps/${APP_ID}/${ACCOUNT_ID}/status"

# Удаление
curl -X DELETE "https://app.cavaleria.ru/api/moysklad/vendor/1.0/apps/${APP_ID}/${ACCOUNT_ID}" \
  -H "Content-Type: application/json" \
  -d '{"cause": "Uninstall"}'
```

### 9.2 Тест базы данных

```bash
php artisan tinker
```

```php
// Проверка подключения
DB::connection()->getPdo();

// Просмотр аккаунтов
DB::table('accounts')->get();

// Создание тестовой записи
DB::table('accounts')->insert([
    'app_id' => 'test-app-id',
    'account_id' => 'test-account-id',
    'access_token' => 'test-token',
    'status' => 'activated',
    'created_at' => now(),
    'updated_at' => now()
]);

// Удаление тестовых данных
DB::table('accounts')->where('account_id', 'test-account-id')->delete();
```

---

## 10. СЛЕДУЮЩИЕ ШАГИ РАЗРАБОТКИ

### 10.1 Приоритет 1 (Core функциональность)

- [ ] **Vue компоненты для iframe интерфейса**
    - Дашборд главного аккаунта
    - Список дочерних аккаунтов
    - Настройки синхронизации
    - Журнал операций

- [ ] **Eloquent модели**
    - Account.php
    - ChildAccount.php
    - SyncSetting.php
    - SyncLog.php

- [ ] **Jobs для фоновой обработки**
    - SyncCatalogJob - синхронизация каталога
    - SyncOrdersJob - синхронизация заказов
    - SyncPricesJob - синхронизация цен
    - ProcessWebhookJob - обработка вебхуков

### 10.2 Приоритет 2 (Синхронизация)

- [ ] **Логика синхронизации товаров**
    - Сопоставление по артикулу/коду
    - Создание недостающих доп.полей
    - Синхронизация изображений
    - Обработка характеристик и комплектов

- [ ] **Логика синхронизации заказов**
    - Прием заказов от дочерних
    - Распределение заказов по условиям
    - Обновление статусов
    - Формирование заявок поставщику

- [ ] **Система вебхуков**
    - Автоматическое создание при установке
    - Обработка событий в реальном времени
    - Retry механизм при ошибках

### 10.3 Приоритет 3 (UI/UX)

- [ ] **Iframe интерфейс**
    - Использование UI Kit МойСклад
    - Адаптивный дизайн
    - Автоматическое масштабирование высоты

- [ ] **Аналитика и отчеты**
    - Дашборд с графиками
    - Динамика продаж
    - Остатки по всей сети
    - Экспорт в Excel

### 10.4 Приоритет 4 (Дополнительно)

- [ ] **Telegram уведомления**
    - Бот для уведомлений о синхронизации
    - Алерты при ошибках
    - Отчеты по запросу

- [ ] **Расширенная аналитика**
    - Предиктивная аналитика остатков
    - KPI по франшизам
    - Планирование закупок

- [ ] **Контроль цен**
    - Стандарты франшизы
    - Автоматическая корректировка

---

## 11. ПОЛЕЗНЫЕ ССЫЛКИ

### Документация

- [МойСклад JSON API 1.2](https://dev.moysklad.ru/doc/api/remap/1.2/)
- [МойСклад Vendor API 1.0](https://dev.moysklad.ru/doc/api/vendor/1.0/)
- [МойСклад Webhook API](https://dev.moysklad.ru/doc/api/remap/1.2/dictionaries/#suschnosti-vebhuki)
- [Laravel Documentation](https://laravel.com/docs/11.x)
- [Vue 3 Documentation](https://vuejs.org/guide/introduction.html)

### Инструменты

- [Личный кабинет разработчика МойСклад](https://apps.moysklad.ru/cabinet/)
- [UI Kit МойСклад](https://github.com/moysklad/html-marketplace-1.0-uikit)
- [Демо-приложение PHP](https://github.com/moysklad/php-dummyapp-marketplace-1.0)

### Сервер

- **URL:** https://app.cavaleria.ru
- **SSH:** `ssh user@server-ip`
- **Директория:** `/var/www/app.cavaleria.ru`
- **GitHub:** https://github.com/cavaleria-dev/multiaccount

---

## 12. КОНТАКТЫ И ПОДДЕРЖКА

### Команда разработки
- **GitHub:** cavaleria-dev
- **Репозиторий:** multiaccount

### Техническая поддержка МойСклад
- **Email:** apps@moysklad.ru
- **Telegram:** @moysklad_support

---

## CHANGELOG

### Version 1.0 (2025-10-13)
- ✅ Инициализация проекта
- ✅ Настройка сервера CentOS 9
- ✅ Установка PHP 8.4, PostgreSQL 18, Node.js 22
- ✅ Создание всех миграций БД
- ✅ Реализация Vendor API endpoints
- ✅ Создание MoySkladService
- ✅ Настройка автодеплоя через GitHub Actions
- ✅ SSL сертификат Let's Encrypt
- ⏳ В разработке: Vue интерфейс
- ⏳ В разработке: Логика синхронизации

---

**Последнее обновление:** 13 октября 2025  
**Статус проекта:** В разработке (Backend готов, Frontend в процессе)