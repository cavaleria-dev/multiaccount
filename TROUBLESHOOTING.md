# Диагностика и решение проблем

## Проблема: Логи не обновляются

### 1. Проверить права доступа к папке логов

```bash
cd /var/www/app.cavaleria.ru
ls -la storage/logs/

# Должны видеть что-то вроде:
# -rw-r--r-- 1 nginx nginx 12345 Oct 13 15:30 laravel.log
```

### 2. Если прав нет - исправить

```bash
sudo chown -R nginx:nginx storage/logs
sudo chmod -R 775 storage/logs
```

### 3. Проверить что Laravel пишет логи

```bash
# Временно включить debug режим
php artisan tinker
>>> Log::info('Test log message');
>>> exit

# Проверить появилось ли сообщение
tail -5 storage/logs/laravel.log
```

### 4. Проверить настройки логирования

```bash
# Посмотреть текущий канал логирования
php artisan tinker
>>> config('logging.default');
>>> exit

# Должно быть 'stack' или 'single'
```

### 5. Очистить кеш конфигурации

```bash
php artisan config:clear
php artisan cache:clear
```

### 6. Проверить размер лог файла

```bash
# Если файл слишком большой, он может не обновляться быстро
ls -lh storage/logs/laravel.log

# Если больше 100MB - стоит очистить
# ВНИМАНИЕ: это удалит все старые логи!
> storage/logs/laravel.log
```

## Просмотр логов от МойСклад

### Смотреть логи в реальном времени

```bash
# Все логи
tail -f storage/logs/laravel.log

# Только от МойСклад
tail -f storage/logs/laravel.log | grep "МойСклад"

# Только запросы установки/удаления
tail -f storage/logs/laravel.log | grep -E "(install|uninstall|Запрос)"
```

### Поиск конкретных событий

```bash
# Все запросы установки
grep "Запрос установки" storage/logs/laravel.log

# Все запросы удаления
grep "Запрос удаления" storage/logs/laravel.log

# Последние 10 запросов от МойСклад
grep "МойСклад" storage/logs/laravel.log | tail -10

# Ошибки
grep "ERROR" storage/logs/laravel.log | tail -20
```

### Просмотр логов деплоя

```bash
# Последний деплой
tail -50 storage/logs/deploy.log

# В реальном времени
tail -f storage/logs/deploy.log
```

## Проблема: DELETE запрос возвращает 405 (Method Not Allowed)

### 1. Проверить что роуты зарегистрированы

```bash
php artisan route:list | grep moysklad
```

Должны увидеть:
```
DELETE  api/moysklad/vendor/1.0/apps/{appId}/{accountId} ... MoySkladController@uninstall
PUT     api/moysklad/vendor/1.0/apps/{appId}/{accountId} ... MoySkladController@install
GET     api/moysklad/vendor/1.0/apps/{appId}/{accountId}/status ... MoySkladController@status
```

### 2. Очистить кеш роутов

```bash
php artisan route:clear
php artisan route:cache
```

### 3. Проверить nginx конфигурацию

```bash
# Найти конфиг для вашего сайта
cat /etc/nginx/sites-available/app.cavaleria.ru | grep -A 5 location

# Убедиться что нет ограничений на методы:
# НЕ должно быть строк типа:
# limit_except GET POST { deny all; }
```

### 4. Перезапустить PHP-FPM

```bash
sudo systemctl restart php-fpm
# или
sudo systemctl restart php8.2-fpm  # замените на вашу версию
```

## Проблема: Изменения не применяются после деплоя

### 1. Принудительный деплой

```bash
./deploy.sh --force
```

### 2. Ручная очистка всех кешей

```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan optimize:clear
```

### 3. Проверить что код обновился

```bash
git log -1 --oneline
# Должен показать последний коммит

git status
# Должно быть: nothing to commit, working tree clean
```

## Проверка timezone

### В Laravel

```bash
php artisan tinker
>>> now();
>>> exit
```

Должно показать московское время.

### В системе

```bash
date
# Должно быть МСК (GMT+3)

timedatectl
# Должно показать Europe/Moscow
```

## Полезные команды для мониторинга

```bash
# Размер лог файлов
du -sh storage/logs/*

# Последние ошибки PHP
tail -50 /var/log/php-fpm/www-error.log

# Статус сервисов
sudo systemctl status nginx
sudo systemctl status php-fpm

# Проверить что приложение доступно
curl -I https://app.cavaleria.ru

# Проверить API endpoint
curl -X GET https://app.cavaleria.ru/api/moysklad/vendor/1.0/apps/test/test/status
```

## Настройка ротации логов

Если логи растут слишком быстро, настройте logrotate:

```bash
sudo nano /etc/logrotate.d/laravel

# Добавить:
/var/www/app.cavaleria.ru/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    missingok
    create 0664 nginx nginx
}
```
