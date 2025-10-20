# Queue System & Supervisor

## Queue System & Supervisor

### Why Supervisor?

**Supervisor** is a process control system for Unix-like operating systems. It's critical for production Laravel queue workers because:

1. **Automatic Restart**: If queue worker crashes or is killed, Supervisor automatically restarts it
2. **Boot Persistence**: Worker starts automatically when server reboots (no manual intervention)
3. **Process Management**: Easily start/stop/restart workers without finding PIDs
4. **Multiple Workers**: Run multiple worker processes for high-load scenarios
5. **Centralized Logging**: All worker output goes to single log file
6. **Graceful Shutdown**: Properly handles worker shutdown (waits for current job to finish)

**Without Supervisor**: You'd need to manually run `php artisan queue:work` in a screen/tmux session, and it would stop after server reboot or SSH disconnect.

**With Supervisor**: Set it up once, workers run forever with auto-restart on failure.

### Queue Architecture Flow

```
User clicks "Sync All" Button (Frontend)
    ↓
POST /api/sync/{accountId}/products/all (Controller)
    ↓
Create tasks in sync_queue table (status: pending, priority: 10)
    ↓
Cron runs every minute: php artisan schedule:run
    ↓
Scheduler dispatches ProcessSyncQueueJob to queue
    ↓
Supervisor-managed queue:work picks up job from queue
    ↓
ProcessSyncQueueJob::handle() - processes 50 tasks from sync_queue
    ↓
For each task: Call ProductSyncService->syncProduct()
    ↓
MoySkladService makes API calls with RateLimitHandler (45 req/sec control)
    ↓
Update task status: completed/failed
    ↓
Log to storage/logs/sync.log (detailed REQUEST/RESPONSE)
```

### Queue Configuration

**Current Setup** (`.env`):
```env
QUEUE_CONNECTION=database  # Uses sync_queue table in PostgreSQL
```

**Alternative Options**:
- `sync` - Synchronous (no queue, processes immediately) - for development only
- `database` - Uses database table - good for small/medium load
- `redis` - Uses Redis - best for high load (faster, more scalable)

**Why database queue for this app:**
- Moderate load (~1000 products per sync)
- Already have PostgreSQL running
- Easy debugging (can see tasks in DB)
- Rate limit control more important than raw speed

### Initial Setup (One-Time)

**Step 1: Install Supervisor**
```bash
# CentOS/RHEL
sudo yum install supervisor -y
sudo systemctl enable supervisord
sudo systemctl start supervisord

# Ubuntu/Debian
sudo apt-get install supervisor -y
sudo systemctl enable supervisor
sudo systemctl start supervisor
```

**Step 2: Configure Worker**
```bash
cd /var/www/app.cavaleria.ru
./setup-queue-worker.sh
```

This script:
1. Detects OS (CentOS → `/etc/supervisord.d/*.ini`, Ubuntu → `/etc/supervisor/conf.d/*.conf`)
2. Creates Supervisor config for laravel-worker
3. Reloads Supervisor configuration
4. Starts worker process
5. Shows status and management commands

**Generated config** (example):
```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/app.cavaleria.ru/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --timeout=300
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/app.cavaleria.ru/storage/logs/worker.log
stopwaitsecs=3600
```

**Key parameters:**
- `--sleep=3` - Sleep 3 seconds when no jobs available (reduces DB load)
- `--tries=3` - Retry failed jobs up to 3 times
- `--max-time=3600` - Worker runs for 1 hour then restarts (prevents memory leaks)
- `--timeout=300` - Kill job if it runs longer than 5 minutes
- `numprocs=1` - Run 1 worker process (increase for high load)

### Multiple Workers (High Load Scenario)

**When to use multiple workers:**
- Syncing 10,000+ products
- Multiple child accounts (3+) syncing simultaneously
- Need to process >100 jobs/minute

**Option 1: Multiple identical workers**
```ini
[program:laravel-worker]
numprocs=4  # Run 4 identical workers
process_name=%(program_name)s_%(process_num)02d
command=php /path/artisan queue:work --sleep=3 --tries=3
```

**Option 2: Priority-based queues**
```ini
# High priority worker (products, orders)
[program:laravel-worker-high]
command=php /path/artisan queue:work --queue=high --sleep=1 --tries=3
numprocs=2

# Low priority worker (images, metadata)
[program:laravel-worker-low]
command=php /path/artisan queue:work --queue=low --sleep=5 --tries=3
numprocs=1
```

Then when queueing jobs:
```php
// High priority (products, orders)
ProcessSyncQueueJob::dispatch()->onQueue('high');

// Low priority (images, folders)
ProcessSyncQueueJob::dispatch()->onQueue('low');
```

**Option 3: Per-account workers**
```ini
# Main account workers (high load)
[program:laravel-worker-main]
command=php /path/artisan queue:work --queue=account-main-uuid --sleep=2
numprocs=3

# Child accounts (moderate load)
[program:laravel-worker-child]
command=php /path/artisan queue:work --queue=account-child-* --sleep=3
numprocs=1
```

### Daily Management

**Check worker status:**
```bash
sudo supervisorctl status laravel-worker:*
# Output: laravel-worker:laravel-worker_00   RUNNING   pid 12345, uptime 2 days, 3:45:12
```

**Restart worker** (after code deploy):
```bash
sudo supervisorctl restart laravel-worker:*
# Or use shortcut:
./restart-queue.sh
```

**Stop worker** (for maintenance):
```bash
sudo supervisorctl stop laravel-worker:*
```

**Start worker**:
```bash
sudo supervisorctl start laravel-worker:*
```

**Monitor queue**:
```bash
./monitor-queue.sh
# Shows: pending, processing, completed, failed counts + recent logs
```

**View worker logs**:
```bash
tail -f storage/logs/worker.log          # Worker output
tail -f storage/logs/laravel.log         # Application logs
tail -f storage/logs/sync.log            # Detailed sync logs (REQUEST/RESPONSE)
```

### Debugging Common Issues

**Issue: Worker not processing jobs**
```bash
# 1. Check worker is running
sudo supervisorctl status laravel-worker:*

# 2. Check for errors in logs
tail -30 storage/logs/worker.log

# 3. Check tasks in queue
php artisan tinker
>>> \DB::table('sync_queue')->where('status', 'pending')->count();

# 4. Try running worker manually (see what happens)
php artisan queue:work --once

# 5. Check cron is running scheduler
grep CRON /var/log/syslog  # Ubuntu
journalctl -u crond        # CentOS
```

**Issue: Jobs failing repeatedly**
```bash
# Check failed jobs
php artisan tinker
>>> \DB::table('sync_queue')->where('status', 'failed')->orderBy('updated_at', 'desc')->take(5)->get();

# Check error messages
tail -50 storage/logs/sync.log | grep ERROR

# Requeue failed jobs
php artisan queue:retry all
```

**Issue: Worker stuck/frozen**
```bash
# Hard restart
sudo supervisorctl restart laravel-worker:*

# If still stuck, kill and restart
sudo supervisorctl stop laravel-worker:*
sudo pkill -9 -f "queue:work"
sudo supervisorctl start laravel-worker:*
```

**Issue: High memory usage**
```bash
# Reduce max-time to restart worker more often
# Edit Supervisor config:
command=php /path/artisan queue:work --max-time=1800  # 30 minutes instead of 1 hour

# Then reload
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart laravel-worker:*
```

### Rate Limiting & МойСклад API

**Why queue is critical for МойСклад:**
- API limit: 45 requests/second burst, lower sustained rate
- Exceeding limit → 429 error → temporary ban
- Queue allows controlled request rate via `RateLimitHandler`

**How rate limiting works:**
1. `MoySkladService` uses `RateLimitHandler` for all API calls
2. Handler tracks requests per second
3. If approaching limit (45 req/sec), adds delay
4. On 429 response, exponential backoff (1s → 2s → 4s → 8s)
5. Queue workers process steadily without overwhelming API

**Without queue (webhook immediate sync):**
- Webhook triggers → Immediate API call
- 10 webhooks in 1 second → 10 API calls → Potential rate limit

**With queue (batch sync):**
- Create 1000 tasks → Queue processes 50/minute
- Controlled rate → Never exceeds API limits

### Helper Scripts

**setup-queue-worker.sh** - Initial setup
- Auto-detects OS (CentOS/Ubuntu)
- Creates Supervisor config
- Starts worker
- Shows management commands

**monitor-queue.sh** - Monitor queue status
- Shows pending/processing/completed/failed counts
- Shows recent worker logs
- Shows recent application logs

**restart-queue.sh** - Quick restart
- Restarts worker via Supervisor
- Useful after code deployment

All scripts are in project root. Run `./script-name.sh` on server.
