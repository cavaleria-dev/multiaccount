#!/bin/bash

# Скрипт для проверки и настройки Laravel Scheduler на production

echo "=== Проверка Laravel Scheduler ==="
echo ""

# 1. Проверка наличия cron задачи
echo "1. Проверка crontab..."
CRON_EXISTS=$(crontab -l 2>/dev/null | grep -c "schedule:run")

if [ "$CRON_EXISTS" -gt 0 ]; then
    echo "✓ Cron задача найдена:"
    crontab -l | grep "schedule:run"
else
    echo "✗ Cron задача НЕ найдена!"
    echo ""
    echo "Для добавления выполните:"
    echo "  crontab -e"
    echo ""
    echo "И добавьте строку:"
    echo "  * * * * * cd /var/www/app.cavaleria.ru && php artisan schedule:run >> /dev/null 2>&1"
fi

echo ""

# 2. Проверка списка задач в планировщике
echo "2. Список задач в планировщике:"
php artisan schedule:list

echo ""

# 3. Проверка очереди sync_queue
echo "3. Проверка очереди sync_queue:"
php artisan tinker --execute="
\$pending = \DB::table('sync_queue')->where('status', 'pending')->count();
\$processing = \DB::table('sync_queue')->where('status', 'processing')->count();
\$completed = \DB::table('sync_queue')->where('status', 'completed')->count();
\$failed = \DB::table('sync_queue')->where('status', 'failed')->count();
echo 'Pending: ' . \$pending . PHP_EOL;
echo 'Processing: ' . \$processing . PHP_EOL;
echo 'Completed: ' . \$completed . PHP_EOL;
echo 'Failed: ' . \$failed . PHP_EOL;
"

echo ""

# 4. Тестовый запуск планировщика
echo "4. Тестовый запуск планировщика (для проверки):"
read -p "Запустить schedule:run? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    php artisan schedule:run
fi

echo ""
echo "=== Проверка завершена ==="
