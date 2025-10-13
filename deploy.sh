#!/bin/bash

set -e  # Остановить выполнение при любой ошибке

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

APP_DIR="/var/www/app.cavaleria.ru"
LOG_FILE="$APP_DIR/storage/logs/deploy.log"
MAX_LOG_SIZE=10485760  # 10MB

cd $APP_DIR

# Создаём лог файл если его нет
mkdir -p storage/logs
touch $LOG_FILE

# Ротация логов если файл больше 10MB
if [ -f "$LOG_FILE" ] && [ $(stat -f%z "$LOG_FILE" 2>/dev/null || stat -c%s "$LOG_FILE") -gt $MAX_LOG_SIZE ]; then
    mv $LOG_FILE "$LOG_FILE.old"
    touch $LOG_FILE
fi

# Функция логирования
log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a $LOG_FILE
}

error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" | tee -a $LOG_FILE
}

warn() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1" | tee -a $LOG_FILE
}

# Функция отката при ошибке
rollback() {
    error "Deployment failed! Rolling back..."
    git reset --hard HEAD@{1} >> $LOG_FILE 2>&1
    sudo chown -R nginx:nginx .
    php artisan up >> $LOG_FILE 2>&1
    exit 1
}

# Установить обработчик ошибок
trap rollback ERR

log "=== Deployment started ==="

# Включаем режим обслуживания
log "Enabling maintenance mode..."
php artisan down --render="errors::503" --retry=60 >> $LOG_FILE 2>&1 || warn "Failed to enable maintenance mode"

# Временно меняем владельца для git
log "Changing ownership for git operations..."
sudo chown -R $USER:$USER .

# Сохраняем текущий коммит для отката
CURRENT_COMMIT=$(git rev-parse HEAD)
log "Current commit: $CURRENT_COMMIT"

# Git pull
log "Pulling latest changes from origin/main..."
if git pull origin main >> $LOG_FILE 2>&1; then
    NEW_COMMIT=$(git rev-parse HEAD)
    log "Updated to commit: $NEW_COMMIT"

    if [ "$CURRENT_COMMIT" == "$NEW_COMMIT" ]; then
        log "No changes detected, skipping build steps"
        php artisan up >> $LOG_FILE 2>&1
        log "=== Deployment completed (no changes) ==="
        exit 0
    fi
else
    error "Git pull failed"
    exit 1
fi

# Возвращаем владельца nginx
log "Restoring nginx ownership..."
sudo chown -R nginx:nginx .
sudo chmod -R 775 storage bootstrap/cache

# Composer - без --no-dev для dev окружения (если нужны dev пакеты)
log "Installing/updating composer dependencies..."
if [ "$APP_ENV" == "production" ]; then
    composer install --optimize-autoloader --no-dev >> $LOG_FILE 2>&1
else
    composer install --optimize-autoloader >> $LOG_FILE 2>&1
fi

# Проверяем были ли изменения в frontend
if git diff --name-only $CURRENT_COMMIT $NEW_COMMIT | grep -qE '^(resources|package\.json|vite\.config\.js)'; then
    log "Frontend changes detected, rebuilding..."
    npm ci --production=false >> $LOG_FILE 2>&1
    npm run build >> $LOG_FILE 2>&1
else
    log "No frontend changes, skipping npm build"
fi

# Laravel migrations
log "Running database migrations..."
php artisan migrate --force >> $LOG_FILE 2>&1

# Очистка и кеширование конфигов
log "Clearing and caching configurations..."
php artisan config:clear >> $LOG_FILE 2>&1
php artisan config:cache >> $LOG_FILE 2>&1
php artisan route:cache >> $LOG_FILE 2>&1
php artisan view:cache >> $LOG_FILE 2>&1

# Перезапуск очередей (если используются)
if pgrep -f "artisan queue:work" > /dev/null; then
    log "Restarting queue workers..."
    php artisan queue:restart >> $LOG_FILE 2>&1
fi

# Restart PHP-FPM
log "Restarting PHP-FPM service..."
sudo systemctl restart php-fpm >> $LOG_FILE 2>&1

# Отключаем режим обслуживания
log "Disabling maintenance mode..."
php artisan up >> $LOG_FILE 2>&1

log "=== Deployment completed successfully ==="

# Опционально: отправить уведомление об успешном деплое
# curl -X POST "https://api.telegram.org/bot<TOKEN>/sendMessage" \
#      -d chat_id=<CHAT_ID> \
#      -d text="✅ Deployment successful: $NEW_COMMIT"

exit 0