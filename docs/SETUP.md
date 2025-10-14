# Инструкция по настройке и запуску

## Настройка планировщика (Scheduler)

Для автоматической обработки очереди синхронизации необходимо настроить Laravel Scheduler.

### 1. Настройка Cron (Production)

Добавьте следующую строку в crontab:

```bash
# Открыть crontab для редактирования
crontab -e

# Добавить строку (замените путь на свой)
* * * * * cd /var/www/app.cavaleria.ru/multiaccount && php artisan schedule:run >> /dev/null 2>&1
```

Эта команда будет запускать планировщик каждую минуту, который в свою очередь запустит `ProcessSyncQueueJob`.

### 2. Проверка работы планировщика

```bash
# Просмотр расписания задач
php artisan schedule:list

# Ручной запуск планировщика (для тестирования)
php artisan schedule:run

# Ручной запуск job (для отладки)
php artisan tinker
>>> dispatch(new \App\Jobs\ProcessSyncQueueJob);
```

### 3. Настройка для разработки

В режиме разработки можно использовать команду `schedule:work`, которая будет запускать планировщик каждую минуту автоматически:

```bash
php artisan schedule:work
```

## Настройка очередей (Queues)

Система использует таблицу `sync_queue` для управления задачами синхронизации. Laravel Scheduler автоматически обрабатывает эту очередь через `ProcessSyncQueueJob`.

### Проверка очереди

```bash
# Просмотр задач в очереди
php artisan tinker
>>> \App\Models\SyncQueue::where('status', 'pending')->count();
>>> \App\Models\SyncQueue::where('status', 'processing')->get();
>>> \App\Models\SyncQueue::where('status', 'failed')->get();
```

## Настройка вебхуков

### 1. Автоматическая настройка при установке приложения

Вебхуки создаются автоматически при установке приложения через `WebhookService`:

- **Главный аккаунт**: product, variant, bundle (CREATE, UPDATE, DELETE)
- **Дочерний аккаунт**: customerorder, retaildemand, purchaseorder (UPDATE)

### 2. Ручная настройка вебхуков

```bash
php artisan tinker

# Настроить вебхуки для главного аккаунта
>>> $service = app(\App\Services\WebhookService::class);
>>> $service->setupWebhooks('account-id-here', 'main');

# Настроить вебхуки для дочернего аккаунта
>>> $service->setupWebhooks('account-id-here', 'child');
```

### 3. Проверка здоровья вебхуков

Вебхуки автоматически проверяются через задачи в очереди. Можно также проверить вручную:

```bash
php artisan tinker
>>> $service = app(\App\Services\WebhookService::class);
>>> $service->checkWebhookHealth('account-id-here');
>>> $service->getWebhookStatus('account-id-here');
```

## Тестирование синхронизации

### 1. Синхронизация товара

```bash
php artisan tinker

# Синхронизировать товар из главного во все дочерние
>>> $service = app(\App\Services\BatchSyncService::class);
>>> $service->batchSyncProduct('main-account-id', 'product-id');

# Проверить статус в очереди
>>> \App\Models\SyncQueue::where('entity_type', 'product')->latest()->get();
```

### 2. Синхронизация заказа

Заказы синхронизируются автоматически при получении вебхука. Для ручного тестирования:

```bash
php artisan tinker

# Синхронизировать заказ из дочернего в главный
>>> $service = app(\App\Services\CustomerOrderSyncService::class);
>>> $service->syncCustomerOrder('child-account-id', 'order-id');
```

### 3. Просмотр статистики

```bash
php artisan tinker

>>> $service = app(\App\Services\SyncStatisticsService::class);

# Статистика по всем франшизам за последние 7 дней
>>> $service->getFranchiseStats('parent-account-id', 7);

# Агрегированная статистика за последние 30 дней
>>> $service->getAggregatedStats('parent-account-id', 30);

# Проверка здоровья синхронизации (порог ошибок 20%)
>>> $service->checkSyncHealth('parent-account-id', 20.0);
```

## Мониторинг и отладка

### Просмотр логов

```bash
# Основные логи
tail -f storage/logs/laravel.log

# Логи синхронизации (grep для фильтрации)
tail -f storage/logs/laravel.log | grep -E "sync|webhook|product"

# Логи ошибок
tail -f storage/logs/laravel.log | grep ERROR
```

### Очистка старых логов

```bash
# Удалить логи старше 7 дней
find storage/logs -name "*.log" -mtime +7 -delete

# Очистить текущий лог
echo "" > storage/logs/laravel.log
```

### Проверка Rate Limits

Rate limit информация записывается в логи при превышении лимитов:

```bash
tail -f storage/logs/laravel.log | grep "Rate Limit"
```

## Команды для работы с Redis

```bash
# Подключиться к Redis
redis-cli

# Просмотр всех ключей
KEYS *

# Просмотр информации
INFO

# Очистка всех данных (осторожно!)
FLUSHALL
```

## Миграции

```bash
# Применить все миграции
php artisan migrate

# Откатить последнюю миграцию
php artisan migrate:rollback

# Откатить все миграции и применить заново (ВНИМАНИЕ: удалит все данные!)
php artisan migrate:fresh

# Проверить статус миграций
php artisan migrate:status
```

## Troubleshooting

### Проблема: Задачи не обрабатываются

1. Проверьте что cron настроен и работает:
   ```bash
   crontab -l
   ```

2. Проверьте логи cron:
   ```bash
   tail -f /var/log/cron
   ```

3. Запустите планировщик вручную:
   ```bash
   php artisan schedule:run
   ```

### Проблема: Вебхуки не приходят

1. Проверьте что вебхуки созданы в МойСклад:
   ```bash
   php artisan tinker
   >>> $service = app(\App\Services\WebhookService::class);
   >>> $service->getWebhookStatus('account-id');
   ```

2. Проверьте доступность URL для МойСклад:
   - URL должен быть доступен извне
   - SSL сертификат должен быть валидным
   - Порт 443 должен быть открыт

3. Проверьте логи входящих запросов:
   ```bash
   tail -f storage/logs/laravel.log | grep "Webhook received"
   ```

### Проблема: Rate Limit превышен

Система автоматически обрабатывает rate limit и переносит задачи. Проверьте:

```bash
# Задачи отложенные из-за rate limit
php artisan tinker
>>> \App\Models\SyncQueue::where('error', 'Rate limit exceeded')->get();
```

### Проблема: Синхронизация застряла

```bash
# Найти застрявшие задачи (processing более 5 минут)
php artisan tinker
>>> $stuck = \App\Models\SyncQueue::where('status', 'processing')
        ->where('started_at', '<', now()->subMinutes(5))
        ->get();

# Сбросить их в pending
>>> foreach ($stuck as $task) {
        $task->update(['status' => 'pending', 'started_at' => null]);
    }
```

## Полезные команды Artisan

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

# Оптимизация автозагрузки
composer dump-autoload -o

# Генерация ключа приложения
php artisan key:generate

# Просмотр всех роутов
php artisan route:list

# Просмотр всех событий планировщика
php artisan schedule:list
```

## Мониторинг производительности

### Статистика базы данных

```bash
php artisan tinker

# Количество задач в очереди по статусам
>>> \DB::table('sync_queue')
    ->select('status', \DB::raw('count(*) as count'))
    ->groupBy('status')
    ->get();

# Средняя продолжительность синхронизации
>>> \DB::table('sync_statistics')
    ->where('created_at', '>=', now()->subDays(7))
    ->avg('avg_duration_ms');

# Топ 10 франшиз по количеству ошибок
>>> \DB::table('sync_statistics')
    ->where('failed_count', '>', 0)
    ->orderBy('failed_count', 'desc')
    ->limit(10)
    ->get();
```

## Резервное копирование

### Backup базы данных

```bash
# PostgreSQL backup
pg_dump -U moysklad_user -h localhost moysklad_db > backup_$(date +%Y%m%d_%H%M%S).sql

# Восстановление
psql -U moysklad_user -h localhost moysklad_db < backup_20251014_120000.sql
```

### Backup файлов

```bash
# Архивировать код приложения
tar -czf multiaccount_backup_$(date +%Y%m%d).tar.gz \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='storage/logs/*' \
  /var/www/app.cavaleria.ru/multiaccount
```
