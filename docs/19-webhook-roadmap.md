# Webhook System - Implementation Roadmap

**Complete implementation plan** - From 20% to 100% production-ready webhook system

**See also:**
- [18-webhook-system.md](18-webhook-system.md) - Architecture & Design Decisions
- [19-webhook-tasks.md](19-webhook-tasks.md) - Day-by-day task breakdown (14 days)
- [19-webhook-migration.md](19-webhook-migration.md) - Migration from existing code
- [18-webhook-services.md](18-webhook-services.md) - Backend Services (detailed code)
- [18-webhook-implementation.md](18-webhook-implementation.md) - Jobs, Controllers, Routes
- [18-webhook-frontend.md](18-webhook-frontend.md) - Vue 3 Components
- [18-webhook-testing.md](18-webhook-testing.md) - Testing & Troubleshooting

---

## Executive Summary

### Current State: 20% Complete ⚠️

The webhook system documentation is **comprehensive and production-ready** (~7,300 lines), but the actual implementation is only **~20% complete**. Significant work remains to bring the system to full production readiness.

**What exists:**
- ✅ Database tables (partial - missing columns)
- ✅ Basic WebhookService (setup/cleanup)
- ✅ Basic WebhookController (needs rewrite)
- ✅ WebhookHealth model (basic)

**What's missing:**
- ❌ 3 core services (Receiver, Processor, Health)
- ❌ 2 async jobs (ProcessWebhookJob, SetupAccountWebhooksJob)
- ❌ 2 models (Webhook, WebhookLog)
- ❌ 3 migrations (webhook_logs, health_stats, missing columns)
- ❌ 4 Artisan commands (setup, check, cleanup, update-stats)
- ❌ 3 Vue components (AccountTypeSelector, WebhookHealth, WebhookLogs)
- ❌ Tests (0% coverage)

### Goal: 100% Production-Ready System

Complete webhook implementation that:
- Receives webhooks from МойСклад with <100ms response time
- Processes events asynchronously via queue
- Creates sync tasks for child accounts
- Monitors health and alerts on failures
- Provides admin UI for management
- Maintains <5% failure rate
- Prevents infinite webhook loops
- Ensures zero data loss

### Timeline: 14 Days (3 Weeks)

- **Week 1 (Days 1-7):** Backend Core - Database, Services, Jobs, Controllers, Commands
- **Week 2 (Days 8-10):** Frontend & Testing - Vue components, Unit tests, Integration tests
- **Week 3 (Days 11-14):** Deployment - Staging validation, Production rollout, Monitoring

### Team Requirements

**1 Full-Stack Developer:**
- Backend: PHP 8.4, Laravel 11, PostgreSQL
- Frontend: Vue 3, Tailwind CSS
- DevOps: Supervisor, queue management, deployment
- Estimated effort: **80-100 hours** (full-time for 2-3 weeks)

---

## Current State Assessment

### ✅ What Exists (20% Complete)

#### Database Tables (Partial)
**Location:** `database/migrations/`

1. **`webhooks` table** (2025_10_13_000006)
   - ✅ Basic structure exists
   - ❌ Missing columns: `account_type`, `diff_type`, `last_triggered_at`, `total_received`, `total_failed`
   - ❌ Missing constraint: UNIQUE (account_id, entity_type, action)
   - ❌ Wrong column name: `webhook_id` (should be `moysklad_webhook_id`)
   - **Status:** Needs ALTER migration

2. **`webhook_health` table** (2025_10_13_100004)
   - ✅ Complete structure
   - **Status:** Keep as is (may rename to `webhook_health_stats`)

#### Models (Partial)
**Location:** `app/Models/`

1. **`WebhookHealth.php`** (37 lines)
   - ✅ Basic fillable fields and casts
   - ❌ Missing: Relationships, computed properties, scopes
   - **Status:** Rename to `WebhookHealthStat.php` + enhance

#### Services (Basic)
**Location:** `app/Services/`

1. **`WebhookService.php`** (360 lines)
   - ✅ Has: `setupWebhooks()`, `cleanupOldWebhooks()`, `checkWebhookHealth()`
   - ❌ Missing: `getWebhooksConfig()`, `reinstallWebhooks()`, proper error collection
   - ❌ Issues: Hardcoded entity types, weak error handling
   - **Status:** Rename to `WebhookSetupService.php` + refactor

#### Controllers (Needs Rewrite)
**Location:** `app/Http/Controllers/Api/`

1. **`WebhookController.php`** (266 lines)
   - ✅ Has: Basic receive endpoint
   - ❌ Issues:
     - Wrong payload parsing (`$payload['action']` vs `$event['action']`)
     - Synchronous processing (blocks request)
     - No idempotency check
     - Direct sync_queue creation (violates SRP)
   - **Status:** Complete rewrite needed

#### Routes (Basic)
**Location:** `routes/api.php`

1. **Webhook endpoint**
   - ✅ `POST /api/webhooks/moysklad` exists
   - ❌ Should be: `POST /api/webhooks/receive` (per documentation)
   - ❌ Missing: Admin management endpoints
   - **Status:** Update route + add admin routes

---

### ❌ What's Missing (80% Not Implemented)

#### Services (3 missing - CRITICAL)

1. **`WebhookReceiverService.php`** (0% - NOT EXISTS)
   - Purpose: Fast webhook validation + idempotency + log creation
   - Methods: `validate()`, `receive()`
   - Target: <50ms execution time
   - **Priority:** HIGH

2. **`WebhookProcessorService.php`** (0% - NOT EXISTS)
   - Purpose: Parse events → check filters → create sync tasks
   - Methods: `process()`, `processMainAccountWebhook()`, `processChildAccountWebhook()`, `checkEntityPassesFilter()`, `createSyncTask()`, `createBatchUpdateTask()`
   - Complexity: HIGHEST (core business logic)
   - **Priority:** CRITICAL

3. **`WebhookHealthService.php`** (0% - NOT EXISTS)
   - Purpose: Health monitoring + statistics + alerts
   - Methods: `getHealthSummary()`, `getDetailedLogs()`, `getStatistics()`, `getAlerts()`, `updateHealthStats()`
   - **Priority:** MEDIUM (can be added later)

#### Jobs (2 missing - CRITICAL)

1. **`ProcessWebhookJob.php`** (0% - NOT EXISTS)
   - Purpose: Async webhook processing
   - Queue: default, Timeout: 120s, Tries: 3
   - **Priority:** CRITICAL

2. **`SetupAccountWebhooksJob.php`** (0% - NOT EXISTS)
   - Purpose: Async webhook installation
   - Queue: default, Timeout: 300s, Tries: 3
   - **Priority:** HIGH

#### Models (2 missing - CRITICAL)

1. **`Webhook.php`** (0% - NOT EXISTS)
   - Purpose: Webhook configuration & status
   - Methods: `incrementReceived()`, `incrementFailed()`, `getHealthStatus()`
   - **Priority:** HIGH

2. **`WebhookLog.php`** (0% - NOT EXISTS)
   - Purpose: Webhook processing log
   - Methods: `markAsProcessing()`, `markAsCompleted()`, `markAsFailed()`
   - **Priority:** CRITICAL

#### Migrations (3 missing - CRITICAL)

1. **`update_webhooks_table.php`** (0% - NOT EXISTS)
   - Purpose: Add missing columns to existing table
   - **Priority:** CRITICAL (blocks all work)

2. **`create_webhook_logs_table.php`** (0% - NOT EXISTS)
   - Purpose: Store all incoming webhooks
   - **Priority:** CRITICAL

3. **`create_webhook_health_stats_table.php`** (0% - NOT EXISTS)
   - Purpose: Aggregated statistics
   - **Priority:** MEDIUM

4. **`update_sync_settings_table.php`** (0% - NOT EXISTS)
   - Purpose: Add `account_type`, `webhooks_enabled` columns
   - **Priority:** HIGH

5. **`update_child_accounts_table.php`** (0% - NOT EXISTS)
   - Purpose: Add `status`, `inactive_reason`, `inactive_at` columns
   - **Priority:** MEDIUM

#### Artisan Commands (4 missing - HIGH)

1. **`WebhooksSetupCommand.php`** (0% - NOT EXISTS)
   - Command: `php artisan webhooks:setup`
   - **Priority:** HIGH

2. **`WebhooksCheckCommand.php`** (0% - NOT EXISTS)
   - Command: `php artisan webhooks:check`
   - **Priority:** HIGH

3. **`WebhooksCleanupLogsCommand.php`** (0% - NOT EXISTS)
   - Command: `php artisan webhooks:cleanup-logs`
   - **Priority:** LOW

4. **`WebhooksUpdateStatsCommand.php`** (0% - NOT EXISTS)
   - Command: `php artisan webhooks:update-stats`
   - **Priority:** MEDIUM

#### Frontend (3 components missing - MEDIUM)

1. **`AccountTypeSelector.vue`** (0% - NOT EXISTS)
   - Route: `/welcome`
   - Purpose: First-time account type selection
   - **Priority:** MEDIUM

2. **`admin/WebhookHealth.vue`** (0% - NOT EXISTS)
   - Route: `/admin/webhook-health`
   - Purpose: Health monitoring dashboard
   - **Priority:** MEDIUM

3. **`admin/WebhookLogs.vue`** (0% - NOT EXISTS)
   - Route: `/admin/webhook-logs`
   - Purpose: Detailed log viewer
   - **Priority:** LOW

#### Tests (0% coverage - HIGH)

1. **Unit Tests** (0% - NOT EXISTS)
   - WebhookReceiverServiceTest
   - WebhookProcessorServiceTest
   - WebhookSetupServiceTest
   - WebhookHealthServiceTest
   - **Priority:** HIGH

2. **Integration Tests** (0% - NOT EXISTS)
   - WebhookReceiverTest
   - WebhookProcessingTest
   - **Priority:** HIGH

3. **Manual Test Scripts** (0% - NOT EXISTS)
   - Testing scenarios from 18-webhook-testing.md
   - **Priority:** MEDIUM

---

## High-Level Roadmap

### Week 1: Backend Core (Days 1-7)

**Goal:** Complete backend infrastructure - database, services, jobs, controllers, commands

**Milestones:**
- ✅ Day 1: All migrations created and run successfully
- ✅ Day 2: All models created with relationships
- ✅ Day 4: All 4 services implemented and unit tested
- ✅ Day 5: Both jobs created and tested
- ✅ Day 6: Controllers and routes working
- ✅ Day 7: All Artisan commands functional

**Deliverables:**
- 5 database migrations
- 3 models (Webhook, WebhookLog, WebhookHealthStat)
- 4 services (Receiver, Processor, Setup, Health)
- 2 jobs (ProcessWebhookJob, SetupAccountWebhooksJob)
- 2 controllers (WebhookController, Admin/WebhookManagementController)
- 4 commands (setup, check, cleanup-logs, update-stats)
- API routes configured

**Validation:**
- ✅ `php artisan migrate` succeeds
- ✅ `php artisan test --filter=Unit/Services/Webhook` passes
- ✅ `curl` tests for all endpoints return correct responses
- ✅ Queue jobs can be dispatched and processed
- ✅ All Artisan commands run without errors

**Estimated Time:** 32-40 hours

---

### Week 2: Frontend & Testing (Days 8-10)

**Goal:** Complete frontend UI and comprehensive testing

**Milestones:**
- ✅ Day 9: All Vue components functional
- ✅ Day 10: Test coverage >80%

**Deliverables:**
- 3 Vue components (AccountTypeSelector, WebhookHealth, WebhookLogs)
- Updated router configuration
- Updated navigation menus
- Unit tests for all services (>80% coverage)
- Integration tests for webhook flow
- Manual testing completed

**Validation:**
- ✅ `npm run build` succeeds
- ✅ All routes accessible in browser
- ✅ API calls work from frontend
- ✅ `php artisan test` shows >80% coverage
- ✅ All manual test scenarios pass

**Estimated Time:** 20-24 hours

---

### Week 3: Deployment (Days 11-14)

**Goal:** Deploy to production with monitoring and gradual rollout

**Milestones:**
- ✅ Day 12: Staging validated (24 hours)
- ✅ Day 14: Production rollout complete

**Deliverables:**
- Staging deployment
- Performance testing results
- Production deployment
- Monitoring dashboards configured
- Documentation for ops team

**Validation:**
- ✅ Staging: Zero errors for 24 hours
- ✅ Production test account: 48 hours monitoring
- ✅ Gradual rollout: All accounts migrated
- ✅ Failure rate <5%
- ✅ No infinite loops detected

**Estimated Time:** 24-30 hours (variable based on issues)

---

## Success Criteria & KPIs

### Performance Metrics

**Webhook Receive Time:**
- Target: <100ms (95th percentile)
- Critical: <200ms (99th percentile)
- Method: Measure in WebhookController::receive()

**Webhook Processing Time:**
- Target: <5 seconds (95th percentile)
- Critical: <10 seconds (99th percentile)
- Method: Measure in ProcessWebhookJob

**Queue Depth:**
- Target: <100 pending tasks
- Critical: <500 pending tasks
- Method: Monitor sync_queue table

### Reliability Metrics

**Failure Rate:**
- Target: <5%
- Critical: <10%
- Method: `(failed_webhooks / total_webhooks) * 100`

**Uptime:**
- Target: 99.9%
- Critical: 99%
- Method: Monitor webhook endpoint availability

**Data Loss:**
- Target: Zero
- Critical: Zero
- Method: All webhooks logged in webhook_logs

### Quality Metrics

**Test Coverage:**
- Target: >80%
- Critical: >60%
- Method: `php artisan test --coverage`

**Code Quality:**
- No critical bugs in production
- All code reviews passed
- Documentation up to date

### Business Metrics

**User Adoption:**
- Target: 100% of accounts with webhooks enabled
- Method: Track sync_settings.webhooks_enabled

**Support Tickets:**
- Target: <5 tickets per week related to webhooks
- Method: Track support system

**Sync Latency:**
- Before webhooks: 5-15 minutes (manual sync)
- After webhooks: <1 minute (real-time)
- Target: 90% reduction in sync latency

---

## Risk Assessment & Mitigation

### High Risk Issues

#### 1. Infinite Webhook Loops 🔴

**Risk:** Child account updates trigger webhook → Main syncs back → triggers webhook → infinite loop

**Impact:** CRITICAL - System overload, МойСклад API rate limits, data corruption

**Probability:** HIGH (if not handled)

**Mitigation:**
```php
// CRITICAL: Add to MoySkladService::makeRequest()
$headers = [
    'X-Lognex-WebHook-DisableByPrefix' => config('app.url')
];
```

**Validation:**
- Manual test: Update product in Main → sync to Child → verify no webhook triggered
- Monitor: Check webhook_logs for same entity_id repeating

**Status:** MUST IMPLEMENT on Day 3 (Services)

---

#### 2. Race Conditions on Concurrent Webhooks 🔴

**Risk:** Two UPDATE webhooks for same product arrive simultaneously → duplicate sync tasks or data overwrite

**Impact:** HIGH - Duplicate work, inconsistent data

**Probability:** MEDIUM (during bulk updates)

**Mitigation:**
```php
// Use pessimistic locking
DB::transaction(function() use ($entityId) {
    SyncQueue::where('entity_id', $entityId)
             ->where('status', 'pending')
             ->lockForUpdate()
             ->first();
    // Create or update task
});
```

**Validation:**
- Load test: Send 10 concurrent webhooks for same entity
- Check: Only 1 task created in sync_queue

**Status:** MUST IMPLEMENT on Day 4 (WebhookProcessorService)

---

### Medium Risk Issues

#### 3. Performance Bottleneck on CREATE Filter Checks 🟡

**Risk:** Each CREATE event requires API call to МойСклад to load entity → 1000 creates = 1000 API calls = 8-16 minutes

**Impact:** MEDIUM - Slow processing, webhook timeout (МойСклад retries after 1.5s)

**Probability:** MEDIUM (during bulk imports)

**Mitigation:**
```php
// Batch load entities (100 per request)
$entities = $this->moySkladService->get("entity/product", [
    'filter' => 'id=' . implode(';id=', $entityIds),
    'expand' => 'productFolder,characteristics',
    'limit' => 100
]);
```

**Validation:**
- Test: Create 100 products in МойСklad
- Measure: Processing time should be <10 seconds (not 8 minutes)

**Status:** SHOULD IMPLEMENT on Day 4 (optimization)

---

#### 4. Database Deadlocks 🟡

**Risk:** Multiple queue workers updating same webhook record → deadlock

**Impact:** MEDIUM - Failed jobs, retry overhead

**Probability:** LOW (with proper transaction isolation)

**Mitigation:**
- Use row-level locking: `lockForUpdate()`
- Set transaction isolation level: `READ COMMITTED`
- Retry failed jobs with exponential backoff

**Validation:**
- Load test: 50 concurrent queue workers
- Monitor: Check for deadlock errors in logs

**Status:** Monitor in Week 3 (production)

---

### Low Risk Issues

#### 5. Frontend CSS Issues 🟢

**Risk:** UI looks broken on different screen sizes

**Impact:** LOW - User inconvenience, not critical functionality

**Probability:** LOW (Tailwind CSS is responsive)

**Mitigation:**
- Test on mobile, tablet, desktop
- Use Tailwind responsive classes

**Status:** Test on Day 9 (frontend)

---

#### 6. Webhook Health Stats Drift 🟢

**Risk:** Cached statistics become stale

**Impact:** LOW - Dashboard shows outdated metrics

**Probability:** MEDIUM

**Mitigation:**
- Schedule: `php artisan webhooks:update-stats` hourly
- Add cache TTL: 5 minutes

**Status:** Implement on Day 7 (commands)

---

## Resource Requirements

### Team

**1 Full-Stack Developer (required):**
- Skills: PHP, Laravel, Vue.js, PostgreSQL, Redis, Supervisor
- Experience: 3+ years with Laravel
- Availability: Full-time for 14 days (or part-time for 4 weeks)

**Optional (nice to have):**
- QA Engineer: For thorough testing (saves 4-6 hours)
- DevOps: For production deployment assistance (saves 2-3 hours)

### Infrastructure

**Development:**
- Local: Frontend only (`npm run dev`)
- Server: All PHP/Laravel work (no local PHP environment)

**Staging:**
- Full production-like environment
- Test МойСклад organization
- Separate database
- Supervisor queue worker

**Production:**
- Existing production environment
- Database backup before deployment
- Rollback plan ready

### Tools & Services

**Required:**
- МойСклад test accounts (Main + Child)
- Git repository access
- SSH access to production server
- Database admin tools (pgAdmin or similar)

**Optional:**
- Monitoring: Grafana/Prometheus (for advanced metrics)
- Error tracking: Sentry (for production error alerts)
- Load testing: Apache Bench or k6

---

## Dependencies & Blockers

### Critical Dependencies

**Day 1 (Migrations) blocks:**
- Day 2: Models (need tables)
- Day 3-4: Services (need models)
- Everything else

**Day 3-4 (Services) blocks:**
- Day 5: Jobs (call services)
- Day 6: Controllers (call services)
- Day 10: Tests (test services)

**Day 5 (Jobs) blocks:**
- Day 6: Controllers (dispatch jobs)

### External Dependencies

**МойСклад API:**
- Uptime required: 99%+
- Rate limits: Must not exceed
- Webhook registration: Must be available

**Production Server:**
- SSH access required
- Deployment permissions
- Supervisor control

**Database:**
- Migration permissions
- Backup/restore capability

---

## Next Steps

### Immediate Actions (Day 0 - Today)

1. **Review this roadmap** with stakeholders
2. **Confirm resource allocation** (developer availability)
3. **Setup staging environment** (if not already)
4. **Create feature branch:** `git checkout -b feature/webhook-system-complete`
5. **Backup production database:** `pg_dump multiaccount > backup_$(date +%Y%m%d).sql`

### Start Development (Day 1 - Tomorrow)

1. **Read detailed task breakdown:** [19-webhook-tasks.md](19-webhook-tasks.md)
2. **Begin with migrations** (Day 1 tasks)
3. **Use todo list** to track progress
4. **Commit frequently** with descriptive messages
5. **Run validation steps** after each task

### Get Help

**Documentation:**
- Questions about architecture? → [18-webhook-system.md](18-webhook-system.md)
- Need code examples? → [18-webhook-services.md](18-webhook-services.md)
- Migration issues? → [19-webhook-migration.md](19-webhook-migration.md)
- Testing unclear? → [18-webhook-testing.md](18-webhook-testing.md)

**Claude Code:**
- Can assist with implementation
- Can review code
- Can debug issues
- Can write tests

---

## Summary

**Current state:** 20% complete (basic structure exists)
**Goal:** 100% production-ready webhook system
**Timeline:** 14 days (3 weeks)
**Effort:** 80-100 hours

**Critical Path:**
1. Database migrations (Day 1) ⚠️
2. Core services (Days 3-4) ⚠️
3. Jobs (Day 5) ⚠️
4. Testing (Day 10) ⚠️
5. Production deployment (Days 13-14) ⚠️

**Success depends on:**
- ✅ Preventing infinite webhook loops
- ✅ Handling race conditions properly
- ✅ Maintaining <5% failure rate
- ✅ Thorough testing before production

**Ready to start?** Proceed to [19-webhook-tasks.md](19-webhook-tasks.md) for detailed day-by-day tasks.
