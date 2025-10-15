#!/bin/bash

# Скрипт для автоматической настройки Laravel Queue Worker через Supervisor

set -e  # Выход при ошибке

echo "=== Laravel Queue Worker Setup ==="
echo ""

# Определить текущий путь к проекту
PROJECT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
echo "Project path: $PROJECT_PATH"
echo ""

# Проверить что supervisor установлен
if ! command -v supervisorctl &> /dev/null; then
    echo "⚠️  Supervisor не установлен!"
    echo ""
    echo "Установите supervisor:"
    echo "  CentOS/RHEL: sudo yum install supervisor -y"
    echo "  Ubuntu/Debian: sudo apt-get install supervisor -y"
    echo ""
    exit 1
fi

echo "✓ Supervisor установлен"
echo ""

# Определить пользователя для worker (текущий пользователь или www-data)
WORKER_USER=$(whoami)
echo "Worker will run as user: $WORKER_USER"
echo ""

# Определить директорию для конфигураций supervisor в зависимости от ОС
if [ -d "/etc/supervisord.d" ]; then
    # CentOS/RHEL использует /etc/supervisord.d
    SUPERVISOR_CONF_DIR="/etc/supervisord.d"
    SUPERVISOR_CONF="$SUPERVISOR_CONF_DIR/laravel-worker.ini"
elif [ -d "/etc/supervisor/conf.d" ]; then
    # Ubuntu/Debian использует /etc/supervisor/conf.d
    SUPERVISOR_CONF_DIR="/etc/supervisor/conf.d"
    SUPERVISOR_CONF="$SUPERVISOR_CONF_DIR/laravel-worker.conf"
else
    echo "⚠️  Не найдена директория конфигураций supervisor!"
    echo ""
    echo "Попытка создать /etc/supervisor/conf.d..."
    sudo mkdir -p /etc/supervisor/conf.d
    SUPERVISOR_CONF_DIR="/etc/supervisor/conf.d"
    SUPERVISOR_CONF="$SUPERVISOR_CONF_DIR/laravel-worker.conf"
fi

echo "Создание конфигурации supervisor..."
echo "Файл: $SUPERVISOR_CONF"
echo ""

sudo tee $SUPERVISOR_CONF > /dev/null <<EOF
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $PROJECT_PATH/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --timeout=300
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=$WORKER_USER
numprocs=1
redirect_stderr=true
stdout_logfile=$PROJECT_PATH/storage/logs/worker.log
stopwaitsecs=3600
EOF

echo "✓ Конфигурация создана"
echo ""

# Обновить supervisor
echo "Обновление supervisor..."
sudo supervisorctl reread
sudo supervisorctl update
echo ""

# Запустить worker
echo "Запуск laravel-worker..."
sudo supervisorctl start laravel-worker:*
echo ""

# Проверить статус
echo "=== Статус Worker ==="
sudo supervisorctl status laravel-worker:*
echo ""

# Проверить QUEUE_CONNECTION
echo "=== Конфигурация Queue ==="
cd $PROJECT_PATH
grep QUEUE_CONNECTION .env || echo "QUEUE_CONNECTION not set in .env"
echo ""

# Показать команды для мониторинга
echo "=== Команды для управления ==="
echo ""
echo "Статус:"
echo "  sudo supervisorctl status laravel-worker:*"
echo ""
echo "Перезапуск:"
echo "  sudo supervisorctl restart laravel-worker:*"
echo ""
echo "Остановка:"
echo "  sudo supervisorctl stop laravel-worker:*"
echo ""
echo "Логи worker:"
echo "  tail -f $PROJECT_PATH/storage/logs/worker.log"
echo ""
echo "Логи приложения:"
echo "  tail -f $PROJECT_PATH/storage/logs/laravel.log | grep ProcessSyncQueueJob"
echo ""
echo "Мониторинг очереди:"
echo "  ./monitor-queue.sh"
echo ""
echo "=== Setup Complete! ==="
echo ""
echo "Queue worker запущен и будет автоматически перезапускаться"
echo "при падении или перезагрузке сервера."
