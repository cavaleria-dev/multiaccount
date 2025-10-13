# Инструкция по деплою

## После git pull выполнить:

```bash
# 1. Обновить зависимости composer (не install, а update!)
composer update --no-dev --optimize-autoloader

# 2. Добавить в .env новые параметры (если их нет):
# MOYSKLAD_APP_UID=ваш-app-uid-из-личного-кабинета
# MOYSKLAD_VENDOR_API_URL=https://api.moysklad.ru/api/vendor/1.0

# 3. Очистить кеш Laravel
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# 4. Перезапустить веб-сервер (nginx/apache) если нужно
```

## Важно!

В этом коммите добавлена новая зависимость `firebase/php-jwt` в composer.json.
Поэтому нужно выполнить `composer update` вместо обычного `composer install`.

После первого успешного деплоя composer.lock будет обновлён и можно будет использовать `composer install` как обычно.
