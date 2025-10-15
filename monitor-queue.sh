#!/bin/bash

# Скрипт для мониторинга Laravel Queue и Sync Queue

PROJECT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd $PROJECT_PATH

echo "=== Laravel Queue Monitor ==="
echo "Time: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# 1. Статус Supervisor Worker
echo "1. Supervisor Worker Status:"
if command -v supervisorctl &> /dev/null; then
    sudo supervisorctl status laravel-worker:* 2>/dev/null || echo "  Worker not configured in supervisor"
else
    echo "  Supervisor not installed"
fi
echo ""

# 2. QUEUE_CONNECTION
echo "2. Queue Configuration:"
grep QUEUE_CONNECTION .env 2>/dev/null || echo "  QUEUE_CONNECTION not set"
grep QUEUE_DRIVER .env 2>/dev/null || echo "  QUEUE_DRIVER not set"
echo ""

# 3. Laravel Jobs Queue (если используется database driver)
echo "3. Laravel Jobs Queue (database):"
JOB_COUNT=$(php artisan tinker --execute="echo \DB::table('jobs')->count();" 2>/dev/null || echo "N/A")
echo "  Jobs in queue: $JOB_COUNT"
echo ""

# 4. Sync Queue статистика
echo "4. Sync Queue Statistics:"
php artisan tinker --execute="
\$pending = \DB::table('sync_queue')->where('status', 'pending')->count();
\$processing = \DB::table('sync_queue')->where('status', 'processing')->count();
\$completed = \DB::table('sync_queue')->where('status', 'completed')->count();
\$failed = \DB::table('sync_queue')->where('status', 'failed')->count();
\$total = \$pending + \$processing + \$completed + \$failed;
echo '  Pending: ' . \$pending . PHP_EOL;
echo '  Processing: ' . \$processing . PHP_EOL;
echo '  Completed: ' . \$completed . PHP_EOL;
echo '  Failed: ' . \$failed . PHP_EOL;
echo '  Total: ' . \$total . PHP_EOL;
" 2>/dev/null
echo ""

# 5. Последние записи в логах
echo "5. Recent ProcessSyncQueueJob logs:"
if [ -f storage/logs/laravel.log ]; then
    tail -10 storage/logs/laravel.log | grep -E "(ProcessSyncQueueJob|Processing sync)" | tail -5 || echo "  No recent logs"
else
    echo "  Log file not found"
fi
echo ""

# 6. Worker logs (если есть)
echo "6. Recent Worker logs:"
if [ -f storage/logs/worker.log ]; then
    tail -5 storage/logs/worker.log
else
    echo "  Worker log file not found"
fi
echo ""

# 7. Sync logs (REQUEST/RESPONSE)
echo "7. Recent Sync logs (REQUEST/RESPONSE):"
if [ -f storage/logs/sync.log ]; then
    SYNC_COUNT=$(grep -c "REQUEST\|RESPONSE" storage/logs/sync.log 2>/dev/null || echo "0")
    echo "  Total REQUEST/RESPONSE entries: $SYNC_COUNT"
    if [ "$SYNC_COUNT" -gt 0 ]; then
        echo "  Last 3 entries:"
        grep -E "REQUEST|RESPONSE" storage/logs/sync.log | tail -3 | cut -c1-100
    fi
else
    echo "  Sync log file not found (no syncs yet?)"
fi
echo ""

echo "=== End of Monitor ==="
echo ""
echo "Useful commands:"
echo "  Watch mode: watch -n 5 ./monitor-queue.sh"
echo "  Worker logs: tail -f storage/logs/worker.log"
echo "  App logs: tail -f storage/logs/laravel.log | grep ProcessSyncQueueJob"
echo "  Sync logs: tail -f storage/logs/sync.log"
