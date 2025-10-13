# Франшиза-синхронизация МойСклад

[![Deploy Status](https://github.com/cavaleria-dev/multiaccount/actions/workflows/deploy.yml/badge.svg)](https://github.com/cavaleria-dev/multiaccount/actions)

Приложение для управления сетью дочерних аккаунтов МойСклад с автоматической синхронизацией данных между главным и дочерними аккаунтами франшизной сети.

## 🚀 Возможности

- **Централизованное управление каталогом** - единый каталог товаров для всей сети
- **Автоматическая синхронизация** - товары, цены, остатки, заказы
- **Управление дочерними аккаунтами** - подключение и настройка через единый интерфейс
- **Распределение заказов** - автоматическое распределение по условиям
- **Аналитика и отчетность** - единая аналитика по всей сети с экспортом в Excel
- **Вебхуки в реальном времени** - мгновенное обновление данных

## 🛠 Технологии

**Backend:**
- PHP 8.4
- Laravel 11.x
- PostgreSQL 18
- Redis 7.x

**Frontend:**
- Vue 3
- Vite 5
- Tailwind CSS 3

**Интеграции:**
- МойСклад JSON API 1.2
- МойСклад Vendor API 1.0
- МойСклад Webhook API

## 📋 Требования

- PHP 8.4+
- PostgreSQL 18+
- Redis 7+
- Node.js 22+
- Composer 2.x
- Nginx

## ⚡ Быстрая установка

### 1. Клонирование репозитория

```bash
git clone git@github.com:cavaleria-dev/multiaccount.git
cd multiaccount
```

### 2. Установка зависимостей

```bash
# PHP зависимости
composer install

# JS зависимости
npm install
```

### 3. Настройка окружения

```bash
# Копировать файл конфигурации
cp .env.example .env

# Сгенерировать ключ приложения
php artisan key:generate

# Отредактировать .env
nano .env
```

Заполните следующие переменные:

```env
# Database
DB_DATABASE=moysklad_db
DB_USERNAME=moysklad_user
DB_PASSWORD=your_password

# МойСклад API
MOYSKLAD_APP_ID=your-app-id
MOYSKLAD_SECRET_KEY=your-secret-key
```

### 4. Миграции базы данных

```bash
php artisan migrate
```

### 5. Сборка фронтенда

```bash
npm run build
```

### 6. Запуск

```bash
# Для разработки
php artisan serve
npm run dev  # В отдельном терминале

# Для production
# Настройте веб-сервер (Nginx/Apache) на папку public/
```

## 📚 Документация

### Структура проекта

```
├── app/
│   ├── Http/Controllers/Api/
│   │   ├── MoySkladController.php      # Vendor API endpoints
│   │   └── WebhookController.php       # Обработка вебхуков
│   └── Services/
│       └── MoySkladService.php         # Сервис для работы с API
├── config/
│   └── moysklad.php                    # Конфигурация МойСклад
├── database/migrations/                # Миграции БД
├── resources/
│   ├── js/                             # Vue компоненты
│   └── views/                          # Blade шаблоны
└── routes/
    ├── api.php                         # API маршруты
    └── web.php                         # Web маршруты
```

### API Endpoints

#### Vendor API (для МойСклад)

```
PUT    /api/moysklad/vendor/1.0/apps/{appId}/{accountId}       # Установка
DELETE /api/moysklad/vendor/1.0/apps/{appId}/{accountId}       # Удаление
GET    /api/moysklad/vendor/1.0/apps/{appId}/{accountId}/status # Статус
```

#### Internal API

```
POST /api/apps/update-status            # Обновление статуса из iframe
POST /api/webhooks/moysklad             # Обработка вебхуков
GET  /api/context/{contextKey}          # Получение контекста пользователя
```

### База данных

**Основные таблицы:**

- `accounts` - Установленные приложения
- `child_accounts` - Связи главный↔дочерний
- `sync_settings` - Настройки синхронизации
- `sync_logs` - Журнал операций
- `entity_mappings` - Сопоставление ID сущностей
- `webhooks` - Зарегистрированные вебхуки
- `accounts_archive` - Архив удаленных аккаунтов

## 🧪 Тестирование

### Тест API endpoint

```bash
curl -X GET "http://localhost:8000/api/moysklad/vendor/1.0/apps/test-app-id/test-account-id/status"
```

### Тест базы данных

```bash
php artisan tinker
>>> DB::table('accounts')->count();
```

## 🚢 Деплой

### GitHub Actions

Проект настроен для автоматического деплоя через GitHub Actions при push в ветку `main`.

**Необходимые Secrets:**
- `SERVER_HOST` - IP адрес сервера
- `SERVER_USER` - Пользователь SSH
- `SSH_PRIVATE_KEY` - Приватный SSH ключ

### Ручной деплой

```bash
# На сервере
cd /var/www/app.cavaleria.ru
./deploy.sh
```

## 📝 Разработка

### Основные команды

```bash
# Очистка кеша
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# Кеширование (production)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Просмотр роутов
php artisan route:list

# Миграции
php artisan migrate
php artisan migrate:rollback
php artisan migrate:fresh  # ВНИМАНИЕ: удаляет все данные!

# Frontend
npm run dev    # Development с hot reload
npm run build  # Production сборка
```

### Работа с Git

```bash
git status
git add .
git commit -m "Описание изменений"
git push origin main
```

## 🔧 Настройка production сервера

Подробная инструкция по настройке сервера CentOS 9 доступна в документации проекта.

**Основные шаги:**
1. Установка PHP 8.4, PostgreSQL 18, Node.js 22
2. Настройка Nginx
3. SSL сертификат Let's Encrypt
4. Настройка Redis
5. Настройка автодеплоя

## 🤝 Участие в разработке

1. Fork проекта
2. Создайте feature ветку (`git checkout -b feature/AmazingFeature`)
3. Commit изменений (`git commit -m 'Add some AmazingFeature'`)
4. Push в ветку (`git push origin feature/AmazingFeature`)
5. Откройте Pull Request

## 📄 Лицензия

Этот проект является проприетарным программным обеспечением.

## 📞 Контакты

**GitHub:** [cavaleria-dev](https://github.com/cavaleria-dev)  
**Email:** support@cavaleria.ru

## 🔗 Полезные ссылки

- [МойСклад JSON API 1.2](https://dev.moysklad.ru/doc/api/remap/1.2/)
- [МойСклад Vendor API 1.0](https://dev.moysklad.ru/doc/api/vendor/1.0/)
- [Laravel Documentation](https://laravel.com/docs/11.x)
- [Vue 3 Documentation](https://vuejs.org/)
- [Личный кабинет разработчика МойСклад](https://apps.moysklad.ru/cabinet/)

## 📋 TODO

### Критичные задачи
- [ ] Настроить URL Vendor API в настройках приложения МойСклад (сейчас запросы не приходят)
- [ ] Проверить SSL сертификат и доступность сервера извне для МойСклад

### Backend
- [ ] Реализовать логику работы с вебхуками
- [ ] Добавить шифрование для `access_token` в БД
- [ ] Реализовать синхронизацию каталога товаров
- [ ] Реализовать синхронизацию цен и остатков
- [ ] Реализовать синхронизацию заказов
- [ ] Добавить обработку ошибок при работе с API МойСклад
- [ ] Настроить очереди (queues) для фоновых задач

### Frontend
- [ ] Создать дашборд с аналитикой
- [ ] Добавить страницу управления дочерними аккаунтами
- [ ] Добавить оповещения о статусе дочерних аккаунтов (suspended/uninstalled)
- [ ] Реализовать настройки синхронизации через UI
- [ ] Добавить логи синхронизации в интерфейс
- [ ] Экспорт отчетов в Excel

### DevOps
- [ ] Настроить автоматический деплой через GitHub Actions
- [ ] Настроить мониторинг (Sentry, LogRocket)
- [ ] Настроить уведомления о критичных ошибках (Telegram, Email)
- [ ] Добавить ротацию логов (logrotate)

### Документация
- [ ] Добавить примеры использования API
- [ ] Создать диаграммы архитектуры
- [ ] Написать инструкцию по настройке приложения в МойСклад
- [ ] Добавить FAQ по частым проблемам

### Тестирование
- [ ] Написать unit-тесты для контроллеров
- [ ] Написать integration тесты для API
- [ ] Добавить end-to-end тесты для критичных сценариев
- [ ] Настроить CI/CD для автоматического запуска тестов

---

**Status:** В разработке
**Version:** 1.0.0
**Last Update:** 13.10.2025