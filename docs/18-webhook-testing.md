# Webhook System - Testing & Troubleshooting

**Part 5 of 5** - Comprehensive testing strategy, monitoring, and troubleshooting guide

**See also:**
- [18-webhook-system.md](18-webhook-system.md) - Overview and Architecture
- [18-webhook-services.md](18-webhook-services.md) - Backend Services
- [18-webhook-implementation.md](18-webhook-implementation.md) - Jobs, Controllers, Routes
- [18-webhook-frontend.md](18-webhook-frontend.md) - Frontend Components

---

## Quick Reference

### Testing Checklist

- [ ] **Setup Testing** - Webhook installation on Main and Child accounts
- [ ] **Event Testing** - All entity types (product, service, variant, etc.) with CREATE/UPDATE/DELETE
- [ ] **Batch Testing** - Multiple entities in one webhook (UPDATE only)
- [ ] **Filter Testing** - Only filtered entities create sync tasks
- [ ] **Priority Testing** - Correct queue priorities (UPDATE=10, CREATE/DELETE=7)
- [ ] **Idempotency Testing** - Duplicate webhooks with same requestId
- [ ] **Error Handling** - Failed webhooks, retries, error logging
- [ ] **Cycle Prevention** - X-Lognex-WebHook-DisableByPrefix header
- [ ] **Performance Testing** - High load scenarios (100+ webhooks/min)
- [ ] **Security Testing** - Unauthorized access, payload validation

### Common Commands

```bash
# Check webhook health
php artisan webhooks:check

# View recent logs
tail -f storage/logs/webhook.log

# Monitor queue
./monitor-queue.sh

# Test webhook receiver
curl -X POST https://app.cavaleria.ru/api/webhooks/receive \
  -H "Content-Type: application/json" \
  -d @webhook-payload.json
```

---

## 1. Testing Strategy

### 1.1 Test Pyramid

```
     /\         E2E Tests (UI flows)
    /  \        - Account type selection
   /____\       - Webhook health dashboard
  /      \      Integration Tests (API + DB)
 /        \     - Webhook receiver → task creation
/__________\    Unit Tests (Services, Jobs)
              - WebhookProcessorService
              - WebhookSetupService
```

### 1.2 Test Environments

**Development:**
- Local frontend (`npm run dev`)
- Production backend (no local PHP)
- Test МойСклад account

**Staging:**
- Full production-like environment
- Separate МойСклад test organization
- Test data only

**Production:**
- Real МойСклад accounts
- Monitoring and alerts enabled
- Rollback plan ready

---

## 2. Unit Testing

### 2.1 WebhookReceiverService Tests

**Location:** `tests/Unit/Services/WebhookReceiverServiceTest.php`

```php
<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\WebhookReceiverService;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookReceiverServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WebhookReceiverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WebhookReceiverService::class);
    }

    /** @test */
    public function it_validates_webhook_payload_with_required_fields()
    {
        $validPayload = [
            'events' => [
                [
                    'action' => 'CREATE',
                    'meta' => [
                        'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/123',
                        'type' => 'product'
                    ]
                ]
            ]
        ];

        $this->assertTrue($this->service->validate($validPayload));
    }

    /** @test */
    public function it_rejects_invalid_webhook_payload()
    {
        $invalidPayload = [
            'events' => []  // Empty events
        ];

        $this->assertFalse($this->service->validate($invalidPayload));
    }

    /** @test */
    public function it_creates_webhook_log_on_receive()
    {
        $payload = [
            'events' => [
                [
                    'action' => 'UPDATE',
                    'meta' => [
                        'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/123',
                        'type' => 'product'
                    ]
                ]
            ]
        ];

        $requestId = 'test-request-id-123';

        $log = $this->service->receive($payload, $requestId);

        $this->assertInstanceOf(WebhookLog::class, $log);
        $this->assertEquals('pending', $log->status);
        $this->assertEquals($requestId, $log->request_id);
        $this->assertEquals('product', $log->entity_type);
        $this->assertEquals('UPDATE', $log->action);
    }

    /** @test */
    public function it_handles_duplicate_request_ids()
    {
        $payload = [
            'events' => [
                [
                    'action' => 'CREATE',
                    'meta' => [
                        'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/456',
                        'type' => 'product'
                    ]
                ]
            ]
        ];

        $requestId = 'duplicate-request-id';

        // First receive
        $log1 = $this->service->receive($payload, $requestId);

        // Second receive with same requestId (should return existing log)
        $log2 = $this->service->receive($payload, $requestId);

        $this->assertEquals($log1->id, $log2->id);
        $this->assertEquals(1, WebhookLog::where('request_id', $requestId)->count());
    }
}
```

### 2.2 WebhookProcessorService Tests

**Location:** `tests/Unit/Services/WebhookProcessorServiceTest.php`

```php
<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\WebhookProcessorService;
use App\Models\WebhookLog;
use App\Models\Account;
use App\Models\SyncQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookProcessorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WebhookProcessorService $service;
    protected Account $mainAccount;
    protected Account $childAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WebhookProcessorService::class);

        // Create test accounts
        $this->mainAccount = Account::factory()->create([
            'account_type' => 'main'
        ]);

        $this->childAccount = Account::factory()->create([
            'account_type' => 'child'
        ]);
    }

    /** @test */
    public function it_creates_sync_tasks_for_main_account_product_update()
    {
        $webhookLog = WebhookLog::factory()->create([
            'account_id' => $this->mainAccount->id,
            'entity_type' => 'product',
            'action' => 'UPDATE',
            'payload' => [
                'events' => [
                    [
                        'action' => 'UPDATE',
                        'meta' => [
                            'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/123',
                            'type' => 'product'
                        ],
                        'updatedFields' => ['name', 'salePrices']
                    ]
                ]
            ]
        ]);

        $results = $this->service->process($webhookLog);

        // Should create tasks for all child accounts
        $this->assertGreaterThan(0, count($results['created']));

        // Check task was created in sync_queue
        $task = SyncQueue::where('entity_type', 'product')
            ->where('action', 'update')
            ->where('priority', 10)  // UPDATE priority
            ->first();

        $this->assertNotNull($task);
    }

    /** @test */
    public function it_batches_multiple_update_events()
    {
        $webhookLog = WebhookLog::factory()->create([
            'account_id' => $this->mainAccount->id,
            'entity_type' => 'product',
            'action' => 'UPDATE',
            'payload' => [
                'events' => [
                    [
                        'action' => 'UPDATE',
                        'meta' => ['href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/1', 'type' => 'product'],
                        'updatedFields' => ['salePrices']
                    ],
                    [
                        'action' => 'UPDATE',
                        'meta' => ['href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/2', 'type' => 'product'],
                        'updatedFields' => ['salePrices']
                    ]
                ]
            ]
        ]);

        $results = $this->service->process($webhookLog);

        // Should create batch tasks (1 task per child account, not 2)
        $this->assertLessThanOrEqual(1, count($results['created']));

        // Task should have batch entity_ids
        $task = SyncQueue::first();
        $this->assertIsArray($task->entity_ids);
        $this->assertCount(2, $task->entity_ids);
    }

    /** @test */
    public function it_skips_filtered_entities_on_create()
    {
        // Set up filter to only sync products with specific attribute
        $this->mainAccount->syncSettings()->update([
            'filter_products_by_attribute' => true,
            'product_attribute_id' => 'some-attribute-id'
        ]);

        $webhookLog = WebhookLog::factory()->create([
            'account_id' => $this->mainAccount->id,
            'entity_type' => 'product',
            'action' => 'CREATE',
            'payload' => [
                'events' => [
                    [
                        'action' => 'CREATE',
                        'meta' => [
                            'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/999',
                            'type' => 'product'
                        ]
                    ]
                ]
            ]
        ]);

        $results = $this->service->process($webhookLog);

        // Should skip because entity doesn't match filter
        $this->assertCount(0, $results['created']);
        $this->assertGreaterThan(0, $results['skipped']);
    }

    /** @test */
    public function it_applies_correct_priorities()
    {
        // Test UPDATE priority (10)
        $updateLog = WebhookLog::factory()->create([
            'account_id' => $this->mainAccount->id,
            'entity_type' => 'product',
            'action' => 'UPDATE'
        ]);

        $this->service->process($updateLog);

        $updateTask = SyncQueue::where('entity_type', 'product')
            ->where('action', 'update')
            ->first();

        $this->assertEquals(10, $updateTask->priority);

        // Test CREATE priority (7)
        $createLog = WebhookLog::factory()->create([
            'account_id' => $this->mainAccount->id,
            'entity_type' => 'product',
            'action' => 'CREATE'
        ]);

        $this->service->process($createLog);

        $createTask = SyncQueue::where('entity_type', 'product')
            ->where('action', 'create')
            ->first();

        $this->assertEquals(7, $createTask->priority);

        // Test productfolder priority (5)
        $folderLog = WebhookLog::factory()->create([
            'account_id' => $this->mainAccount->id,
            'entity_type' => 'productfolder',
            'action' => 'UPDATE'
        ]);

        $this->service->process($folderLog);

        $folderTask = SyncQueue::where('entity_type', 'productfolder')->first();

        $this->assertEquals(5, $folderTask->priority);
    }
}
```

### 2.3 Run Unit Tests

```bash
# Run all webhook tests
php artisan test --filter=Webhook

# Run specific test class
php artisan test tests/Unit/Services/WebhookReceiverServiceTest.php

# Run with coverage
php artisan test --coverage --min=80
```

---

## 3. Integration Testing

### 3.1 Webhook Receiver Integration Test

**Location:** `tests/Feature/WebhookReceiverTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Account;
use App\Models\WebhookLog;
use App\Models\SyncQueue;
use App\Jobs\ProcessWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

class WebhookReceiverTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_receives_webhook_and_dispatches_job()
    {
        Queue::fake();

        $payload = [
            'events' => [
                [
                    'action' => 'UPDATE',
                    'meta' => [
                        'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/123',
                        'type' => 'product'
                    ],
                    'updatedFields' => ['name']
                ]
            ]
        ];

        $response = $this->postJson('/api/webhooks/receive', $payload, [
            'X-Lognex-WebHook-Id' => 'webhook-123',
            'X-Request-Id' => 'request-456'
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'received']);

        // Check webhook log was created
        $this->assertDatabaseHas('webhook_logs', [
            'request_id' => 'request-456',
            'status' => 'pending'
        ]);

        // Check job was dispatched
        Queue::assertPushed(ProcessWebhookJob::class);
    }

    /** @test */
    public function it_rejects_invalid_webhook_payload()
    {
        $invalidPayload = [
            'events' => []  // Empty events
        ];

        $response = $this->postJson('/api/webhooks/receive', $invalidPayload);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Invalid webhook payload']);
    }

    /** @test */
    public function it_handles_duplicate_webhooks()
    {
        $payload = [
            'events' => [
                [
                    'action' => 'CREATE',
                    'meta' => [
                        'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/789',
                        'type' => 'product'
                    ]
                ]
            ]
        ];

        $requestId = 'duplicate-request-id';

        // First request
        $response1 = $this->postJson('/api/webhooks/receive', $payload, [
            'X-Request-Id' => $requestId
        ]);

        $response1->assertStatus(200);

        // Second request with same requestId
        $response2 = $this->postJson('/api/webhooks/receive', $payload, [
            'X-Request-Id' => $requestId
        ]);

        $response2->assertStatus(200);
        $response2->assertJson(['status' => 'duplicate']);

        // Should only have one log entry
        $this->assertEquals(1, WebhookLog::where('request_id', $requestId)->count());
    }

    /** @test */
    public function it_processes_webhook_and_creates_sync_tasks()
    {
        $mainAccount = Account::factory()->create(['account_type' => 'main']);
        $childAccount = Account::factory()->create(['account_type' => 'child']);

        $webhookLog = WebhookLog::factory()->create([
            'account_id' => $mainAccount->id,
            'entity_type' => 'product',
            'action' => 'UPDATE',
            'payload' => [
                'events' => [
                    [
                        'action' => 'UPDATE',
                        'meta' => [
                            'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/123',
                            'type' => 'product'
                        ],
                        'updatedFields' => ['name']
                    ]
                ]
            ]
        ]);

        // Process webhook
        $job = new ProcessWebhookJob($webhookLog->id);
        $job->handle(app(\App\Services\WebhookProcessorService::class));

        // Check task was created
        $this->assertDatabaseHas('sync_queue', [
            'entity_type' => 'product',
            'action' => 'update',
            'status' => 'pending'
        ]);

        // Check webhook log status updated
        $webhookLog->refresh();
        $this->assertEquals('completed', $webhookLog->status);
    }
}
```

### 3.2 Run Integration Tests

```bash
# Run all feature tests
php artisan test --testsuite=Feature

# Run specific feature test
php artisan test tests/Feature/WebhookReceiverTest.php

# Run with database seeding
php artisan test --seed
```

---

## 4. Manual Testing

### 4.1 Setup Testing

**Test Case 1: Install webhooks on Main account**

```bash
# 1. Login to application as Main account
# 2. Select "Главный аккаунт" on welcome screen
# 3. Confirm installation

# Expected: Webhooks installed for:
# - product, service, variant, bundle (CREATE, UPDATE, DELETE)
# - productfolder (CREATE, UPDATE, DELETE)
# - customerorder, retaildemand, purchaseorder (CREATE, UPDATE, DELETE)

# Verify in database:
SELECT * FROM webhooks WHERE account_id = 'your-account-id';
# Should have 24 rows (8 entity types × 3 actions)
```

**Test Case 2: Install webhooks on Child account**

```bash
# 1. Login to application as Child account
# 2. Select "Дочерний аккаунт" on welcome screen
# 3. Confirm installation

# Expected: Webhooks installed for:
# - customerorder, retaildemand, purchaseorder (CREATE, UPDATE, DELETE) ONLY

# Verify in database:
SELECT * FROM webhooks WHERE account_id = 'your-child-account-id';
# Should have 9 rows (3 entity types × 3 actions)
```

### 4.2 Event Testing

**Test Case 3: Product UPDATE webhook**

```bash
# 1. In МойСклад Main account, update product price
# 2. Wait 1-2 seconds

# Expected:
# - Webhook received: GET /api/webhooks/receive
# - WebhookLog created with status=pending
# - ProcessWebhookJob dispatched
# - Job processes webhook → creates sync tasks
# - Tasks added to sync_queue with priority=10

# Verify:
SELECT * FROM webhook_logs ORDER BY created_at DESC LIMIT 1;
SELECT * FROM sync_queue WHERE entity_type='product' AND action='update' ORDER BY created_at DESC LIMIT 5;

# Check logs:
tail -f storage/logs/webhook.log
```

**Test Case 4: Product CREATE webhook with filter**

```bash
# 1. Configure filter in sync_settings: only products with specific attribute
# 2. Create product WITHOUT that attribute in МойСклад
# 3. Create product WITH that attribute in МойСклад

# Expected:
# - First product: Webhook received, but NO sync task created (filtered out)
# - Second product: Webhook received, sync task created with priority=7

# Verify:
SELECT * FROM sync_queue WHERE entity_type='product' AND action='create' ORDER BY created_at DESC;
# Should only have task for second product
```

**Test Case 5: Batch UPDATE webhook**

```bash
# 1. In МойСклад, select 10 products
# 2. Bulk update sale prices

# Expected:
# - ONE webhook with 10 events
# - ONE batch sync task created (not 10 individual tasks)
# - Task has entity_ids array with 10 product IDs

# Verify:
SELECT * FROM sync_queue ORDER BY created_at DESC LIMIT 1;
# Check entity_ids column → should be JSON array with 10 IDs
```

### 4.3 Cycle Prevention Testing

**Test Case 6: Verify X-Lognex-WebHook-DisableByPrefix header**

```bash
# 1. Enable webhook on Child account
# 2. Main account updates product → syncs to Child
# 3. Child receives product update from Main

# Expected:
# - Child product update does NOT trigger webhook back to Main
# - Check MoySkladService::makeRequest() adds header:
#   X-Lognex-WebHook-DisableByPrefix: webhook-{appId}

# Verify in logs:
tail -f storage/logs/sync.log | grep "X-Lognex-WebHook-DisableByPrefix"
```

### 4.4 Idempotency Testing

**Test Case 7: Duplicate webhooks**

```bash
# 1. Manually send same webhook twice with same X-Request-Id

curl -X POST https://app.cavaleria.ru/api/webhooks/receive \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: duplicate-test-123" \
  -d '{
    "events": [{
      "action": "UPDATE",
      "meta": {
        "href": "https://api.moysklad.ru/api/remap/1.2/entity/product/123",
        "type": "product"
      },
      "updatedFields": ["name"]
    }]
  }'

# Expected:
# - First request: 200 OK, status=received
# - Second request: 200 OK, status=duplicate
# - Only ONE webhook_log entry
# - Only ONE sync task created

# Verify:
SELECT COUNT(*) FROM webhook_logs WHERE request_id='duplicate-test-123';
# Should be 1
```

---

## 5. Performance Testing

### 5.1 Load Testing

**Scenario 1: High webhook frequency (100 webhooks/minute)**

```bash
# Use Apache Bench to simulate high load
ab -n 100 -c 10 -T 'application/json' \
  -H "X-Request-Id: load-test-${RANDOM}" \
  -p webhook-payload.json \
  https://app.cavaleria.ru/api/webhooks/receive

# Expected:
# - All webhooks received within <100ms each
# - No HTTP errors (all 200 OK)
# - Jobs queued successfully
# - No database deadlocks

# Monitor:
watch -n 1 'php artisan queue:work --once'
tail -f storage/logs/webhook.log
```

**Scenario 2: Batch webhook with 100 entities**

```bash
# Create webhook with 100 UPDATE events
curl -X POST https://app.cavaleria.ru/api/webhooks/receive \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: batch-test-100" \
  -d @webhook-batch-100.json

# Expected:
# - Webhook received in <200ms
# - Batch task created (not 100 individual tasks)
# - Job processes batch successfully
# - No memory issues

# Verify processing time:
SELECT
  id,
  received_at,
  processed_at,
  EXTRACT(EPOCH FROM (processed_at - received_at)) as processing_seconds
FROM webhook_logs
WHERE request_id='batch-test-100';
# Should be <5 seconds
```

### 5.2 Database Performance

**Check slow queries:**

```sql
-- Enable slow query logging in PostgreSQL
ALTER DATABASE multiaccount SET log_min_duration_statement = 1000;

-- Check webhook_logs table performance
EXPLAIN ANALYZE
SELECT * FROM webhook_logs
WHERE account_id = 'uuid-here'
  AND created_at > NOW() - INTERVAL '24 hours'
ORDER BY created_at DESC;

-- Check missing indexes
SELECT
  schemaname,
  tablename,
  attname,
  null_frac,
  avg_width,
  n_distinct
FROM pg_stats
WHERE tablename IN ('webhook_logs', 'webhooks', 'webhook_health_stats');
```

---

## 6. Monitoring & Alerting

### 6.1 Health Check Command

**Run health check manually:**

```bash
php artisan webhooks:check

# Output example:
# Webhook Health Check
# ====================
#
# Main Account (cavaleria-main):
#   ✓ 24 webhooks installed
#   ✓ Failure rate: 0.5% (2/400 in last 24h)
#   Status: HEALTHY
#
# Child Account (cavaleria-child-1):
#   ✓ 9 webhooks installed
#   ✓ Failure rate: 1.2% (5/420 in last 24h)
#   Status: HEALTHY
#
# Child Account (cavaleria-child-2):
#   ⚠ 9 webhooks installed
#   ⚠ Failure rate: 12.3% (49/398 in last 24h)
#   Status: UNHEALTHY - REQUIRES ATTENTION
#   Last error: Invalid entity ID format
```

### 6.2 Automated Monitoring

**Schedule health checks:**

```php
// app/Console/Kernel.php

protected function schedule(Schedule $schedule): void
{
    // Run health check every hour
    $schedule->command('webhooks:check')
        ->hourly()
        ->sendOutputTo(storage_path('logs/webhook-health.log'));

    // Send email alert if failures detected
    $schedule->command('webhooks:check --email-alerts')
        ->everyThirtyMinutes();

    // Update health statistics daily
    $schedule->command('webhooks:update-stats')
        ->dailyAt('03:00');

    // Cleanup old logs (keep 30 days)
    $schedule->command('webhooks:cleanup-logs --days=30')
        ->weekly();
}
```

### 6.3 Prometheus Metrics (Optional)

**Expose metrics for Prometheus:**

```php
// app/Http/Controllers/MetricsController.php

public function webhookMetrics()
{
    $metrics = [
        '# HELP webhook_received_total Total webhooks received',
        '# TYPE webhook_received_total counter',
        'webhook_received_total ' . WebhookLog::count(),

        '# HELP webhook_failed_total Total webhooks failed',
        '# TYPE webhook_failed_total counter',
        'webhook_failed_total ' . WebhookLog::where('status', 'failed')->count(),

        '# HELP webhook_processing_seconds Webhook processing time',
        '# TYPE webhook_processing_seconds histogram',
        // ... calculate percentiles
    ];

    return response(implode("\n", $metrics))
        ->header('Content-Type', 'text/plain');
}
```

---

## 7. Troubleshooting Guide

### 7.1 Common Issues

**Issue 1: Webhooks not being received**

```bash
# Symptoms:
# - No webhook_logs entries
# - Updates in МойСклад not triggering webhooks

# Diagnosis:
# 1. Check webhooks are installed
SELECT * FROM webhooks WHERE account_id = 'your-account-id';

# 2. Check webhook URL is accessible
curl https://app.cavaleria.ru/api/webhooks/receive

# 3. Check МойСклад webhook status
# Go to: https://api.moysklad.ru/api/remap/1.2/entity/webhook
# Verify webhooks are active

# Solution:
# Reinstall webhooks
php artisan webhooks:setup --account-id=your-account-id
```

**Issue 2: Webhooks received but not processed**

```bash
# Symptoms:
# - webhook_logs have status=pending forever
# - No sync tasks created

# Diagnosis:
# 1. Check queue worker is running
./monitor-queue.sh

# 2. Check for failed jobs
SELECT * FROM failed_jobs WHERE payload LIKE '%ProcessWebhookJob%';

# 3. Check logs for errors
tail -100 storage/logs/webhook.log | grep ERROR

# Solution:
# Restart queue worker
./restart-queue.sh

# Retry failed webhooks
php artisan queue:retry all
```

**Issue 3: High failure rate (>10%)**

```bash
# Symptoms:
# - webhook_health_stats shows high failure_rate
# - Many webhook_logs with status=failed

# Diagnosis:
# Check error messages
SELECT error_message, COUNT(*) as count
FROM webhook_logs
WHERE status='failed' AND created_at > NOW() - INTERVAL '24 hours'
GROUP BY error_message
ORDER BY count DESC;

# Common errors:
# - "Account not found" → Missing account in database
# - "Invalid entity ID" → Malformed webhook payload
# - "Timeout" → МойСклад API slow/unavailable
# - "Filter check failed" → Error loading entity to check filter

# Solution:
# Fix underlying issue based on error type
# Requeue failed webhooks
UPDATE webhook_logs SET status='pending' WHERE status='failed' AND ...;
```

**Issue 4: Cycle detected (infinite loop)**

```bash
# Symptoms:
# - Same entity updated repeatedly
# - Webhook → sync → webhook → sync loop

# Diagnosis:
# Check if X-Lognex-WebHook-DisableByPrefix header is being sent
tail -f storage/logs/sync.log | grep "X-Lognex-WebHook-DisableByPrefix"

# Check webhook logs for same entity
SELECT * FROM webhook_logs
WHERE payload::text LIKE '%entity-id-here%'
ORDER BY created_at DESC
LIMIT 20;

# Solution:
# Ensure MoySkladService always sends header:
# X-Lognex-WebHook-DisableByPrefix: webhook-{appId}
```

**Issue 5: Duplicate sync tasks created**

```bash
# Symptoms:
# - Same entity synced multiple times
# - Multiple identical entries in sync_queue

# Diagnosis:
# Check for duplicate webhooks
SELECT request_id, COUNT(*) as count
FROM webhook_logs
GROUP BY request_id
HAVING COUNT(*) > 1;

# Check if idempotency is working
SELECT * FROM webhook_logs WHERE request_id='duplicate-id';

# Solution:
# Ensure WebhookReceiverService checks requestId:
# - Use firstOrCreate with request_id
# - Return existing log if found
```

### 7.2 Debug Mode

**Enable detailed webhook logging:**

```env
# .env
LOG_LEVEL=debug
WEBHOOK_DEBUG=true
```

**View detailed webhook processing:**

```bash
tail -f storage/logs/webhook.log

# Output example:
# [2025-10-29 10:15:23] DEBUG: Webhook received
#   Request ID: abc-123-def
#   Account ID: uuid-456
#   Entity Type: product
#   Action: UPDATE
#   Events: 5
#
# [2025-10-29 10:15:23] DEBUG: Processing webhook abc-123-def
#   Main account: Yes
#   Child accounts: 3
#   Filter enabled: Yes
#
# [2025-10-29 10:15:24] DEBUG: Created sync tasks
#   Tasks created: 15 (5 entities × 3 child accounts)
#   Priority: 10
#   Batch: No
```

### 7.3 Emergency Procedures

**Disable all webhooks (emergency stop):**

```bash
# Disable webhook processing globally
php artisan down --message="Webhook maintenance"

# Delete all webhooks from МойСклад
php artisan webhooks:delete-all --confirm

# Stop queue worker
sudo supervisorctl stop laravel-worker:*
```

**Re-enable after fixing issue:**

```bash
# Restart queue worker
sudo supervisorctl start laravel-worker:*

# Reinstall webhooks
php artisan webhooks:setup --all-accounts

# Bring application back up
php artisan up
```

---

## 8. Security Testing

### 8.1 Unauthorized Access Test

```bash
# Try to access admin endpoints without authentication
curl -X GET https://app.cavaleria.ru/api/admin/webhooks

# Expected: 401 Unauthorized

# Try to access with invalid context key
curl -X GET https://app.cavaleria.ru/api/admin/webhooks \
  -H "X-Context-Key: invalid-key"

# Expected: 401 Unauthorized
```

### 8.2 Payload Injection Test

```bash
# Try SQL injection in webhook payload
curl -X POST https://app.cavaleria.ru/api/webhooks/receive \
  -H "Content-Type: application/json" \
  -d '{
    "events": [{
      "action": "UPDATE; DROP TABLE webhook_logs;--",
      "meta": {
        "href": "https://api.moysklad.ru/api/remap/1.2/entity/product/123",
        "type": "product"
      }
    }]
  }'

# Expected: 400 Bad Request (validation fails)
# webhook_logs table should NOT be dropped
```

### 8.3 Rate Limiting Test

```bash
# Send 1000 webhooks rapidly
for i in {1..1000}; do
  curl -X POST https://app.cavaleria.ru/api/webhooks/receive \
    -H "Content-Type: application/json" \
    -H "X-Request-Id: rate-limit-test-$i" \
    -d @webhook-payload.json &
done

# Expected:
# - First 100 requests: 200 OK
# - Remaining requests: 429 Too Many Requests (if rate limiting enabled)
```

---

## 9. Rollback Plan

### 9.1 Rollback Webhook System

**If webhooks cause issues, rollback to batch sync:**

```bash
# 1. Delete all webhooks
php artisan webhooks:delete-all --confirm

# 2. Update sync_settings
UPDATE sync_settings SET webhook_enabled = false;

# 3. Resume batch sync (manual trigger)
# Users will use "Sync All" button instead

# 4. Monitor for 24 hours
tail -f storage/logs/sync.log
```

### 9.2 Gradual Rollout

**Enable webhooks for one account first:**

```bash
# 1. Enable for test account only
php artisan webhooks:setup --account-id=test-account-uuid

# 2. Monitor for 48 hours
php artisan webhooks:check --account-id=test-account-uuid

# 3. If successful, enable for more accounts
php artisan webhooks:setup --account-id=production-account-1-uuid
# ... wait 24 hours, monitor ...
php artisan webhooks:setup --all-accounts
```

---

## 10. Testing Checklist

### Pre-Deployment Checklist

- [ ] All unit tests pass (`php artisan test --filter=Unit/Services/Webhook`)
- [ ] All integration tests pass (`php artisan test --filter=Feature/Webhook`)
- [ ] Manual testing completed for all scenarios
- [ ] Performance testing shows <100ms webhook receive time
- [ ] Health check command works (`php artisan webhooks:check`)
- [ ] Scheduler tasks configured (`php artisan schedule:list`)
- [ ] Queue worker running (`./monitor-queue.sh`)
- [ ] Logs are being written (`tail -f storage/logs/webhook.log`)
- [ ] Database migrations run (`php artisan migrate`)
- [ ] Security testing passed (no vulnerabilities)

### Post-Deployment Checklist

- [ ] Webhooks installed on test account
- [ ] Test webhook received and processed
- [ ] Sync task created in queue
- [ ] Queue worker processes task
- [ ] Entity synchronized to child account
- [ ] Admin dashboard shows correct health status
- [ ] Logs show no errors
- [ ] Monitor for 24 hours
- [ ] Enable for production accounts

---

## Summary

This testing guide covers:

1. **Unit Testing** - WebhookReceiverService, WebhookProcessorService with 80%+ coverage
2. **Integration Testing** - End-to-end webhook receive → task creation flow
3. **Manual Testing** - Step-by-step scenarios for all webhook types
4. **Performance Testing** - Load testing, batch processing, database optimization
5. **Monitoring** - Health checks, automated alerts, Prometheus metrics
6. **Troubleshooting** - Common issues with diagnosis and solutions
7. **Security Testing** - Unauthorized access, payload injection, rate limiting
8. **Rollback Plan** - Emergency procedures and gradual rollout strategy
9. **Checklists** - Pre/post-deployment verification

**Critical Success Metrics:**
- Webhook receive time: <100ms
- Webhook processing time: <5 seconds
- Failure rate: <5%
- No cycles (infinite loops)
- No duplicate tasks

**Next steps:**
- [18-webhook-system.md](18-webhook-system.md) - Back to overview with cross-references
