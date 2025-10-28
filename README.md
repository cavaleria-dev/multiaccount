# Франшиза-синхронизация МойСклад

[![Deploy Status](https://github.com/cavaleria-dev/multiaccount/actions/workflows/deploy.yml/badge.svg)](https://github.com/cavaleria-dev/multiaccount/actions)

Laravel-приложение для управления сетью дочерних аккаунтов МойСклад с автоматической синхронизацией данных между главным и дочерними аккаунтами франшизной сети. Работает как iframe-приложение внутри интерфейса МойСклад.

**Основная задача:** Централизованное управление каталогом товаров, ценами, характеристиками и заказами для франшизных сетей с минимальной нагрузкой на API МойСклад.

## ✨ Ключевые возможности

- 🚀 **97% меньше API запросов** - Batch optimization для товаров/услуг ([подробнее](docs/04-batch-sync.md))
- 📁 **95% меньше папок синхронизируется** - Filtered folder sync ([подробнее](docs/14-product-folder-sync.md))
- 🔄 **Проактивная синхронизация** - Характеристики и изображения предзагружаются автоматически
- 🧩 **Модульная архитектура** - 13 sync handlers (76% меньше кода) ([подробнее](docs/16-sync-handlers.md))
- 📊 **Production-ready** - Мониторинг API, cleanup команды, детальные логи
- 🏢 **Franchise management** - Главный аккаунт → Дочерние аккаунты (одностороннее)
- 🔐 **МойСклад iframe integration** - Контекст-based аутентификация (30min cache)

## 🛠 Технологии

**Backend:**
- PHP 8.4
- Laravel 12
- PostgreSQL 18
- Redis 7
- Supervisor (queue worker)

**Frontend:**
- Vue 3.5
- Vite 7
- Tailwind CSS 3.4
- Vue Router 4

**Интеграции:**
- МойСклад JSON API 1.2
- МойСклад Vendor API 1.0
- МойСклад Webhook API

## 📚 Документация

Полная документация разбита на модули для удобства:

### 🚀 Для начала работы

- **[CLAUDE.md](CLAUDE.md)** - Quick reference для разработки (AI-ассистенты)
- **[Getting Started](docs/01-getting-started.md)** - Установка, команды, деплой
- **[Architecture Overview](docs/03-architecture.md)** - Обзор архитектуры синхронизации

### ⚡ Оптимизации

- **[Batch Synchronization](docs/04-batch-sync.md)** ⭐ - 97% меньше API запросов
- **[Product Folder Sync](docs/14-product-folder-sync.md)** ⭐ - 95% меньше папок
- **[Image Synchronization](docs/13-image-sync.md)** - Batch upload оптимизация
- **[Characteristic Sync](docs/15-characteristic-sync.md)** - Проактивная синхронизация
- **[Variant Assortment Sync](docs/17-variant-assortment-sync.md)** - Unified API endpoint

### 🔧 Технические детали

- **[Service Layer](docs/05-services.md)** - Архитектура сервисов (34 сервиса)
- **[Database Structure](docs/07-database.md)** - Таблицы и маппинги (17 моделей, 38 миграций)
- **[Queue & Supervisor](docs/02-queue-supervisor.md)** - Queue system architecture
- **[Sync Task Handlers](docs/16-sync-handlers.md)** - Модульные обработчики (76% код-reduction)
- **[API Endpoints](docs/08-api-endpoints.md)** - REST API reference
- **[Frontend Architecture](docs/06-frontend.md)** - Vue 3 компоненты
- **[Admin Monitoring](docs/11-admin-monitoring.md)** - API monitoring system

### 📖 Остальное

- **[Coding Standards](docs/09-coding-standards.md)** - PHP/Laravel + Vue 3 конвенции
- **[Common Patterns & Gotchas](docs/10-common-patterns.md)** - Best practices
- **[Configuration](docs/12-configuration.md)** - Environment setup
- **[Known Issues](docs/KNOWN_ISSUES.md)** - Решенные проблемы
- **[Product Filters](docs/PRODUCT_FILTERS.md)** - UI конструктор фильтров

## ⚡ Быстрый старт

```bash
# 1. Клонировать репозиторий
git clone git@github.com:cavaleria-dev/multiaccount.git
cd multiaccount

# 2. Установить зависимости
composer install
npm install

# 3. Настроить окружение
cp .env.example .env
php artisan key:generate
# Отредактируйте .env (DB, МойСклад API credentials)

# 4. Миграции
php artisan migrate

# 5. Запустить для разработки
npm run dev             # Frontend (local machine)
# Backend запускается ТОЛЬКО на сервере (no local PHP environment)
```

📖 **Подробная инструкция:** [docs/01-getting-started.md](docs/01-getting-started.md)

## 🏗 Архитектура

### Основные компоненты

- **МойСклад iframe app** - Работает внутри интерфейса МойСклад (context-based auth)
- **Batch optimization** - 97% меньше API запросов через пакетную обработку ([подробнее](docs/04-batch-sync.md))
- **Queue system** - Supervisor + ProcessSyncQueueJob (50 задач/минуту)
- **Modular handlers** - 13 sync task handlers (76% меньше дублирующегося кода)
- **Context caching** - 30-минутный кеш для МойСклад authentication context

### Направления синхронизации

**Главный → Дочерние аккаунты:**
- Товары, модификации, комплекты, услуги (products, variants, bundles, services)
- Атрибуты, характеристики, цены, штрихкоды, упаковки
- Папки товаров (только те, где есть отфильтрованные товары)
- Изображения (batch upload optimization)
- Кастомные справочники (customentity)

**Дочерние → Главный аккаунт:**
- Заказы покупателей (customerorder)
- Розничные продажи (retaildemand)
- Заказы поставщикам (purchaseorder, проведенные only)
- **Немедленная синхронизация** (без очереди, time-sensitive)

📖 **Подробная архитектура:** [docs/03-architecture.md](docs/03-architecture.md)

## 🗂 Структура проекта

```
├── app/
│   ├── Http/Controllers/       # API контроллеры (Vendor API, Webhooks, Context)
│   ├── Services/               # 34 сервиса (sync, monitoring, rate limit)
│   ├── Models/                 # 17 моделей (accounts, mappings, statistics)
│   ├── Console/Commands/       # 7 artisan команд (cleanup, encrypt, admin)
│   └── Jobs/                   # ProcessSyncQueueJob (queue worker)
├── database/migrations/        # 38 миграций (schema + data fixes)
├── docs/                       # 17 модулей документации
├── resources/js/               # Vue 3 компоненты (Composition API)
├── routes/                     # API + Web маршруты
├── deploy.sh                   # Production deployment script
├── monitor-queue.sh            # Queue monitoring script
└── restart-queue.sh            # Queue worker restart
```

📖 **Детали:**
- Сервисы: [docs/05-services.md](docs/05-services.md)
- База данных: [docs/07-database.md](docs/07-database.md)
- Frontend: [docs/06-frontend.md](docs/06-frontend.md)

## 🚀 Деплой на production

```bash
# На сервере
cd /var/www/app.cavaleria.ru
./deploy.sh

# Мониторинг
./monitor-queue.sh               # Queue status
tail -f storage/logs/laravel.log # Application logs

# Admin panel
open https://app.cavaleria.ru/admin/logs
```

📖 **Подробнее:** [docs/01-getting-started.md#деплой](docs/01-getting-started.md)

## 🔧 Полезные команды

### Синхронизация

```bash
# Queue worker (Supervisor автоматически перезапускает)
php artisan queue:work

# Перезапуск worker после deploy
./restart-queue.sh
```

### Cleanup команды

```bash
# Очистка устаревших маппингов характеристик
php artisan sync:cleanup-stale-characteristic-mappings --dry-run
php artisan sync:cleanup-stale-characteristic-mappings

# Очистка дубликатов папок товаров
php artisan sync:cleanup-duplicate-folders --dry-run
php artisan sync:cleanup-duplicate-folders --fix

# Шифрование plaintext токенов
php artisan accounts:encrypt-tokens --dry-run
php artisan accounts:encrypt-tokens

# Очистка временных изображений
php artisan sync:cleanup-temp-images
```

### Мониторинг

```bash
# Статус очереди
./monitor-queue.sh

# Логи синхронизации
tail -f storage/logs/laravel.log | grep -E "Batch|sync"

# Детальные API логи (REQUEST/RESPONSE)
tail -f storage/logs/sync.log

# Проверка планировщика
./check-scheduler.sh
```

📖 **Полный список:** [docs/01-getting-started.md#команды](docs/01-getting-started.md)

## 📊 Production-ready возможности

**Мониторинг и логирование:**
- ✅ API monitoring - Request/Response логи для отладки ([docs/11](docs/11-admin-monitoring.md))
- ✅ Rate limit handling - Автоматические задержки при лимитах API
- ✅ Queue monitoring - Health checks через `monitor-queue.sh`
- ✅ Webhook health tracking - Отслеживание статуса вебхуков
- ✅ Sync statistics - Ежедневная аналитика успешных/неудачных операций

**Безопасность и надежность:**
- ✅ Access token encryption - Автоматическое шифрование через `encrypted` cast
- ✅ Mass assignment protection - `access_token` не в `$fillable`
- ✅ Context caching - 30-минутный кеш для аутентификации
- ✅ Retry mechanism - Exponential backoff при ошибках (3 попытки)

**Maintenance:**
- ✅ Cleanup команды - Удаление устаревших маппингов, дубликатов, temp файлов
- ✅ Supervisor integration - Автоматический перезапуск queue worker
- ✅ Migration safety - Все миграции с проверкой существования + rollback

📖 **Подробнее:** [docs/11-admin-monitoring.md](docs/11-admin-monitoring.md)

## 🔗 Полезные ссылки

**МойСклад API:**
- [МойСклад JSON API 1.2](https://dev.moysklad.ru/doc/api/remap/1.2/)
- [МойСклад Vendor API 1.0](https://dev.moysklad.ru/doc/api/vendor/1.0/)
- [Личный кабинет разработчика](https://apps.moysklad.ru/cabinet/)

**Технологии:**
- [Laravel 12 Documentation](https://laravel.com/docs/12.x)
- [Vue 3 Documentation](https://vuejs.org/)
- [Tailwind CSS Documentation](https://tailwindcss.com/docs)

## 📄 Лицензия

Этот проект является проприетарным программным обеспечением.

## 📞 Контакты

**GitHub:** [cavaleria-dev](https://github.com/cavaleria-dev)
**Email:** support@cavaleria.ru
**Production:** https://app.cavaleria.ru

---

**Version:** 1.0.0
**Last Update:** 28.10.2025
**Status:** В разработке
