# Getting Started

## Project Overview

МойСклад Franchise Management Application - Laravel 12 + Vue 3 application for managing franchise networks in МойСклад with automatic data synchronization between main and child accounts. Runs as an iframe application inside МойСклад interface.

**Stack:** PHP 8.2+, Laravel 12, PostgreSQL 18, Redis 7, Vue 3, Tailwind CSS 3

## Development Environment

**IMPORTANT:** Local development machine does NOT have PHP environment installed. All PHP commands (migrations, artisan) must be executed on the production server.

## Development Commands

```bash
# Frontend development (local)
npm install                    # Install dependencies
npm run dev                    # Start Vite dev server with hot reload
npm run build                  # Production build

# Backend commands (ONLY on server via SSH or deploy.sh)
composer install               # Install PHP dependencies
php artisan migrate            # Run migrations
php artisan migrate:rollback   # Rollback last migration
php artisan cache:clear        # Clear application cache
php artisan config:clear       # Clear config cache

# Development (server only)
composer dev                   # Runs all services concurrently
# This starts: artisan serve, queue:listen, pail (logs), npm run dev

# Individual services (server only)
php artisan serve              # Backend server (localhost:8000)
php artisan queue:listen       # Process queue jobs (manual, for dev only)
php artisan pail               # Real-time logs

# Testing (server only)
composer test                  # Run PHPUnit tests
php artisan test               # Same as above

# Production commands (server only)
php artisan config:cache       # Cache config
php artisan route:cache        # Cache routes
php artisan view:cache         # Cache views

# Queue management (production - via Supervisor)
./setup-queue-worker.sh        # Setup queue worker with Supervisor (one-time)
./monitor-queue.sh             # Monitor queue status and logs
./restart-queue.sh             # Restart queue worker
sudo supervisorctl status laravel-worker:*    # Check worker status
sudo supervisorctl restart laravel-worker:*   # Restart worker

# Scheduler (production)
# Laravel 12: Schedule defined in routes/console.php
# Add to crontab: * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
```

## Deployment

**Primary method:** Use `deploy.sh` script for deployment.

```bash
# On server
cd /var/www/app.cavaleria.ru && ./deploy.sh
```

The deploy.sh script handles:
- Git pull from main branch
- composer install (production mode)
- npm install && npm run build
- Database migrations
- Cache clearing
- Service restarts

**Auto-deploy:** GitHub Actions automatically runs deploy.sh on push to `main` branch.

Required GitHub Secrets:
- `SERVER_HOST`
- `SERVER_USER`
- `SSH_PRIVATE_KEY`
