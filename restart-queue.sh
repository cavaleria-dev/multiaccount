#!/bin/bash

# Скрипт для перезапуска Laravel Queue Worker

PROJECT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd $PROJECT_PATH

echo "=== Restart Laravel Queue Worker ==="
echo ""

# Проверить что supervisor установлен
if ! command -v supervisorctl &> /dev/null; then
    echo "⚠️  Supervisor не установлен!"
    echo "Worker может работать в screen/tmux - проверьте вручную"
    exit 1
fi

# Показать текущий статус
echo "Текущий статус:"
sudo supervisorctl status laravel-worker:*
echo ""

# Перезапустить
echo "Перезапуск worker..."
sudo supervisorctl restart laravel-worker:*
echo ""

# Подождать секунду
sleep 1

# Показать новый статус
echo "Новый статус:"
sudo supervisorctl status laravel-worker:*
echo ""

# Показать последние логи
echo "Последние логи worker (5 строк):"
if [ -f storage/logs/worker.log ]; then
    tail -5 storage/logs/worker.log
else
    echo "  Worker log not found"
fi
echo ""

echo "=== Restart Complete ==="
echo ""
echo "Мониторинг:"
echo "  ./monitor-queue.sh"
echo ""
echo "Логи в реальном времени:"
echo "  tail -f storage/logs/worker.log"
