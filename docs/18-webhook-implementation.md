# Webhook Implementation - Jobs, Controllers, Routes, Commands

**Part 3 of 5:** Implementation Layer
**Related:** [Main](18-webhook-system.md) | [Services](18-webhook-services.md) | [Frontend](18-webhook-frontend.md) | [Testing](18-webhook-testing.md)

---

## 🎯 Quick Reference

**This file contains complete implementation:**

1. **Jobs** (2 classes) - Async webhook processing and setup
2. **Controllers** (2 classes) - Webhook receiver and admin API
3. **Routes** - API endpoints configuration
4. **Artisan Commands** (4 commands) - CLI tools for management
5. **Scheduler Configuration** - Automated tasks

**Total:** ~1,500 lines of production-ready code

---

## 📚 Table of Contents

1. [Jobs](#1-jobs)
   - ProcessWebhookJob
   - SetupAccountWebhooksJob
2. [Controllers](#2-controllers)
   - WebhookController
   - WebhookManagementController
3. [Routes](#3-routes)
4. [Artisan Commands](#4-artisan-commands)
   - webhooks:setup
   - webhooks:check
   - webhooks:cleanup-logs
   - webhooks:update-stats
5. [Scheduler Configuration](#5-scheduler-configuration)

---

## 1. Jobs

### 🎯 IMPLEMENTATION_STEP: Phase 3 - Jobs

### 1.1. ProcessWebhookJob

**📝 LOCATION:** `app/Jobs/ProcessWebhookJob.php`

**📝 RESPONSIBILITY:** Async processing of webhook (parse events → create sync_queue tasks)

**⚠️ CRITICAL:** This job processes webhook asynchronously after fast response to МойСклад

```php
<?php

namespace App\Jobs;

use App\Models\WebhookLog;
use App\Services\WebhookProcessorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for async webhook processing
 *
 * Flow:
 * 1. Load WebhookLog from database
 * 2. Call WebhookProcessorService to process events
 * 3. Create sync_queue tasks
 * 4. Update webhook_log status
 *
 * IMPORTANT: This runs AFTER webhook response sent to МойСклад
 * Can take 5-30 seconds (filter checks, API calls)
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry settings
     */
    public $tries = 3;
    public $timeout = 120; // 2 minutes

    /**
     * Webhook log ID to process
     */
    protected int $webhookLogId;

    /**
     * Create a new job instance
     *
     * @param int $webhookLogId
     */
    public function __construct(int $webhookLogId)
    {
        $this->webhookLogId = $webhookLogId;
    }

    /**
     * Execute the job
     *
     * @param WebhookProcessorService $processor
     * @return void
     */
    public function handle(WebhookProcessorService $processor): void
    {
        $startTime = microtime(true);

        // Load webhook log
        $webhookLog = WebhookLog::find($this->webhookLogId);

        if (!$webhookLog) {
            Log::error('ProcessWebhookJob: WebhookLog not found', [
                'webhook_log_id' => $this->webhookLogId
            ]);
            return;
        }

        Log::info('ProcessWebhookJob starting', [
            'job_id' => $this->job->getJobId(),
            'webhook_log_id' => $this->webhookLogId,
            'request_id' => $webhookLog->request_id,
            'entity_type' => $webhookLog->entity_type,
            'action' => $webhookLog->action,
            'events_count' => $webhookLog->events_count
        ]);

        try {
            // Process webhook (may throw exception)
            $result = $processor->process($webhookLog);

            $duration = (int)((microtime(true) - $startTime) * 1000); // ms

            Log::info('ProcessWebhookJob completed successfully', [
                'job_id' => $this->job->getJobId(),
                'webhook_log_id' => $this->webhookLogId,
                'created_tasks' => $result['created_tasks'],
                'skipped' => $result['skipped'],
                'duration_ms' => $duration
            ]);

        } catch (\Throwable $e) {
            $duration = (int)((microtime(true) - $startTime) * 1000);

            Log::error('ProcessWebhookJob failed', [
                'job_id' => $this->job->getJobId(),
                'webhook_log_id' => $this->webhookLogId,
                'attempt' => $this->attempts(),
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Re-throw to trigger retry
            throw $e;
        }
    }

    /**
     * Handle job failure (after all retries exhausted)
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessWebhookJob failed permanently', [
            'webhook_log_id' => $this->webhookLogId,
            'attempts' => $this->tries,
            'error' => $exception->getMessage()
        ]);

        // Update webhook log status to failed
        $webhookLog = WebhookLog::find($this->webhookLogId);
        if ($webhookLog && $webhookLog->status !== 'failed') {
            $webhookLog->markAsFailed('Job failed: ' . $exception->getMessage());
        }
    }
}
```

### 💡 Key Design Points:

**1. Retry Strategy:**
- 3 attempts with exponential backoff (Laravel default)
- If all fail → call `failed()` method
- Update webhook_log status to 'failed'

**2. Timeout:**
- 120 seconds (2 minutes) per attempt
- Sufficient for filter checks + API calls

**3. Logging:**
- Log start, success, failure
- Include timing (duration_ms)
- Include attempt number on failure

### ✅ VALIDATION:

```bash
php artisan tinker
>>> $log = \App\Models\WebhookLog::first();
>>> \App\Jobs\ProcessWebhookJob::dispatch($log->id);
>>> # Check logs
tail -f storage/logs/laravel.log | grep ProcessWebhookJob
```

---

### 1.2. SetupAccountWebhooksJob

**📝 LOCATION:** `app/Jobs/SetupAccountWebhooksJob.php`

**📝 RESPONSIBILITY:** Async webhook installation (can take 30-60s for 15 webhooks)

```php
<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\WebhookSetupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for async webhook setup
 *
 * Dispatched when:
 * - User selects account_type in UI
 * - Admin clicks "Reinstall Webhooks"
 * - Artisan command: webhooks:setup
 *
 * Can take 30-60 seconds to install 15 webhooks
 */
class SetupAccountWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry settings
     */
    public $tries = 3;
    public $timeout = 300; // 5 minutes

    /**
     * Account and type
     */
    protected string $accountId;
    protected string $accountType;

    /**
     * Create a new job instance
     *
     * @param string $accountId
     * @param string $accountType 'main' or 'child'
     */
    public function __construct(string $accountId, string $accountType)
    {
        $this->accountId = $accountId;
        $this->accountType = $accountType;
    }

    /**
     * Execute the job
     *
     * @param WebhookSetupService $setupService
     * @return void
     */
    public function handle(WebhookSetupService $setupService): void
    {
        $startTime = microtime(true);

        Log::info('SetupAccountWebhooksJob starting', [
            'job_id' => $this->job->getJobId(),
            'account_id' => $this->accountId,
            'account_type' => $this->accountType
        ]);

        // Load account
        $account = Account::where('account_id', $this->accountId)->first();

        if (!$account) {
            Log::error('SetupAccountWebhooksJob: Account not found', [
                'account_id' => $this->accountId
            ]);
            return;
        }

        try {
            // Setup webhooks
            $result = $setupService->setupWebhooksForAccount($account, $this->accountType);

            $duration = (int)((microtime(true) - $startTime) * 1000); // ms

            Log::info('SetupAccountWebhooksJob completed successfully', [
                'job_id' => $this->job->getJobId(),
                'account_id' => $this->accountId,
                'account_type' => $this->accountType,
                'created' => $result['created'],
                'errors_count' => count($result['errors']),
                'duration_ms' => $duration
            ]);

            if (!empty($result['errors'])) {
                Log::warning('SetupAccountWebhooksJob: Some webhooks failed', [
                    'account_id' => $this->accountId,
                    'errors' => $result['errors']
                ]);
            }

        } catch (\Throwable $e) {
            $duration = (int)((microtime(true) - $startTime) * 1000);

            Log::error('SetupAccountWebhooksJob failed', [
                'job_id' => $this->job->getJobId(),
                'account_id' => $this->accountId,
                'account_type' => $this->accountType,
                'attempt' => $this->attempts(),
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Handle job failure
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SetupAccountWebhooksJob failed permanently', [
            'account_id' => $this->accountId,
            'account_type' => $this->accountType,
            'attempts' => $this->tries,
            'error' => $exception->getMessage()
        ]);

        // TODO: Notify admin via email/Slack
    }
}
```

### 💡 Key Design Points:

**1. Longer Timeout:**
- 300 seconds (5 minutes) - installing 15 webhooks takes time
- Each webhook = 1 API call (~1-2 seconds)

**2. Partial Success:**
- If some webhooks fail → log warning but don't throw
- Job completes successfully even if some webhooks failed
- Errors logged in result array

**3. Usage:**
- Dispatched from UI when user selects account_type
- Dispatched from artisan command
- Dispatched from admin panel

### ✅ VALIDATION:

```bash
php artisan tinker
>>> $account = \App\Models\Account::first();
>>> \App\Jobs\SetupAccountWebhooksJob::dispatch($account->account_id, 'main');
>>> # Check progress
tail -f storage/logs/laravel.log | grep SetupAccountWebhooksJob
```

---

## 2. Controllers

### 🎯 IMPLEMENTATION_STEP: Phase 4 - Controllers

### 2.1. WebhookController (Public Receiver)

**📝 LOCATION:** `app/Http/Controllers/Api/WebhookController.php`

**📝 RESPONSIBILITY:** Receive webhooks from МойСклад (public endpoint, no auth)

**⚠️ CRITICAL:** MUST respond within 100ms (МойСклад expects < 1500ms)

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebhookReceiverService;
use App\Jobs\ProcessWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Controller for receiving webhooks from МойСклад
 *
 * CRITICAL: This endpoint must respond FAST (< 100ms)
 * МойСклад expects response within 1500ms or will retry
 *
 * Flow:
 * 1. Validate payload (fast)
 * 2. Save to webhook_logs (fast)
 * 3. Dispatch ProcessWebhookJob (fast)
 * 4. Return 200 OK immediately
 */
class WebhookController extends Controller
{
    protected WebhookReceiverService $receiverService;

    public function __construct(WebhookReceiverService $receiverService)
    {
        $this->receiverService = $receiverService;
    }

    /**
     * Receive webhook from МойСклад
     *
     * POST /api/webhooks/receive?requestId={uuid}
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function receive(Request $request): JsonResponse
    {
        $startTime = microtime(true);

        try {
            // Get requestId from query parameter
            $requestId = $request->query('requestId');

            if (!$requestId) {
                Log::warning('Webhook received without requestId', [
                    'ip' => $request->ip(),
                    'url' => $request->fullUrl()
                ]);

                return response()->json([
                    'error' => 'requestId parameter is required'
                ], 400);
            }

            // Get payload
            $payload = $request->all();

            // Validate payload structure
            if (!$this->receiverService->validate($payload)) {
                Log::warning('Webhook received with invalid payload', [
                    'request_id' => $requestId,
                    'ip' => $request->ip(),
                    'payload_keys' => array_keys($payload)
                ]);

                return response()->json([
                    'error' => 'Invalid payload structure'
                ], 400);
            }

            // Save to database (fast!)
            $webhookLog = $this->receiverService->receive($payload, $requestId);

            // Dispatch job for async processing
            ProcessWebhookJob::dispatch($webhookLog->id);

            $duration = (int)((microtime(true) - $startTime) * 1000); // ms

            Log::info('Webhook received and queued', [
                'webhook_log_id' => $webhookLog->id,
                'request_id' => $requestId,
                'entity_type' => $webhookLog->entity_type,
                'action' => $webhookLog->action,
                'events_count' => $webhookLog->events_count,
                'duration_ms' => $duration
            ]);

            // Return 200 OK immediately
            return response()->json([
                'status' => 'received',
                'webhook_log_id' => $webhookLog->id
            ], 200);

        } catch (\Throwable $e) {
            $duration = (int)((microtime(true) - $startTime) * 1000);

            Log::error('Webhook receive failed', [
                'request_id' => $request->query('requestId'),
                'ip' => $request->ip(),
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return 200 even on error (prevent МойСклад retry)
            // Error logged for debugging
            return response()->json([
                'status' => 'error',
                'message' => 'Internal error (logged)'
            ], 200);
        }
    }
}
```

### 💡 Key Design Points:

**1. Performance:**
- Target: < 100ms response time
- No external API calls
- Single database INSERT
- Minimal validation

**2. Error Handling:**
- Return 200 even on error (prevent unnecessary retries)
- Log all errors for debugging
- Invalid payload → 400 (only for validation errors)

**3. Idempotency:**
- requestId checked in WebhookReceiverService
- Duplicate webhooks ignored

### ✅ VALIDATION:

```bash
# Test with curl
curl -X POST "http://localhost/api/webhooks/receive?requestId=test-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{
    "events": [{
      "meta": {"type": "product", "href": "https://api.moysklad.ru/api/remap/1.2/entity/product/test-uuid"},
      "action": "UPDATE",
      "accountId": "test-account-uuid",
      "updatedFields": ["salePrices"]
    }]
  }'

# Check response (should be < 100ms)
# Check log created
php artisan tinker
>>> \App\Models\WebhookLog::latest()->first()
```

---

### 2.2. WebhookManagementController (Admin API)

**📝 LOCATION:** `app/Http/Controllers/Admin/WebhookManagementController.php`

**📝 RESPONSIBILITY:** Admin API for webhook management

**Complete Code:** (Due to length, showing key methods)

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Models\Account;
use App\Services\WebhookSetupService;
use App\Services\WebhookHealthService;
use App\Jobs\SetupAccountWebhooksJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Admin controller for webhook management
 *
 * Endpoints:
 * - GET /api/admin/webhooks - List all webhooks with stats
 * - POST /api/admin/webhooks/setup - Setup webhooks for account
 * - POST /api/admin/webhooks/reinstall - Reinstall webhooks
 * - POST /api/admin/webhooks/{id}/toggle - Enable/disable
 * - DELETE /api/admin/webhooks/{id} - Delete webhook
 * - GET /api/admin/webhooks/logs - Detailed logs
 * - GET /api/admin/webhooks/statistics - Stats for charts
 * - GET /api/admin/webhooks/alerts - Failing webhooks
 */
class WebhookManagementController extends Controller
{
    protected WebhookSetupService $setupService;
    protected WebhookHealthService $healthService;

    public function __construct(
        WebhookSetupService $setupService,
        WebhookHealthService $healthService
    ) {
        $this->setupService = $setupService;
        $this->healthService = $healthService;
    }

    /**
     * Get list of webhooks with statistics
     *
     * GET /api/admin/webhooks?account_id={uuid}
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $accountId = $request->query('account_id');

        $webhooks = $this->healthService->getHealthSummary($accountId);

        return response()->json([
            'webhooks' => $webhooks,
            'total' => $webhooks->count(),
        ]);
    }

    /**
     * Setup webhooks for account
     *
     * POST /api/admin/webhooks/setup
     * Body: {"account_id": "uuid", "account_type": "main"}
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function setup(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => 'required|uuid',
            'account_type' => 'required|in:main,child',
        ]);

        $account = Account::where('account_id', $request->account_id)->first();

        if (!$account) {
            return response()->json([
                'error' => 'Account not found'
            ], 404);
        }

        // Dispatch job (async)
        SetupAccountWebhooksJob::dispatch($request->account_id, $request->account_type);

        return response()->json([
            'message' => 'Webhook setup started (processing in background)',
            'account_id' => $request->account_id,
            'account_type' => $request->account_type,
        ]);
    }

    /**
     * Reinstall webhooks (delete old + install new)
     *
     * POST /api/admin/webhooks/reinstall
     * Body: {"account_id": "uuid", "account_type": "main"}
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function reinstall(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => 'required|uuid',
            'account_type' => 'required|in:main,child',
        ]);

        $account = Account::where('account_id', $request->account_id)->first();

        if (!$account) {
            return response()->json([
                'error' => 'Account not found'
            ], 404);
        }

        try {
            // Synchronous (for immediate feedback)
            $result = $this->setupService->reinstallWebhooks($account, $request->account_type);

            return response()->json([
                'message' => 'Webhooks reinstalled',
                'deleted' => $result['deleted'],
                'created' => $result['created'],
                'errors' => $result['errors'],
            ]);

        } catch (\Throwable $e) {
            Log::error('Webhook reinstall failed', [
                'account_id' => $request->account_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle webhook enabled status
     *
     * POST /api/admin/webhooks/{webhook}/toggle
     *
     * @param Webhook $webhook
     * @return JsonResponse
     */
    public function toggle(Webhook $webhook): JsonResponse
    {
        $webhook->update(['enabled' => !$webhook->enabled]);

        return response()->json([
            'message' => 'Webhook toggled',
            'webhook' => [
                'id' => $webhook->id,
                'entity_type' => $webhook->entity_type,
                'action' => $webhook->action,
                'enabled' => $webhook->enabled,
            ],
        ]);
    }

    /**
     * Delete webhook
     *
     * DELETE /api/admin/webhooks/{webhook}
     *
     * @param Webhook $webhook
     * @return JsonResponse
     */
    public function destroy(Webhook $webhook): JsonResponse
    {
        try {
            $account = Account::where('account_id', $webhook->account_id)->first();

            // Delete from МойСклад
            $this->setupService
                ->getMoySkladService()
                ->setAccessToken($account->access_token)
                ->setAccountId($webhook->account_id)
                ->delete("entity/webhook/{$webhook->moysklad_webhook_id}");

            // Delete from database
            $webhook->delete();

            return response()->json([
                'message' => 'Webhook deleted successfully'
            ]);

        } catch (\Throwable $e) {
            Log::error('Webhook deletion failed', [
                'webhook_id' => $webhook->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed webhook logs
     *
     * GET /api/admin/webhooks/logs?account_id=&entity_type=&status=&limit=100
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logs(Request $request): JsonResponse
    {
        $filters = [
            'account_id' => $request->query('account_id'),
            'entity_type' => $request->query('entity_type'),
            'status' => $request->query('status'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        $limit = (int) $request->query('limit', 100);

        $logs = $this->healthService->getDetailedLogs($filters, $limit);

        return response()->json([
            'logs' => $logs,
            'total' => $logs->count(),
        ]);
    }

    /**
     * Get aggregated statistics
     *
     * GET /api/admin/webhooks/statistics?period=7days&account_id=
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        $period = $request->query('period', '7days');
        $accountId = $request->query('account_id');

        $stats = $this->healthService->getStatistics($period, $accountId);

        return response()->json($stats);
    }

    /**
     * Get alerts (failing webhooks)
     *
     * GET /api/admin/webhooks/alerts
     *
     * @return JsonResponse
     */
    public function alerts(): JsonResponse
    {
        $alerts = $this->healthService->getAlerts();

        return response()->json([
            'alerts' => $alerts,
            'total' => $alerts->count(),
        ]);
    }
}
```

### 💡 Key Methods:

1. **index()** - List webhooks with stats
2. **setup()** - Install webhooks (async via job)
3. **reinstall()** - Delete + install (sync for immediate feedback)
4. **toggle()** - Enable/disable webhook
5. **destroy()** - Delete webhook from МойСклад + DB
6. **logs()** - Detailed logs with filters
7. **statistics()** - Aggregated stats for charts
8. **alerts()** - Failing webhooks (failure_rate > 10%)

---

## 3. Routes

**📝 LOCATION:** `routes/api.php`

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Admin\WebhookManagementController;

// ============================================================================
// PUBLIC WEBHOOK RECEIVER (no auth)
// ============================================================================

/**
 * Receive webhooks from МойСклад
 *
 * POST /api/webhooks/receive?requestId={uuid}
 *
 * PUBLIC endpoint (МойСклад doesn't send auth headers)
 * Must respond within 100ms
 */
Route::post('/webhooks/receive', [WebhookController::class, 'receive'])
    ->name('webhooks.receive');

// ============================================================================
// ADMIN WEBHOOK MANAGEMENT (auth + admin middleware)
// ============================================================================

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin/webhooks')->group(function () {

    /**
     * List all webhooks with statistics
     *
     * GET /api/admin/webhooks?account_id={uuid}
     */
    Route::get('/', [WebhookManagementController::class, 'index'])
        ->name('admin.webhooks.index');

    /**
     * Setup webhooks for account (async)
     *
     * POST /api/admin/webhooks/setup
     * Body: {"account_id": "uuid", "account_type": "main"}
     */
    Route::post('/setup', [WebhookManagementController::class, 'setup'])
        ->name('admin.webhooks.setup');

    /**
     * Reinstall webhooks (sync)
     *
     * POST /api/admin/webhooks/reinstall
     * Body: {"account_id": "uuid", "account_type": "main"}
     */
    Route::post('/reinstall', [WebhookManagementController::class, 'reinstall'])
        ->name('admin.webhooks.reinstall');

    /**
     * Toggle webhook enabled status
     *
     * POST /api/admin/webhooks/{webhook}/toggle
     */
    Route::post('/{webhook}/toggle', [WebhookManagementController::class, 'toggle'])
        ->name('admin.webhooks.toggle');

    /**
     * Delete webhook
     *
     * DELETE /api/admin/webhooks/{webhook}
     */
    Route::delete('/{webhook}', [WebhookManagementController::class, 'destroy'])
        ->name('admin.webhooks.destroy');

    /**
     * Get detailed webhook logs
     *
     * GET /api/admin/webhooks/logs?account_id=&entity_type=&status=&limit=100
     */
    Route::get('/logs', [WebhookManagementController::class, 'logs'])
        ->name('admin.webhooks.logs');

    /**
     * Get aggregated statistics
     *
     * GET /api/admin/webhooks/statistics?period=7days&account_id=
     */
    Route::get('/statistics', [WebhookManagementController::class, 'statistics'])
        ->name('admin.webhooks.statistics');

    /**
     * Get alerts (failing webhooks)
     *
     * GET /api/admin/webhooks/alerts
     */
    Route::get('/alerts', [WebhookManagementController::class, 'alerts'])
        ->name('admin.webhooks.alerts');
});
```

### 💡 Route Design:

**Public Routes:**
- `/api/webhooks/receive` - No auth (МойСклад doesn't authenticate)

**Admin Routes:**
- Prefix: `/api/admin/webhooks`
- Middleware: `auth:sanctum` + `admin`
- All management operations

---

## 4. Artisan Commands

### 🎯 IMPLEMENTATION_STEP: Phase 5 - Commands

### 4.1. webhooks:setup

**📝 LOCATION:** `app/Console/Commands/WebhooksSetupCommand.php`

**Complete Code:** [See Services Document](18-webhook-services.md) for WebhookSetupService

```php
<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Services\WebhookSetupService;
use Illuminate\Console\Command;

/**
 * Setup webhooks for accounts
 *
 * Usage:
 * php artisan webhooks:setup                        # All accounts
 * php artisan webhooks:setup --account=UUID --type=main  # Specific account
 */
class WebhooksSetupCommand extends Command
{
    protected $signature = 'webhooks:setup
                            {--account= : Specific account ID}
                            {--type= : Account type (main/child) - required if --account specified}';

    protected $description = 'Setup webhooks for accounts';

    public function handle(WebhookSetupService $setupService): int
    {
        if ($this->option('account')) {
            return $this->setupForAccount($setupService);
        }

        return $this->setupForAllAccounts($setupService);
    }

    protected function setupForAccount(WebhookSetupService $setupService): int
    {
        $accountId = $this->option('account');
        $accountType = $this->option('type');

        if (!$accountType) {
            $this->error('--type option is required when --account is specified');
            $this->info('Example: php artisan webhooks:setup --account=UUID --type=main');
            return 1;
        }

        if (!in_array($accountType, ['main', 'child'])) {
            $this->error('Invalid account type. Must be "main" or "child"');
            return 1;
        }

        $account = Account::where('account_id', $accountId)->first();

        if (!$account) {
            $this->error("Account not found: {$accountId}");
            return 1;
        }

        $this->info("Setting up webhooks for: {$account->account_name}");
        $this->info("Account type: {$accountType}");
        $this->newLine();

        $result = $setupService->setupWebhooksForAccount($account, $accountType);

        $this->info("✓ Created: {$result['created']} webhooks");

        if (!empty($result['errors'])) {
            $this->warn("⚠ Errors: " . count($result['errors']));
            foreach ($result['errors'] as $error) {
                $this->error("  - {$error['entity_type']} {$error['action']}: {$error['error']}");
            }
            return 1;
        }

        $this->info('✓ All webhooks setup successfully!');
        return 0;
    }

    protected function setupForAllAccounts(WebhookSetupService $setupService): int
    {
        // Get all accounts with account_type configured
        $accounts = Account::join('sync_settings', 'accounts.account_id', '=', 'sync_settings.account_id')
            ->whereNotNull('sync_settings.account_type')
            ->select('accounts.*', 'sync_settings.account_type')
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('No accounts with account_type configured');
            $this->info('Users must select account type in UI first');
            return 0;
        }

        $this->info("Found {$accounts->count()} accounts with configured type");
        $this->newLine();

        $bar = $this->output->createProgressBar($accounts->count());
        $bar->start();

        $totalCreated = 0;
        $totalErrors = 0;

        foreach ($accounts as $account) {
            $result = $setupService->setupWebhooksForAccount($account, $account->account_type);

            $totalCreated += $result['created'];
            $totalErrors += count($result['errors']);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Setup completed!");
        $this->info("✓ Total created: {$totalCreated}");

        if ($totalErrors > 0) {
            $this->warn("⚠ Total errors: {$totalErrors}");
            $this->info('Check logs for details: tail -f storage/logs/laravel.log');
            return 1;
        }

        return 0;
    }
}
```

---

### 4.2. webhooks:check

**📝 LOCATION:** `app/Console/Commands/WebhooksCheckCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Services\WebhookHealthService;
use Illuminate\Console\Command;

/**
 * Check webhook health
 *
 * Usage:
 * php artisan webhooks:check              # Show all webhooks
 * php artisan webhooks:check --alerts     # Show only alerts
 * php artisan webhooks:check --account=UUID  # Filter by account
 */
class WebhooksCheckCommand extends Command
{
    protected $signature = 'webhooks:check
                            {--account= : Filter by account ID}
                            {--alerts : Show only alerts (failure_rate > 10%)}';

    protected $description = 'Check webhook health and show alerts';

    public function handle(WebhookHealthService $healthService): int
    {
        if ($this->option('alerts')) {
            return $this->showAlerts($healthService);
        }

        return $this->showHealthSummary($healthService);
    }

    protected function showHealthSummary(WebhookHealthService $healthService): int
    {
        $accountId = $this->option('account');

        $this->info('Webhook Health Summary');
        $this->newLine();

        $webhooks = $healthService->getHealthSummary($accountId);

        if ($webhooks->isEmpty()) {
            $this->warn('No webhooks found');
            return 0;
        }

        $headers = [
            'Account',
            'Type',
            'Entity',
            'Action',
            'Status',
            'Received',
            'Failed',
            'Rate %',
            'Last Triggered'
        ];

        $rows = $webhooks->map(function ($webhook) {
            $statusColor = match($webhook['status']) {
                'healthy' => 'green',
                'warning' => 'yellow',
                'critical' => 'red',
                'disabled' => 'gray',
                default => 'white'
            };

            return [
                substr($webhook['account_id'], 0, 8) . '...',
                $webhook['account_type'],
                $webhook['entity_type'],
                $webhook['action'],
                "<fg={$statusColor}>{$webhook['status']}</>",
                $webhook['total_received'],
                $webhook['total_failed'],
                $webhook['failure_rate'] . '%',
                $webhook['last_triggered_at'] ?? 'Never',
            ];
        })->toArray();

        $this->table($headers, $rows);

        return 0;
    }

    protected function showAlerts(WebhookHealthService $healthService): int
    {
        $this->info('Webhook Alerts (failure_rate > 10%)');
        $this->newLine();

        $alerts = $healthService->getAlerts();

        if ($alerts->isEmpty()) {
            $this->info('✓ No alerts! All webhooks are healthy.');
            return 0;
        }

        $headers = [
            'Account',
            'Entity',
            'Action',
            'Severity',
            'Failure Rate',
            'Failed/Total',
            'Last Triggered'
        ];

        $rows = $alerts->map(function ($alert) {
            $severityColor = match($alert['severity']) {
                'critical' => 'red',
                'high' => 'yellow',
                default => 'white'
            };

            return [
                substr($alert['account_id'], 0, 8) . '...',
                $alert['entity_type'],
                $alert['action'],
                "<fg={$severityColor}>{$alert['severity']}</>",
                $alert['failure_rate'] . '%',
                "{$alert['total_failed']}/{$alert['total_received']}",
                $alert['last_triggered_at']?->diffForHumans() ?? 'Never',
            ];
        })->toArray();

        $this->table($headers, $rows);

        $this->newLine();
        $this->warn("⚠ Found {$alerts->count()} alerts!");
        $this->info('Action: Check logs or reinstall webhooks');

        return 1; // Exit code 1 indicates alerts present
    }
}
```

---

### 4.3. webhooks:cleanup-logs

**📝 LOCATION:** `app/Console/Commands/WebhooksCleanupLogsCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\WebhookLog;
use Illuminate\Console\Command;

/**
 * Cleanup old webhook logs
 *
 * Usage:
 * php artisan webhooks:cleanup-logs --days=30          # Delete logs older than 30 days
 * php artisan webhooks:cleanup-logs --days=30 --dry-run # Show what would be deleted
 */
class WebhooksCleanupLogsCommand extends Command
{
    protected $signature = 'webhooks:cleanup-logs
                            {--days=30 : Delete logs older than N days}
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Cleanup old webhook logs';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');

        $cutoffDate = now()->subDays($days);

        $this->info("Cleaning up webhook logs older than {$days} days");
        $this->info("Cutoff date: {$cutoffDate->toDateString()}");
        $this->newLine();

        $query = WebhookLog::where('created_at', '<', $cutoffDate);

        $count = $query->count();

        if ($count === 0) {
            $this->info('✓ No logs to delete');
            return 0;
        }

        $this->warn("Found {$count} logs to delete");
        $this->newLine();

        if ($dryRun) {
            $this->info('DRY RUN - No logs will be deleted');
            $this->newLine();

            // Show statistics
            $stats = WebhookLog::where('created_at', '<', $cutoffDate)
                ->selectRaw('entity_type, status, COUNT(*) as count')
                ->groupBy('entity_type', 'status')
                ->get();

            $this->table(
                ['Entity Type', 'Status', 'Count'],
                $stats->map(fn($s) => [$s->entity_type, $s->status, $s->count])->toArray()
            );

            return 0;
        }

        if (!$this->confirm('Do you want to proceed with deletion?')) {
            $this->info('Cancelled');
            return 0;
        }

        $deleted = $query->delete();

        $this->info("✓ Deleted {$deleted} logs");

        return 0;
    }
}
```

---

### 4.4. webhooks:update-stats

**📝 LOCATION:** `app/Console/Commands/WebhooksUpdateStatsCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Services\WebhookHealthService;
use Illuminate\Console\Command;

/**
 * Update webhook health statistics
 *
 * Aggregates webhook_logs into webhook_health_stats
 * Should run hourly via scheduler
 *
 * Usage:
 * php artisan webhooks:update-stats
 */
class WebhooksUpdateStatsCommand extends Command
{
    protected $signature = 'webhooks:update-stats';

    protected $description = 'Update webhook health statistics (runs hourly)';

    public function handle(WebhookHealthService $healthService): int
    {
        $this->info('Updating webhook health statistics...');

        $healthService->updateHealthStats();

        $this->info('✓ Statistics updated successfully');

        return 0;
    }
}
```

---

## 5. Scheduler Configuration

**📝 LOCATION:** `app/Console/Kernel.php`

```php
<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Register commands
     */
    protected $commands = [
        Commands\WebhooksSetupCommand::class,
        Commands\WebhooksCheckCommand::class,
        Commands\WebhooksCleanupLogsCommand::class,
        Commands\WebhooksUpdateStatsCommand::class,
    ];

    /**
     * Define the application's command schedule
     */
    protected function schedule(Schedule $schedule): void
    {
        // Existing schedules...

        // ================================================================
        // WEBHOOK SCHEDULES
        // ================================================================

        /**
         * Update webhook health statistics every hour
         *
         * Aggregates webhook_logs into webhook_health_stats for fast dashboard
         */
        $schedule->command('webhooks:update-stats')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        /**
         * Cleanup old webhook logs once per week
         *
         * Deletes logs older than 30 days to keep database size manageable
         */
        $schedule->command('webhooks:cleanup-logs --days=30')
            ->weekly()
            ->sundays()
            ->at('03:00')
            ->withoutOverlapping();

        /**
         * Check webhook health and send alerts (optional)
         *
         * Run hourly, send email if alerts found
         */
        $schedule->command('webhooks:check --alerts')
            ->hourly()
            ->withoutOverlapping()
            ->when(function () {
                // Only run if alerts exist
                return \App\Models\Webhook::where('enabled', true)
                    ->where('total_received', '>', 0)
                    ->get()
                    ->filter(fn($w) => $w->failure_rate > 10)
                    ->isNotEmpty();
            })
            ->onFailure(function () {
                // TODO: Send email notification to admin
            });
    }
}
```

### 💡 Schedule Design:

**1. Update Stats (Hourly):**
- Aggregate webhook_logs → webhook_health_stats
- Fast dashboard queries

**2. Cleanup Logs (Weekly):**
- Delete logs > 30 days
- Keep database size manageable
- Runs Sunday 3am (low traffic)

**3. Check Alerts (Hourly):**
- Check for failing webhooks
- Send email if found (TODO)
- Only runs if alerts exist (conditional)

---

## Summary

**Files Created:**

1. ✅ `ProcessWebhookJob.php` (~150 lines)
2. ✅ `SetupAccountWebhooksJob.php` (~100 lines)
3. ✅ `WebhookController.php` (~100 lines)
4. ✅ `WebhookManagementController.php` (~200 lines)
5. ✅ Routes configuration (~50 lines)
6. ✅ `WebhooksSetupCommand.php` (~150 lines)
7. ✅ `WebhooksCheckCommand.php` (~150 lines)
8. ✅ `WebhooksCleanupLogsCommand.php` (~100 lines)
9. ✅ `WebhooksUpdateStatsCommand.php` (~50 lines)
10. ✅ Kernel schedule configuration (~50 lines)

**Total:** ~1,100 lines of production-ready code

**Next Steps:**

1. Copy files to appropriate locations
2. Register commands in Kernel
3. Test endpoints with Postman/curl
4. Run commands to verify
5. Proceed to [Frontend](18-webhook-frontend.md) for Vue components

---

**Related Documents:**
- [Main System Overview](18-webhook-system.md)
- [Services Layer](18-webhook-services.md)
- [Frontend Components](18-webhook-frontend.md)
- [Testing & Monitoring](18-webhook-testing.md)

---

**END OF IMPLEMENTATION DOCUMENT**