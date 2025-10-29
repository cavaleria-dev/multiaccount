# Webhook Services - Complete Implementation

**Part 2 of 5:** Services Layer
**Related:** [Main Document](18-webhook-system.md) | [Implementation](18-webhook-implementation.md) | [Frontend](18-webhook-frontend.md) | [Testing](18-webhook-testing.md)

---

## 🎯 Quick Reference

**This file contains 4 services with complete code:**

1. **WebhookSetupService** - Install/delete webhooks via МойСклад API
2. **WebhookReceiverService** - Receive and validate incoming webhooks
3. **WebhookProcessorService** - Parse events → create sync_queue tasks
4. **WebhookHealthService** - Monitoring, statistics, alerts

**Total Lines:** ~2,000 lines of PHP code (ready to copy-paste)

---

## 1. WebhookSetupService

**📝 LOCATION:** `app/Services/WebhookSetupService.php`

**📝 RESPONSIBILITY:** Install/delete webhooks via МойСклад API

### Key Methods:

- `setupWebhooksForAccount()` - Install all webhooks for account
- `deleteWebhooksForAccount()` - Delete all webhooks
- `reinstallWebhooks()` - Delete old + install new
- `getWebhooksConfig()` - Get webhook list by account type

### Complete Code:

```php
<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Webhook;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing webhooks installation/deletion
 *
 * Responsibilities:
 * - Install webhooks in МойСклад via API
 * - Delete webhooks from МойСклад
 * - Reinstall webhooks (delete + install)
 * - Track webhooks in local database
 */
class WebhookSetupService
{
    protected MoySkladService $moySkladService;
    protected string $webhookUrl;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
        $this->webhookUrl = config('app.url') . '/api/webhooks/receive';
    }

    /**
     * Setup webhooks for account based on account type
     *
     * @param Account $account Account to setup webhooks for
     * @param string $accountType 'main' or 'child'
     * @return array ['created' => int, 'errors' => array]
     */
    public function setupWebhooksForAccount(Account $account, string $accountType): array
    {
        // Configure МойСклад service
        $this->moySkladService
            ->setAccessToken($account->access_token)
            ->setAccountId($account->account_id);

        // Get webhook configuration for account type
        $webhooksConfig = $this->getWebhooksConfig($accountType);

        Log::info('Starting webhook setup', [
            'account_id' => $account->account_id,
            'account_type' => $accountType,
            'webhooks_count' => count($webhooksConfig)
        ]);

        $created = 0;
        $errors = [];

        // Install each webhook
        foreach ($webhooksConfig as $config) {
            try {
                $this->createWebhook($account, $config);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'entity_type' => $config['entity_type'],
                    'action' => $config['action'],
                    'error' => $e->getMessage()
                ];

                Log::error('Failed to create webhook', [
                    'account_id' => $account->account_id,
                    'entity_type' => $config['entity_type'],
                    'action' => $config['action'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        Log::info('Webhook setup completed', [
            'account_id' => $account->account_id,
            'account_type' => $accountType,
            'created' => $created,
            'errors_count' => count($errors)
        ]);

        return [
            'created' => $created,
            'errors' => $errors
        ];
    }

    /**
     * Create single webhook in МойСклад and store in database
     *
     * @param Account $account
     * @param array $config ['entity_type', 'action', 'account_type']
     * @return void
     * @throws \Exception
     */
    protected function createWebhook(Account $account, array $config): void
    {
        // Check if webhook already exists
        $existing = Webhook::where([
            'account_id' => $account->account_id,
            'entity_type' => $config['entity_type'],
            'action' => $config['action'],
        ])->first();

        if ($existing) {
            Log::info('Webhook already exists, skipping', [
                'account_id' => $account->account_id,
                'entity_type' => $config['entity_type'],
                'action' => $config['action'],
                'webhook_id' => $existing->id
            ]);
            return;
        }

        // Prepare payload for МойСклад API
        $payload = [
            'url' => $this->webhookUrl,
            'action' => $config['action'],
            'entityType' => $config['entity_type'],
        ];

        // For UPDATE action, request field-level diff
        if ($config['action'] === 'UPDATE') {
            $payload['diffType'] = 'FIELDS';
        }

        Log::debug('Creating webhook in МойСклад', [
            'account_id' => $account->account_id,
            'payload' => $payload
        ]);

        // Create webhook via МойСклад API
        $response = $this->moySkladService->post('entity/webhook', $payload);

        if (!isset($response['id'])) {
            throw new \Exception('МойСклад API did not return webhook ID');
        }

        // Save webhook to database
        $webhook = Webhook::create([
            'account_id' => $account->account_id,
            'account_type' => $config['account_type'],
            'moysklad_webhook_id' => $response['id'],
            'entity_type' => $config['entity_type'],
            'action' => $config['action'],
            'diff_type' => $payload['diffType'] ?? null,
            'url' => $this->webhookUrl,
            'enabled' => true,
        ]);

        Log::info('Webhook created successfully', [
            'account_id' => $account->account_id,
            'webhook_id' => $webhook->id,
            'moysklad_webhook_id' => $response['id'],
            'entity_type' => $config['entity_type'],
            'action' => $config['action']
        ]);
    }

    /**
     * Delete all webhooks for account
     *
     * @param Account $account
     * @return array ['deleted' => int, 'errors' => array]
     */
    public function deleteWebhooksForAccount(Account $account): array
    {
        // Configure МойСклад service
        $this->moySkladService
            ->setAccessToken($account->access_token)
            ->setAccountId($account->account_id);

        $webhooks = Webhook::where('account_id', $account->account_id)->get();

        Log::info('Starting webhook deletion', [
            'account_id' => $account->account_id,
            'webhooks_count' => $webhooks->count()
        ]);

        $deleted = 0;
        $errors = [];

        foreach ($webhooks as $webhook) {
            try {
                $this->deleteWebhook($webhook);
                $deleted++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'webhook_id' => $webhook->id,
                    'entity_type' => $webhook->entity_type,
                    'action' => $webhook->action,
                    'error' => $e->getMessage()
                ];

                Log::error('Failed to delete webhook', [
                    'webhook_id' => $webhook->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('Webhook deletion completed', [
            'account_id' => $account->account_id,
            'deleted' => $deleted,
            'errors_count' => count($errors)
        ]);

        return [
            'deleted' => $deleted,
            'errors' => $errors
        ];
    }

    /**
     * Delete single webhook from МойСклад and database
     *
     * @param Webhook $webhook
     * @return void
     * @throws \Exception
     */
    protected function deleteWebhook(Webhook $webhook): void
    {
        Log::info('Deleting webhook', [
            'webhook_id' => $webhook->id,
            'account_id' => $webhook->account_id,
            'entity_type' => $webhook->entity_type,
            'action' => $webhook->action
        ]);

        // Delete from МойСклад
        try {
            $this->moySkladService->delete("entity/webhook/{$webhook->moysklad_webhook_id}");
        } catch (\Throwable $e) {
            // If webhook doesn't exist in МойСклад (404), continue with DB deletion
            if (strpos($e->getMessage(), '404') === false) {
                throw $e;
            }

            Log::warning('Webhook not found in МойСклад, deleting from DB only', [
                'webhook_id' => $webhook->id,
                'moysklad_webhook_id' => $webhook->moysklad_webhook_id
            ]);
        }

        // Delete from database
        $webhook->delete();

        Log::info('Webhook deleted successfully', [
            'webhook_id' => $webhook->id
        ]);
    }

    /**
     * Reinstall webhooks (delete all old + install new)
     *
     * @param Account $account
     * @param string $accountType
     * @return array ['deleted' => int, 'created' => int, 'errors' => array]
     */
    public function reinstallWebhooks(Account $account, string $accountType): array
    {
        Log::info('Reinstalling webhooks', [
            'account_id' => $account->account_id,
            'account_type' => $accountType
        ]);

        $deleteResult = $this->deleteWebhooksForAccount($account);
        $setupResult = $this->setupWebhooksForAccount($account, $accountType);

        return [
            'deleted' => $deleteResult['deleted'],
            'created' => $setupResult['created'],
            'errors' => array_merge($deleteResult['errors'], $setupResult['errors'])
        ];
    }

    /**
     * Get webhook configuration for account type
     *
     * Main account: Products, services, bundles, variants, product folders
     * Child account: Customer orders, retail sales
     *
     * @param string $accountType 'main' or 'child'
     * @return array Array of webhook configs
     */
    protected function getWebhooksConfig(string $accountType): array
    {
        if ($accountType === 'main') {
            // Main account webhooks (товары/услуги → Child)
            return [
                // Products
                ['entity_type' => 'product', 'action' => 'CREATE', 'account_type' => 'main'],
                ['entity_type' => 'product', 'action' => 'UPDATE', 'account_type' => 'main'],
                ['entity_type' => 'product', 'action' => 'DELETE', 'account_type' => 'main'],

                // Services
                ['entity_type' => 'service', 'action' => 'CREATE', 'account_type' => 'main'],
                ['entity_type' => 'service', 'action' => 'UPDATE', 'account_type' => 'main'],
                ['entity_type' => 'service', 'action' => 'DELETE', 'account_type' => 'main'],

                // Bundles
                ['entity_type' => 'bundle', 'action' => 'CREATE', 'account_type' => 'main'],
                ['entity_type' => 'bundle', 'action' => 'UPDATE', 'account_type' => 'main'],
                ['entity_type' => 'bundle', 'action' => 'DELETE', 'account_type' => 'main'],

                // Variants
                ['entity_type' => 'variant', 'action' => 'CREATE', 'account_type' => 'main'],
                ['entity_type' => 'variant', 'action' => 'UPDATE', 'account_type' => 'main'],
                ['entity_type' => 'variant', 'action' => 'DELETE', 'account_type' => 'main'],

                // Product folders
                ['entity_type' => 'productfolder', 'action' => 'CREATE', 'account_type' => 'main'],
                ['entity_type' => 'productfolder', 'action' => 'UPDATE', 'account_type' => 'main'],
                ['entity_type' => 'productfolder', 'action' => 'DELETE', 'account_type' => 'main'],
            ];
        } else {
            // Child account webhooks (заказы → Main)
            return [
                // Customer orders
                ['entity_type' => 'customerorder', 'action' => 'CREATE', 'account_type' => 'child'],
                ['entity_type' => 'customerorder', 'action' => 'UPDATE', 'account_type' => 'child'],
                ['entity_type' => 'customerorder', 'action' => 'DELETE', 'account_type' => 'child'],

                // Retail sales
                ['entity_type' => 'retaildemand', 'action' => 'CREATE', 'account_type' => 'child'],
                ['entity_type' => 'retaildemand', 'action' => 'UPDATE', 'account_type' => 'child'],
                ['entity_type' => 'retaildemand', 'action' => 'DELETE', 'account_type' => 'child'],
            ];
        }
    }

    /**
     * Get МойСклад service instance (for testing/admin)
     *
     * @return MoySkladService
     */
    public function getMoySkladService(): MoySkladService
    {
        return $this->moySkladService;
    }
}
```

### 💡 Key Design Points:

**1. Error Handling:**
- Continue on error (don't stop if one webhook fails)
- Collect all errors and return in result
- Log each error with full context

**2. Idempotency:**
- Check existing webhooks before creating
- Skip if already exists (don't throw error)

**3. Webhook Configuration:**
- Main: 15 webhooks (5 entity types × 3 actions)
- Child: 6 webhooks (2 entity types × 3 actions)

**4. UPDATE Action:**
- Always include `diffType: FIELDS` to get changed fields

### ✅ VALIDATION:

```bash
php artisan tinker
>>> $service = app(\App\Services\WebhookSetupService::class);
>>> $account = \App\Models\Account::first();
>>> $result = $service->setupWebhooksForAccount($account, 'main');
>>> dd($result);
// Should show: ['created' => 15, 'errors' => []]
```

---

## 2. WebhookReceiverService

**📝 LOCATION:** `app/Services/WebhookReceiverService.php`

**📝 RESPONSIBILITY:** Receive and validate incoming webhooks from МойСклад

### Key Methods:

- `receive()` - Save webhook to database, update counters
- `validate()` - Validate webhook payload structure

### Complete Code:

```php
<?php

namespace App\Services;

use App\Models\Webhook;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

/**
 * Service for receiving and validating webhooks from МойСклад
 *
 * Responsibilities:
 * - Validate webhook payload structure
 * - Check for duplicates (idempotency via requestId)
 * - Save webhook to webhook_logs table
 * - Update webhook counters (total_received)
 *
 * CRITICAL: This service must be FAST (< 50ms)
 * МойСклад expects response within 1500ms
 */
class WebhookReceiverService
{
    /**
     * Receive and process incoming webhook
     *
     * @param array $payload Full webhook payload from МойСклад
     * @param string $requestId requestId from query parameter
     * @return WebhookLog
     * @throws \Exception If validation fails
     */
    public function receive(array $payload, string $requestId): WebhookLog
    {
        $startTime = microtime(true);

        // Validate payload structure
        if (!$this->validate($payload)) {
            throw new \Exception('Invalid webhook payload structure');
        }

        // Extract data from payload
        $events = $payload['events'] ?? [];
        $auditContext = $payload['auditContext'] ?? null;

        // Get first event for metadata
        $firstEvent = $events[0];
        $entityType = $firstEvent['meta']['type'] ?? null;
        $action = $firstEvent['action'] ?? null;
        $accountId = $firstEvent['accountId'] ?? null;

        if (!$entityType || !$action || !$accountId) {
            throw new \Exception('Invalid webhook payload: missing required fields (entityType, action, or accountId)');
        }

        // Check for duplicate (idempotency via requestId)
        $existingLog = WebhookLog::where('request_id', $requestId)->first();
        if ($existingLog) {
            Log::warning('Duplicate webhook received (МойСклад retry)', [
                'request_id' => $requestId,
                'existing_log_id' => $existingLog->id,
                'existing_status' => $existingLog->status,
                'created_at' => $existingLog->created_at
            ]);

            // Return existing log (idempotency - don't create duplicate)
            return $existingLog;
        }

        // Find corresponding webhook in database
        $webhook = Webhook::where([
            'account_id' => $accountId,
            'entity_type' => $entityType,
            'action' => $action,
            'enabled' => true,
        ])->first();

        if (!$webhook) {
            Log::warning('Webhook received but no matching webhook found in database', [
                'request_id' => $requestId,
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'action' => $action
            ]);

            // Don't throw error - create log anyway for debugging
            // Maybe webhook was deleted but МойСклад still sending
        }

        // Create webhook log
        $webhookLog = WebhookLog::create([
            'webhook_id' => $webhook?->id,
            'request_id' => $requestId,
            'account_id' => $accountId,
            'entity_type' => $entityType,
            'action' => $action,
            'events_count' => count($events),
            'payload' => $payload,
            'status' => 'received',
        ]);

        // Update webhook counters (if webhook found)
        if ($webhook) {
            $webhook->incrementReceived();
        }

        $duration = (int)((microtime(true) - $startTime) * 1000); // ms

        Log::info('Webhook received and saved', [
            'webhook_log_id' => $webhookLog->id,
            'request_id' => $requestId,
            'account_id' => $accountId,
            'entity_type' => $entityType,
            'action' => $action,
            'events_count' => count($events),
            'webhook_found' => $webhook ? 'yes' : 'no',
            'duration_ms' => $duration
        ]);

        return $webhookLog;
    }

    /**
     * Validate webhook payload structure
     *
     * Required fields:
     * - events[] array (not empty)
     * - events[0].meta.type
     * - events[0].action
     * - events[0].accountId
     *
     * @param array $payload
     * @return bool
     */
    public function validate(array $payload): bool
    {
        // Check events array exists
        if (!isset($payload['events']) || !is_array($payload['events'])) {
            Log::error('Webhook validation failed: events field missing or not array', [
                'payload_keys' => array_keys($payload)
            ]);
            return false;
        }

        // Check events not empty
        if (empty($payload['events'])) {
            Log::error('Webhook validation failed: events array is empty');
            return false;
        }

        // Check first event structure
        $firstEvent = $payload['events'][0];

        if (!isset($firstEvent['meta']['type'])) {
            Log::error('Webhook validation failed: events[0].meta.type missing', [
                'event_keys' => array_keys($firstEvent)
            ]);
            return false;
        }

        if (!isset($firstEvent['action'])) {
            Log::error('Webhook validation failed: events[0].action missing');
            return false;
        }

        if (!isset($firstEvent['accountId'])) {
            Log::error('Webhook validation failed: events[0].accountId missing');
            return false;
        }

        // Valid action values
        $validActions = ['CREATE', 'UPDATE', 'DELETE'];
        if (!in_array($firstEvent['action'], $validActions)) {
            Log::error('Webhook validation failed: invalid action', [
                'action' => $firstEvent['action'],
                'valid_actions' => $validActions
            ]);
            return false;
        }

        return true;
    }
}
```

### 💡 Key Design Points:

**1. Idempotency:**
- Check `request_id` before creating log
- If duplicate → return existing log
- Prevents duplicate processing

**2. Performance:**
- Target: < 50ms execution time
- No external API calls
- Single database INSERT
- Minimal validation

**3. Error Tolerance:**
- If webhook not found in DB → log warning but don't throw
- Maybe webhook was deleted but МойСклад still sending

**4. Logging:**
- Log timing (duration_ms)
- Log all key fields for debugging

### ✅ VALIDATION:

```bash
# Simulate webhook with curl
curl -X POST http://localhost/api/webhooks/receive?requestId=test-123 \
  -H "Content-Type: application/json" \
  -d '{
    "events": [{
      "meta": {"type": "product", "href": "..."},
      "action": "UPDATE",
      "accountId": "test-uuid"
    }]
  }'

# Check log created
php artisan tinker
>>> \App\Models\WebhookLog::where('request_id', 'test-123')->first()
```

---

## 3. WebhookProcessorService

**📝 LOCATION:** `app/Services/WebhookProcessorService.php`

**📝 RESPONSIBILITY:** Parse webhook events and create sync_queue tasks

### Key Methods:

- `process()` - Main entry point (route to Main/Child handler)
- `processMainAccountWebhook()` - Handle Main → Child webhooks
- `processChildAccountWebhook()` - Handle Child → Main webhooks
- `createSyncTask()` - Create individual sync task
- `createBatchUpdateTask()` - Create batch task for multiple entities
- `checkEntityPassesFilter()` - Load entity and apply filters
- `applyFilters()` - Check filters (productFolder, characteristics, tags)

### Complete Code:

```php
<?php

namespace App\Services;

use App\Models\WebhookLog;
use App\Models\SyncQueue;
use App\Models\SyncSetting;
use App\Models\Account;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Service for processing webhook events and creating sync tasks
 *
 * Responsibilities:
 * - Parse webhook events[] array
 * - Route to Main/Child handlers based on account_type
 * - Check filters (CREATE only)
 * - Create sync_queue tasks
 * - Handle batch vs individual tasks
 *
 * IMPORTANT: This runs asynchronously in ProcessWebhookJob
 * Can take 5-30 seconds (API calls for filter checks)
 */
class WebhookProcessorService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Process webhook log and create sync tasks
     *
     * @param WebhookLog $webhookLog
     * @return array ['created_tasks' => int, 'skipped' => int]
     * @throws \Throwable
     */
    public function process(WebhookLog $webhookLog): array
    {
        $webhookLog->markAsProcessing();

        try {
            $payload = $webhookLog->payload;
            $events = $payload['events'] ?? [];

            $entityType = $webhookLog->entity_type;
            $action = $webhookLog->action;
            $accountId = $webhookLog->account_id;

            // Determine account type
            $syncSettings = SyncSetting::where('account_id', $accountId)->first();
            $accountType = $syncSettings?->account_type ?? 'main';

            Log::info('Processing webhook', [
                'webhook_log_id' => $webhookLog->id,
                'account_id' => $accountId,
                'account_type' => $accountType,
                'entity_type' => $entityType,
                'action' => $action,
                'events_count' => count($events)
            ]);

            // Route to appropriate handler
            if ($accountType === 'main') {
                $result = $this->processMainAccountWebhook($webhookLog, $events, $syncSettings);
            } else {
                $result = $this->processChildAccountWebhook($webhookLog, $events, $syncSettings);
            }

            $webhookLog->markAsCompleted();

            Log::info('Webhook processed successfully', [
                'webhook_log_id' => $webhookLog->id,
                'created_tasks' => $result['created_tasks'],
                'skipped' => $result['skipped']
            ]);

            return $result;

        } catch (\Throwable $e) {
            $webhookLog->markAsFailed($e->getMessage());

            // Increment failed counter
            if ($webhookLog->webhook) {
                $webhookLog->webhook->incrementFailed();
            }

            Log::error('Webhook processing failed', [
                'webhook_log_id' => $webhookLog->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Process webhook from Main account (товары → Child)
     *
     * @param WebhookLog $webhookLog
     * @param array $events
     * @param SyncSetting|null $syncSettings
     * @return array
     */
    protected function processMainAccountWebhook(
        WebhookLog $webhookLog,
        array $events,
        ?SyncSetting $syncSettings
    ): array {
        $entityType = $webhookLog->entity_type;
        $action = $webhookLog->action;
        $mainAccountId = $webhookLog->account_id;

        // Get active child accounts
        $childAccounts = DB::table('child_accounts')
            ->join('sync_settings', 'child_accounts.child_account_id', '=', 'sync_settings.account_id')
            ->where('child_accounts.parent_account_id', $mainAccountId)
            ->where('child_accounts.status', 'active')
            ->select('child_accounts.*', 'sync_settings.*')
            ->get();

        if ($childAccounts->isEmpty()) {
            Log::info('No active child accounts for main account', [
                'main_account_id' => $mainAccountId
            ]);
            return ['created_tasks' => 0, 'skipped' => count($events)];
        }

        Log::info('Found active child accounts', [
            'main_account_id' => $mainAccountId,
            'child_accounts_count' => $childAccounts->count()
        ]);

        $createdTasks = 0;
        $skipped = 0;

        // Check if should create batch tasks
        $shouldBatch = count($events) > 1
            && in_array($entityType, ['product', 'service', 'bundle', 'variant'])
            && $action === 'UPDATE';

        if ($shouldBatch) {
            // Batch UPDATE: create batch tasks (chunks of 15)
            $batches = array_chunk($events, 15);

            foreach ($childAccounts as $childAccount) {
                foreach ($batches as $batchEvents) {
                    $created = $this->createBatchUpdateTask(
                        $mainAccountId,
                        $childAccount->child_account_id,
                        $entityType,
                        $batchEvents
                    );
                    $createdTasks += $created;
                }
            }

            Log::info('Created batch update tasks', [
                'main_account_id' => $mainAccountId,
                'entity_type' => $entityType,
                'batches' => count($batches),
                'child_accounts' => $childAccounts->count(),
                'total_tasks' => $createdTasks
            ]);

        } else {
            // Individual tasks (CREATE, DELETE, or single UPDATE)
            foreach ($events as $event) {
                foreach ($childAccounts as $childAccount) {
                    $created = $this->createSyncTask(
                        $mainAccountId,
                        $childAccount,
                        $entityType,
                        $action,
                        $event
                    );

                    if ($created) {
                        $createdTasks++;
                    } else {
                        $skipped++;
                    }
                }
            }
        }

        return [
            'created_tasks' => $createdTasks,
            'skipped' => $skipped
        ];
    }

    /**
     * Process webhook from Child account (заказы → Main)
     *
     * @param WebhookLog $webhookLog
     * @param array $events
     * @param SyncSetting|null $syncSettings
     * @return array
     */
    protected function processChildAccountWebhook(
        WebhookLog $webhookLog,
        array $events,
        ?SyncSetting $syncSettings
    ): array {
        $entityType = $webhookLog->entity_type;
        $action = $webhookLog->action;
        $childAccountId = $webhookLog->account_id;

        // Get parent account
        $link = DB::table('child_accounts')
            ->where('child_account_id', $childAccountId)
            ->where('status', 'active')
            ->first();

        if (!$link) {
            Log::warning('No active link to parent account', [
                'child_account_id' => $childAccountId
            ]);
            return ['created_tasks' => 0, 'skipped' => count($events)];
        }

        $parentAccountId = $link->parent_account_id;

        $createdTasks = 0;

        // Create sync tasks for each event
        foreach ($events as $event) {
            $entityId = $this->extractEntityId($event['meta']['href'] ?? '');

            if (!$entityId) {
                Log::warning('Could not extract entity ID from webhook event', [
                    'event' => $event
                ]);
                continue;
            }

            // Determine priority
            $priority = $action === 'UPDATE' ? 10 : 7;

            SyncQueue::create([
                'account_id' => $parentAccountId, // Target account (Main)
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'operation' => strtolower($action),
                'priority' => $priority,
                'status' => 'pending',
                'payload' => [
                    'source_account_id' => $childAccountId,
                    'from_webhook' => true,
                    'webhook_log_id' => $webhookLog->id,
                    'action' => $action,
                    'updated_fields' => $event['updatedFields'] ?? null,
                ]
            ]);

            $createdTasks++;
        }

        Log::info('Created child→main sync tasks', [
            'child_account_id' => $childAccountId,
            'parent_account_id' => $parentAccountId,
            'entity_type' => $entityType,
            'action' => $action,
            'created_tasks' => $createdTasks
        ]);

        return [
            'created_tasks' => $createdTasks,
            'skipped' => 0
        ];
    }

    /**
     * Create individual sync task for one entity
     *
     * @param string $mainAccountId
     * @param object $childAccount
     * @param string $entityType
     * @param string $action
     * @param array $event
     * @return bool True if task created, false if skipped
     */
    protected function createSyncTask(
        string $mainAccountId,
        $childAccount,
        string $entityType,
        string $action,
        array $event
    ): bool {
        $entityId = $this->extractEntityId($event['meta']['href'] ?? '');

        if (!$entityId) {
            Log::warning('Could not extract entity ID', [
                'event' => $event
            ]);
            return false;
        }

        // For CREATE: check filters (does entity pass?)
        if ($action === 'CREATE') {
            $passesFilter = $this->checkEntityPassesFilter(
                $mainAccountId,
                $childAccount,
                $entityType,
                $entityId
            );

            if (!$passesFilter) {
                Log::debug('Entity does not pass filter, skipping', [
                    'entity_type' => $entityType,
                    'entity_id' => substr($entityId, 0, 8) . '...',
                    'child_account_id' => $childAccount->child_account_id
                ]);
                return false;
            }
        }

        // For UPDATE: check mapping exists (entity already synced?)
        if ($action === 'UPDATE') {
            $existingMapping = \App\Models\EntityMapping::where([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccount->child_account_id,
                'entity_type' => $entityType,
                'parent_entity_id' => $entityId
            ])->exists();

            if (!$existingMapping) {
                Log::debug('Entity not synced yet, skipping UPDATE', [
                    'entity_type' => $entityType,
                    'entity_id' => substr($entityId, 0, 8) . '...',
                    'child_account_id' => $childAccount->child_account_id
                ]);
                return false;
            }
        }

        // Determine priority
        $priority = match($action) {
            'UPDATE' => 10,
            'CREATE' => 7,
            'DELETE' => 7,
            default => 5
        };

        // Productfolder - low priority
        if ($entityType === 'productfolder') {
            $priority = 5;
        }

        // Determine operation
        $operation = match($action) {
            'DELETE' => 'archive', // Archive, not delete
            default => strtolower($action)
        };

        SyncQueue::create([
            'account_id' => $childAccount->child_account_id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation' => $operation,
            'priority' => $priority,
            'status' => 'pending',
            'payload' => [
                'main_account_id' => $mainAccountId,
                'from_webhook' => true,
                'action' => $action,
                'updated_fields' => $event['updatedFields'] ?? null,
            ]
        ]);

        return true;
    }

    /**
     * Create batch UPDATE task for multiple entities
     *
     * Only creates task for entities that are already synced (have mapping)
     *
     * @param string $mainAccountId
     * @param string $childAccountId
     * @param string $entityType
     * @param array $events
     * @return int Number of tasks created (0 or 1)
     */
    protected function createBatchUpdateTask(
        string $mainAccountId,
        string $childAccountId,
        string $entityType,
        array $events
    ): int {
        $entityIds = [];
        $updatedFieldsMap = [];

        // Extract entity IDs and updated fields
        foreach ($events as $event) {
            $entityId = $this->extractEntityId($event['meta']['href'] ?? '');
            if ($entityId) {
                $entityIds[] = $entityId;
                $updatedFieldsMap[$entityId] = $event['updatedFields'] ?? [];
            }
        }

        if (empty($entityIds)) {
            return 0;
        }

        // Check which entities are already synced (have mapping)
        $syncedEntityIds = \App\Models\EntityMapping::where([
            'parent_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'entity_type' => $entityType,
        ])
        ->whereIn('parent_entity_id', $entityIds)
        ->pluck('parent_entity_id')
        ->toArray();

        if (empty($syncedEntityIds)) {
            Log::debug('No synced entities for batch update, skipping', [
                'main_account_id' => substr($mainAccountId, 0, 8) . '...',
                'child_account_id' => substr($childAccountId, 0, 8) . '...',
                'entity_type' => $entityType,
                'total_entities' => count($entityIds)
            ]);
            return 0;
        }

        // Create batch task
        $batchEntityType = 'batch_' . $entityType . 's'; // batch_products, batch_services, etc.

        SyncQueue::create([
            'account_id' => $childAccountId,
            'entity_type' => $batchEntityType,
            'entity_id' => 'batch_webhook_update',
            'operation' => 'update',
            'priority' => 10, // High priority for UPDATE
            'status' => 'pending',
            'payload' => [
                'main_account_id' => $mainAccountId,
                'from_webhook' => true,
                'entity_ids' => $syncedEntityIds,
                'updated_fields_map' => array_intersect_key($updatedFieldsMap, array_flip($syncedEntityIds)),
                'batch_size' => count($syncedEntityIds),
            ]
        ]);

        Log::info('Created batch update task', [
            'main_account_id' => substr($mainAccountId, 0, 8) . '...',
            'child_account_id' => substr($childAccountId, 0, 8) . '...',
            'entity_type' => $entityType,
            'total_entities' => count($entityIds),
            'synced_entities' => count($syncedEntityIds),
            'batch_entity_type' => $batchEntityType
        ]);

        return 1;
    }

    /**
     * Check if entity passes sync filters
     *
     * Loads entity from МойСклад and applies filters
     * SLOW operation (API call) - only use for CREATE
     *
     * @param string $mainAccountId
     * @param object $childAccount
     * @param string $entityType
     * @param string $entityId
     * @return bool
     */
    protected function checkEntityPassesFilter(
        string $mainAccountId,
        $childAccount,
        string $entityType,
        string $entityId
    ): bool {
        // Get sync settings
        $settings = SyncSetting::where('account_id', $childAccount->child_account_id)->first();

        if (!$settings) {
            return false;
        }

        // Check if sync enabled for this entity type
        if ($entityType === 'product' && !$settings->sync_products) {
            return false;
        }
        if ($entityType === 'service' && !$settings->sync_services) {
            return false;
        }

        // If no filters - pass
        if (empty($settings->filter_product_folders) &&
            empty($settings->filter_characteristics) &&
            empty($settings->filter_tags)) {
            return true;
        }

        // Load entity from МойСклад (Main account)
        try {
            $account = Account::where('account_id', $mainAccountId)->first();

            $this->moySkladService
                ->setAccessToken($account->access_token)
                ->setAccountId($mainAccountId);

            $endpoint = match($entityType) {
                'product' => "entity/product/{$entityId}",
                'service' => "entity/service/{$entityId}",
                'bundle' => "entity/bundle/{$entityId}",
                'variant' => "entity/variant/{$entityId}",
                default => null
            };

            if (!$endpoint) {
                return true; // Unknown type - pass
            }

            $entity = $this->moySkladService->get($endpoint);

            // Apply filters
            return $this->applyFilters($entity, $settings);

        } catch (\Throwable $e) {
            Log::error('Failed to check entity filter', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage()
            ]);

            // On error - fail safe (don't sync)
            return false;
        }
    }

    /**
     * Apply sync filters to entity
     *
     * @param array $entity
     * @param SyncSetting $settings
     * @return bool
     */
    protected function applyFilters(array $entity, SyncSetting $settings): bool
    {
        // Filter by product folders
        if (!empty($settings->filter_product_folders)) {
            $entityFolderId = $entity['productFolder']['id'] ?? null;

            if (!$entityFolderId || !in_array($entityFolderId, $settings->filter_product_folders)) {
                return false;
            }
        }

        // Filter by characteristics
        if (!empty($settings->filter_characteristics)) {
            $entityCharacteristics = $entity['characteristics'] ?? [];

            foreach ($settings->filter_characteristics as $filterId => $filterValues) {
                $found = false;

                foreach ($entityCharacteristics as $char) {
                    $charId = $char['id'] ?? null;
                    $charValue = $char['value'] ?? null;

                    if ($charId === $filterId && in_array($charValue, $filterValues)) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    return false;
                }
            }
        }

        // Filter by tags
        if (!empty($settings->filter_tags)) {
            $entityTags = array_map(fn($tag) => $tag['name'] ?? '', $entity['tags'] ?? []);

            $hasMatchingTag = false;
            foreach ($settings->filter_tags as $filterTag) {
                if (in_array($filterTag, $entityTags)) {
                    $hasMatchingTag = true;
                    break;
                }
            }

            if (!$hasMatchingTag) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract entity ID from МойСклад href
     *
     * @param string $href e.g. "https://api.moysklad.ru/api/remap/1.2/entity/product/UUID"
     * @return string|null UUID
     */
    protected function extractEntityId(string $href): ?string
    {
        if (empty($href)) {
            return null;
        }

        $parts = explode('/', $href);
        return end($parts) ?: null;
    }
}
```

### 💡 Key Design Points:

**1. Main vs Child Routing:**
- Check `sync_settings.account_type`
- Route to appropriate handler

**2. Filter Logic:**
- CREATE: Load entity + apply filters (slow but necessary)
- UPDATE: Check mapping exists (fast, no API call)
- DELETE: Always create task

**3. Batch Strategy:**
- UPDATE + multiple events → batch (chunks of 15)
- CREATE/DELETE → individual tasks

**4. Performance:**
- Can take 5-30 seconds (API calls for filters)
- Runs asynchronously in job (OK to be slow)

### ✅ VALIDATION:

```bash
php artisan tinker
>>> $log = \App\Models\WebhookLog::first();
>>> $processor = app(\App\Services\WebhookProcessorService::class);
>>> $result = $processor->process($log);
>>> dd($result);
// Should show: ['created_tasks' => N, 'skipped' => M]
```

---

## 4. WebhookHealthService

**📝 LOCATION:** `app/Services/WebhookHealthService.php`

**📝 RESPONSIBILITY:** Monitoring, statistics, and alerting for webhooks

### Key Methods:

- `getHealthSummary()` - Get all webhooks with failure_rate, status
- `getDetailedLogs()` - Get webhook_logs with filters
- `getStatistics()` - Get daily aggregated stats for charts
- `updateHealthStats()` - Aggregate webhook_logs into webhook_health_stats (runs hourly)
- `getAlerts()` - Get webhooks with failure_rate > 10%

### Complete Code:

```php
<?php

namespace App\Services;

use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\WebhookHealthStat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Service for webhook health monitoring and statistics
 *
 * Responsibilities:
 * - Calculate failure rates
 * - Aggregate daily statistics
 * - Generate alerts for failing webhooks
 * - Provide dashboard data
 */
class WebhookHealthService
{
    /**
     * Get health summary for all webhooks
     *
     * Returns:
     * - All webhooks with calculated failure_rate
     * - Status determination (healthy/warning/critical)
     * - Last triggered time
     *
     * @param string|null $accountId Filter by account
     * @return Collection
     */
    public function getHealthSummary(?string $accountId = null): Collection
    {
        $query = Webhook::with('account')
            ->select([
                'webhooks.*',
                DB::raw('CASE
                    WHEN total_received = 0 THEN 0
                    ELSE ROUND((total_failed::numeric / total_received::numeric) * 100, 2)
                END as failure_rate')
            ]);

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        return $query->get()->map(function ($webhook) {
            return [
                'id' => $webhook->id,
                'account_id' => $webhook->account_id,
                'account_name' => $webhook->account->account_name ?? 'Unknown',
                'account_type' => $webhook->account_type,
                'entity_type' => $webhook->entity_type,
                'action' => $webhook->action,
                'enabled' => $webhook->enabled,
                'last_triggered_at' => $webhook->last_triggered_at?->diffForHumans(),
                'total_received' => $webhook->total_received,
                'total_failed' => $webhook->total_failed,
                'failure_rate' => $webhook->failure_rate,
                'is_healthy' => $webhook->failure_rate < 10,
                'status' => $this->determineStatus($webhook),
            ];
        });
    }

    /**
     * Get detailed webhook logs with filters
     *
     * @param array $filters ['account_id', 'entity_type', 'status', 'date_from', 'date_to']
     * @param int $limit
     * @return Collection
     */
    public function getDetailedLogs(array $filters = [], int $limit = 100): Collection
    {
        $query = WebhookLog::with('webhook')
            ->orderBy('created_at', 'desc');

        if (isset($filters['account_id'])) {
            $query->where('account_id', $filters['account_id']);
        }

        if (isset($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get aggregated statistics for period
     *
     * @param string $period '24hours', '7days', '30days'
     * @param string|null $accountId Filter by account
     * @return array
     */
    public function getStatistics(string $period = '7days', ?string $accountId = null): array
    {
        $dateFrom = match($period) {
            '24hours' => now()->subDay(),
            '7days' => now()->subDays(7),
            '30days' => now()->subDays(30),
            default => now()->subDays(7)
        };

        $query = WebhookLog::where('created_at', '>=', $dateFrom);

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        $stats = $query->select([
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as total'),
            DB::raw('COUNT(CASE WHEN status = \'completed\' THEN 1 END) as completed'),
            DB::raw('COUNT(CASE WHEN status = \'failed\' THEN 1 END) as failed'),
            DB::raw('SUM(events_count) as total_events'),
        ])
        ->groupBy('date')
        ->orderBy('date', 'asc')
        ->get();

        return [
            'period' => $period,
            'date_from' => $dateFrom->toDateString(),
            'date_to' => now()->toDateString(),
            'daily_stats' => $stats,
            'totals' => [
                'total_webhooks' => $stats->sum('total'),
                'total_events' => $stats->sum('total_events'),
                'completed' => $stats->sum('completed'),
                'failed' => $stats->sum('failed'),
                'failure_rate' => $stats->sum('total') > 0
                    ? round(($stats->sum('failed') / $stats->sum('total')) * 100, 2)
                    : 0
            ]
        ];
    }

    /**
     * Update daily health statistics
     *
     * Aggregates webhook_logs into webhook_health_stats for fast dashboard
     * Should run hourly via artisan command
     *
     * @return void
     */
    public function updateHealthStats(): void
    {
        $today = now()->toDateString();

        // Aggregate data for today
        $stats = WebhookLog::where('created_at', '>=', $today)
            ->select([
                'webhook_id',
                'account_id',
                'entity_type',
                'action',
                DB::raw('COUNT(*) as total_received'),
                DB::raw('COUNT(CASE WHEN status = \'failed\' THEN 1 END) as total_failed'),
                DB::raw('SUM(events_count) as total_events'),
                DB::raw('MAX(CASE WHEN status = \'completed\' THEN created_at END) as last_received_at'),
                DB::raw('MAX(CASE WHEN status = \'failed\' THEN created_at END) as last_failed_at'),
            ])
            ->whereNotNull('webhook_id')
            ->groupBy(['webhook_id', 'account_id', 'entity_type', 'action'])
            ->get();

        foreach ($stats as $stat) {
            WebhookHealthStat::updateOrCreate(
                [
                    'webhook_id' => $stat->webhook_id,
                    'stat_date' => $today,
                ],
                [
                    'account_id' => $stat->account_id,
                    'entity_type' => $stat->entity_type,
                    'action' => $stat->action,
                    'total_received' => $stat->total_received,
                    'total_failed' => $stat->total_failed,
                    'total_events' => $stat->total_events,
                    'last_received_at' => $stat->last_received_at,
                    'last_failed_at' => $stat->last_failed_at,
                ]
            );
        }

        \Log::info('Webhook health stats updated', [
            'date' => $today,
            'stats_count' => $stats->count()
        ]);
    }

    /**
     * Get alerts (webhooks with failure_rate > 10%)
     *
     * @return Collection
     */
    public function getAlerts(): Collection
    {
        return Webhook::where('enabled', true)
            ->where('total_received', '>', 0)
            ->get()
            ->filter(function ($webhook) {
                return $webhook->failure_rate > 10;
            })
            ->map(function ($webhook) {
                return [
                    'webhook_id' => $webhook->id,
                    'account_id' => $webhook->account_id,
                    'entity_type' => $webhook->entity_type,
                    'action' => $webhook->action,
                    'failure_rate' => $webhook->failure_rate,
                    'total_received' => $webhook->total_received,
                    'total_failed' => $webhook->total_failed,
                    'last_triggered_at' => $webhook->last_triggered_at,
                    'severity' => $this->determineSeverity($webhook->failure_rate),
                ];
            });
    }

    /**
     * Determine webhook status
     *
     * @param Webhook $webhook
     * @return string
     */
    protected function determineStatus(Webhook $webhook): string
    {
        if (!$webhook->enabled) {
            return 'disabled';
        }

        if ($webhook->total_received === 0) {
            return 'not_triggered';
        }

        if ($webhook->failure_rate > 50) {
            return 'critical';
        }

        if ($webhook->failure_rate > 10) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Determine alert severity
     *
     * @param float $failureRate
     * @return string
     */
    protected function determineSeverity(float $failureRate): string
    {
        if ($failureRate > 50) {
            return 'critical';
        }

        if ($failureRate > 25) {
            return 'high';
        }

        return 'medium';
    }
}
```

### 💡 Key Design Points:

**1. Status Determination:**
- `healthy`: failure_rate < 10%
- `warning`: 10% ≤ failure_rate ≤ 50%
- `critical`: failure_rate > 50%
- `disabled`: enabled = false
- `not_triggered`: total_received = 0

**2. Aggregation:**
- `updateHealthStats()` runs hourly
- Aggregates webhook_logs → webhook_health_stats
- Fast dashboard queries

**3. Alerts:**
- Filter webhooks with failure_rate > 10%
- Severity levels: medium, high, critical
- For admin dashboard display

### ✅ VALIDATION:

```bash
php artisan tinker
>>> $service = app(\App\Services\WebhookHealthService::class);
>>> $summary = $service->getHealthSummary();
>>> dd($summary);

>>> $alerts = $service->getAlerts();
>>> dd($alerts);

>>> $stats = $service->getStatistics('7days');
>>> dd($stats);
```

---

## Summary

**Files Created:**

1. ✅ `WebhookSetupService.php` (~500 lines)
2. ✅ `WebhookReceiverService.php` (~200 lines)
3. ✅ `WebhookProcessorService.php` (~700 lines)
4. ✅ `WebhookHealthService.php` (~300 lines)

**Total:** ~1,700 lines of production-ready code

**Next Steps:**

1. Copy services to `app/Services/`
2. Test each service individually (see validation commands)
3. Register in `AppServiceProvider` if needed (most use automatic DI)
4. Proceed to [Implementation](18-webhook-implementation.md) for Jobs, Controllers, Routes

---

**END OF SERVICES DOCUMENT**