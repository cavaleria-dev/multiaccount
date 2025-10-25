# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 📚 Documentation Structure

This project uses modular documentation for better maintainability. See specific guides below:

1. **[Getting Started](docs/01-getting-started.md)** - Development setup, commands, deployment
2. **[Queue & Supervisor](docs/02-queue-supervisor.md)** - Queue system architecture, Supervisor management
3. **[Architecture Overview](docs/03-architecture.md)** - МойСклад integration, synchronization architecture
4. **[Batch Synchronization](docs/04-batch-sync.md)** ⭐ - Batch optimization (97% fewer API calls)
5. **[Service Layer](docs/05-services.md)** - Service responsibilities, sync services architecture
6. **[Frontend Architecture](docs/06-frontend.md)** - Vue 3, components, composables
7. **[Database Structure](docs/07-database.md)** - Tables, mappings, migrations
8. **[API Endpoints](docs/08-api-endpoints.md)** - REST API reference
9. **[Coding Standards](docs/09-coding-standards.md)** - PHP/Laravel + Vue 3 conventions
10. **[Common Patterns & Gotchas](docs/10-common-patterns.md)** - Best practices, troubleshooting
11. **[Admin Monitoring](docs/11-admin-monitoring.md)** - API monitoring system
12. **[Configuration & Resources](docs/12-configuration.md)** - Environment setup, Git workflow
13. **[Image Synchronization](docs/13-image-sync.md)** - Image sync with batch upload optimization
14. **[Product Folder Synchronization](docs/14-product-folder-sync.md)** ⭐ - Filtered folder sync (95% fewer folders synced)
15. **[Characteristic Synchronization](docs/15-characteristic-sync.md)** ⭐ - Proactive characteristic sync (fixes error 10002)
16. **[Sync Task Handlers](docs/16-sync-handlers.md)** 🆕 - Modular handler architecture (76% code reduction)
17. **[Variant Assortment Sync](docs/17-variant-assortment-sync.md)** 🆕 - Unified variant sync via /entity/assortment

## Quick Reference

### Project Overview

МойСклад Franchise Management Application - Laravel 11 + Vue 3 application for managing franchise networks in МойСклад with automatic data synchronization between main and child accounts. Runs as an iframe application inside МойСклад interface.

**Stack:** PHP 8.4, Laravel 11, PostgreSQL 18, Redis 7, Vue 3, Tailwind CSS 3

### Key Commands

```bash
# Frontend (local machine)
npm run dev                   # Start Vite dev server with hot reload
npm run build                 # Production build

# Backend (server ONLY - no local PHP environment!)
composer dev                  # All services (serve + queue + logs + npm)
php artisan migrate           # Run migrations
./deploy.sh                   # Deploy to production
./restart-queue.sh            # Restart queue worker after deploy
```

### Architecture Highlights

- **МойСклад iframe app** - Runs inside МойСклад interface (context-based auth)
- **Franchise management** - Main account → Child accounts synchronization
- **Batch optimization** - 97% fewer API requests for products/services ([details](docs/04-batch-sync.md))
- **Queue-based sync** - Supervisor + ProcessSyncQueueJob (50 tasks/minute)
- **Modular handlers** - 13 sync task handlers (76% code reduction) ([details](docs/16-sync-handlers.md))
- **Context caching** - 30min cache for МойСклад authentication context

### Top 10 Critical Gotchas

Full list: [Common Patterns & Gotchas](docs/10-common-patterns.md)

1. ⚠️ **No local PHP environment** - All `php artisan` commands run on server ONLY
2. ⚠️ **JWT must use `JSON_UNESCAPED_SLASHES`** - МойСклад Vendor API will fail without it
3. ⚠️ **Restart worker after deploy** - Supervisor keeps old code: `./restart-queue.sh`
4. ⚠️ **Queue payload MUST include `main_account_id`** - Jobs fail with TypeError without it
5. ⚠️ **Catch `\Throwable`, not `\Exception`** - To handle TypeError in queue jobs
6. ⚠️ **Context must be cached** - Middleware expects `moysklad_context:{contextKey}` in Redis
7. ⚠️ **contextKey in sessionStorage** - API interceptor reads from there, not from URL
8. ⚠️ **Scheduler + Queue are separate** - Cron dispatches jobs, Supervisor processes them
9. ⚠️ **Worker holds DB connection** - Manual sync_queue updates may not be seen immediately
10. ⚠️ **Failed tasks (3 attempts) stop retrying** - Must manually requeue or fix and requeue

### Synchronization Flow

**Products/Services (Main → Child):**
1. **Pre-cache dependencies** - Attributes, price types (NOT folders!) - done once
2. **Load entities** - Batches of 100 with expand, apply filters
3. **Pre-sync folders** - ONLY for filtered entities (if `create_product_folders = true`)
4. **Prepare batch** - Use cached mappings (0 additional GET requests!)
5. **Batch POST** - 100 entities per request to МойСклад
6. **Create mappings** - Store entity mappings + individual retry for failures

**Key optimization:** Product folders synced in **PHASE 2.5** (after filtering, before batch POST) - only folders with filtered entities, not all 1000 folders!

**See:** [Batch Synchronization](docs/04-batch-sync.md) for detailed architecture.

**Orders (Child → Main):**
- customerorder → customerorder
- retaildemand → customerorder
- purchaseorder → customerorder (проведенные only)
- **Immediate sync WITHOUT queue** (time-sensitive, low volume)

### Development Workflow

**Local (your machine):**
- Frontend only: `npm run dev`
- Make changes to Vue components, composables, etc.
- Git commit & push

**Server (via SSH or deploy.sh):**
- Backend changes: Deployed via `./deploy.sh`
- Migrations: Run automatically during deploy
- Queue worker: Auto-restart via deploy.sh
- Manual restart: `./restart-queue.sh`

**Important:** Never run PHP commands locally - no PHP environment installed on dev machine!

### Testing on Production

```bash
# Monitor sync operations
tail -f storage/logs/laravel.log | grep -E "Batch|sync"

# Check queue status
./monitor-queue.sh

# View detailed API logs (REQUEST/RESPONSE)
tail -f storage/logs/sync.log

# Cleanup stale characteristic mappings (fixes error 10001)
php artisan sync:cleanup-stale-characteristic-mappings --dry-run
php artisan sync:cleanup-stale-characteristic-mappings

# Admin panel
open https://app.cavaleria.ru/admin/logs
```

### Common Tasks

**Add new migration:**
```php
// Create manually in database/migrations/
// Name: YYYY_MM_DD_HHMMSS_description.php
// Always check if column exists before adding
// Always implement down() method
// Deploy to run: ./deploy.sh
```

**Add new service:**
```php
// app/Services/MyService.php
// Register in AppServiceProvider if needed
// Use dependency injection in constructors
// Always log operations with Log::info/error
// Always wrap in try-catch
```

**Add new Vue component:**
```vue
<!-- resources/js/components/MyComponent.vue -->
<script setup>
// Composition API only (no Options API)
// Props, emits, composables, reactive state, computed, watch, methods, lifecycle
</script>
```

**See:** [Coding Standards](docs/09-coding-standards.md) for detailed conventions.

### Resources

- [МойСклад JSON API 1.2](https://dev.moysklad.ru/doc/api/remap/1.2/)
- [МойСклад Vendor API 1.0](https://dev.moysklad.ru/doc/api/vendor/1.0/)
- [Developer Console](https://apps.moysklad.ru/cabinet/)

---

## Need More Details?

Each section above links to detailed documentation in the `docs/` folder. Start with the relevant guide for your task.

**Quick navigation:**
- New to the project? → [Getting Started](docs/01-getting-started.md)
- Working on sync? → [Batch Synchronization](docs/04-batch-sync.md) or [Architecture](docs/03-architecture.md)
- Adding features? → [Service Layer](docs/05-services.md) + [Coding Standards](docs/09-coding-standards.md)
- Debugging issues? → [Common Patterns & Gotchas](docs/10-common-patterns.md)
- Frontend work? → [Frontend Architecture](docs/06-frontend.md)
- Image sync? → [Image Synchronization](docs/13-image-sync.md)
- Product folder sync? → [Product Folder Synchronization](docs/14-product-folder-sync.md)
