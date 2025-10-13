# AI Агент - Шпаргалка команд

## 🚨 КРИТИЧЕСКИ ВАЖНО

### Всегда помнить:
1. **Владелец файлов:** nginx:nginx
2. **Права storage:** 775
3. **После git pull:** восстановить права
4. **После изменения .env:** `php artisan config:cache`
5. **Git safe directory:** `/var/www/app.cavaleria.ru`

### Никогда не делать:
- ❌ Не коммитить `.env`
- ❌ Не коммитить `vendor/`
- ❌ Не коммитить `node_modules/`
- ❌ Не коммитить `public/build/`
- ❌ Не использовать `localStorage` в артефактах

---

## 📍 БАЗОВАЯ ИНФОРМАЦИЯ

```
Проект:    Франшиза-синхронизация МойСклад
Домен:     https://app.cavaleria.ru
Путь:      /var/www/app.cavaleria.ru
GitHub:    https://github.com/cavaleria-dev/multiaccount
Ветка:     main
```

---

## ⚡ БЫСТРЫЕ КОМАНДЫ

### Подключение к серверу
```bash
ssh user@server-ip
cd /var/www/app.cavaleria.ru
```

### Деплой вручную
```bash
cd /var/www/app.cavaleria.ru
./deploy.sh
```

### Проверка статуса
```bash
# Сервисы
sudo systemctl status php-fpm nginx postgresql-18 redis

# Логи
tail -f storage/logs/laravel.log
tail -f /var/log/nginx/app.cavaleria.ru-error.log
```

### Исправление прав (частая проблема!)
```bash
cd /var/www/app.cavaleria.ru
sudo chown -R nginx:nginx .
sudo chmod -R 775 storage bootstrap/cache
```

---

## 🔧 РАБОТА С КОДОМ

### Git операции
```bash
# Safe directory (если ошибка)
git config --global --add safe.directory /var/www/app.cavaleria.ru

# Стандартный workflow
git status
git add .
git commit -m "Описание изменений"
git push origin main
```

### Laravel команды
```bash
# Очистка (после изменений)
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# Кеширование (для production)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Миграции
php artisan migrate
php artisan migrate:fresh  # ВНИМАНИЕ: удаляет все данные!

# Проверка роутов
php artisan route:list | grep moysklad
```

### Composer
```bash
# Установка
composer install --no-dev  # Production
composer install           # Development

# После добавления зависимости
composer require package/name
composer dump-autoload
```

### NPM (Frontend)
```bash
# Установка
npm ci  # Для CI/CD
npm install  # Для разработки

# Сборка
npm run dev    # Development (с hot reload)
npm run build  # Production

# Проверка
ls -la public/build/manifest.json
```

---

## 🗄️ РАБОТА С БАЗОЙ ДАННЫХ

### Подключение
```bash
# Через psql
psql -h localhost -U moysklad_user -d moysklad_db

# Через tinker
php artisan tinker
>>> DB::connection()->getPdo();
>>> DB::table('accounts')->count();
```

### Полезные SQL запросы
```sql
-- Все аккаунты
SELECT * FROM accounts;

-- Активные аккаунты
SELECT * FROM accounts WHERE status = 'activated';

-- Связи главный-дочерний
SELECT 
    p.account_id as parent,
    c.account_id as child
FROM child_accounts ca
JOIN accounts p ON p.account_id = ca.parent_account_id
JOIN accounts c ON c.account_id = ca.child_account_id;

-- Последние логи синхронизации
SELECT * FROM sync_logs ORDER BY created_at DESC LIMIT 10;
```

---

## 🧪 ТЕСТИРОВАНИЕ

### Тест Vendor API
```bash
APP_ID="замените-на-реальный"
ACCOUNT_ID="test-$(date +%s)"

# Установка
curl -X PUT "https://app.cavaleria.ru/api/moysklad/vendor/1.0/apps/${APP_ID}/${ACCOUNT_ID}" \
  -H "Content-Type: application/json" \
  -d '{"access_token":"test","cause":"Install","subscription":{"status":"Active","tariff":{"name":"Test","price":100},"pricePerMonth":100}}'

# Статус
curl "https://app.cavaleria.ru/api/moysklad/vendor/1.0/apps/${APP_ID}/${ACCOUNT_ID}/status"
```

### Тест с реальными данными
```bash
php artisan tinker
```
```php
// Создать тестовый аккаунт
$service = app(\App\Services\MoySkladService::class);
$service->setAccessToken('real-token-here');

// Получить товары
$products = $service->getProducts(['limit' => 10]);
print_r($products);

// Получить вебхуки
$webhooks = $service->getWebhooks();
print_r($webhooks);
```

---

## 🔍 ДИАГНОСТИКА ПРОБЛЕМ

### Проблема: 500 Error
```bash
# 1. Смотрим логи
tail -100 storage/logs/laravel.log

# 2. Права доступа
sudo chown -R nginx:nginx .
sudo chmod -R 775 storage bootstrap/cache

# 3. SELinux (если нужно)
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/app.cavaleria.ru/storage(/.*)?"
sudo restorecon -Rv storage/

# 4. Очистка кеша
php artisan cache:clear
php artisan config:clear
```

### Проблема: Vite manifest not found
```bash
# Пересоборка
rm -rf public/build
npm install
npm run build
ls -la public/build/manifest.json  # должен существовать
```

### Проблема: Database connection
```bash
# Проверка PostgreSQL
sudo systemctl status postgresql-18
sudo systemctl restart postgresql-18

# Проверка .env
cat .env | grep DB_

# Тест подключения
psql -h localhost -U moysklad_user -d moysklad_db
```

### Проблема: Git refuses to work
```bash
# Добавить в safe
git config --global --add safe.directory /var/www/app.cavaleria.ru

# Или временно сменить владельца
sudo chown -R $USER:$USER .
git pull
sudo chown -R nginx:nginx .
```

---

## 📝 СОЗДАНИЕ НОВЫХ ФАЙЛОВ

### Создать контроллер
```bash
php artisan make:controller Api/ИмяController
```

### Создать миграцию
```bash
php artisan make:migration create_название_table
# Редактируем файл в database/migrations/
php artisan migrate
```

### Создать Job
```bash
php artisan make:job НазваниеJob
# Файл в app/Jobs/
```

### Создать Model
```bash
php artisan make:model НазваниеModel -m  # с миграцией
```

### Создать Vue компонент
```bash
# Вручную создаём в resources/js/components/
nano resources/js/components/НазваниеКомпонента.vue
```

---

## 🔐 БЕЗОПАСНОСТЬ

### Переменные окружения
```bash
# Редактирование .env
nano .env

# После изменения ОБЯЗАТЕЛЬНО:
php artisan config:cache
sudo systemctl restart php-fpm
```

### Получить appId и secretKey
1. https://apps.moysklad.ru/cabinet/
2. Черновик решения → Редактировать
3. Скопировать значения

### SSL сертификат
```bash
# Проверка срока действия
sudo certbot certificates

# Обновление вручную
sudo certbot renew

# Автообновление (должно быть настроено)
sudo systemctl status certbot-renew.timer
```

---

## 📊 МОНИТОРИНГ

### Использование ресурсов
```bash
# CPU и память
top
htop

# Диск
df -h
du -sh /var/www/app.cavaleria.ru/*

# Логи размер
du -sh /var/log/nginx/
du -sh storage/logs/
```

### Активные подключения
```bash
# PHP-FPM процессы
ps aux | grep php-fpm

# Nginx connections
ss -tlnp | grep :80
ss -tlnp | grep :443

# PostgreSQL connections
sudo -u postgres psql -c "SELECT count(*) FROM pg_stat_activity;"
```

---

## 📦 BACKUP

### Создать бэкап БД
```bash
# PostgreSQL dump
sudo -u postgres pg_dump moysklad_db > backup_$(date +%Y%m%d_%H%M%S).sql

# Сжать
gzip backup_*.sql
```

### Восстановить из бэкапа
```bash
# Распаковать
gunzip backup_file.sql.gz

# Восстановить
sudo -u postgres psql moysklad_db < backup_file.sql
```

### Backup файлов
```bash
# Создать архив
tar -czf backup_files_$(date +%Y%m%d).tar.gz \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='storage/logs' \
  /var/www/app.cavaleria.ru/
```

---

## 🎯 ЧАСТЫЕ ЗАДАЧИ

### Добавить новый API endpoint
1. Добавить метод в контроллер
2. Зарегистрировать роут в `routes/api.php`
3. `php artisan route:clear`
4. Протестировать через curl

### Добавить новое поле в таблицу
1. `php artisan make:migration add_field_to_table`
2. Редактировать миграцию
3. `php artisan migrate`
4. Обновить модель (если есть)

### Обновить фронтенд
1. Внести изменения в `.vue` файлы
2. `npm run build`
3. Проверить `public/build/manifest.json`
4. Коммит и push

### Добавить новую зависимость PHP
```bash
composer require vendor/package
composer dump-autoload
git add composer.json composer.lock
git commit -m "Add package"
git push
```

### Добавить новую зависимость JS
```bash
npm install package-name
git add package.json package-lock.json
git commit -m "Add package"
git push
```

---

## 🚀 PRODUCTION CHECKLIST

Перед деплоем проверить:
- [ ] `.env` настроен правильно
- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production`
- [ ] Миграции прошли успешно
- [ ] `npm run build` выполнен
- [ ] Права доступа установлены
- [ ] SSL сертификат активен
- [ ] Логи не содержат критических ошибок

---

## 📞 КОНТАКТЫ

**МойСклад поддержка:** apps@moysklad.ru  
**Документация:** https://dev.moysklad.ru/  
**GitHub Issues:** https://github.com/cavaleria-dev/multiaccount/issues

---

**Последнее обновление:** 13.10.2025