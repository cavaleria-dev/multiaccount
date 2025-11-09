# Sync Task Handlers - Modular Architecture

**🆕 NEW (2025-10-25):** ProcessSyncQueueJob refactored from 2,842 lines to 829 lines (-71%) using Strategy Pattern with modular handlers.

## Overview

Sync task processing has been refactored into a modular architecture where each entity type has its own dedicated handler. This significantly improves code maintainability, testability, and follows SOLID principles.

### Before Refactoring

```php
// ProcessSyncQueueJob.php (2,842 lines)
public function handle(
    ProductSyncService $productSyncService,
    ServiceSyncService $serviceSyncService,
    BundleSyncService $bundleSyncService,
    CustomerOrderSyncService $customerOrderSyncService,
    RetailDemandSyncService $retailDemandSyncService,
    PurchaseOrderSyncService $purchaseOrderSyncService,
    SyncStatisticsService $statisticsService,
    WebhookService $webhookService,
    ImageSyncService $imageSyncService
) {
    // 14 processXXXSync() methods (~2100 lines of duplicated code)
    match($task->entity_type) {
        'product' => $this->processProductSync(...),
        'variant' => $this->processVariantSync(...),
        // ... 11 more cases
    };
}
```

### After Refactoring

```php
// ProcessSyncQueueJob.php (829 lines)
public function handle(
    TaskDispatcher $taskDispatcher,
    SyncStatisticsService $statisticsService
) {
    // Simple delegation to appropriate handler
    $taskDispatcher->dispatch($task, $accountsCache, $settingsCache);
}
```

---

## Architecture

### Component Structure

```
app/Services/Sync/
├── TaskDispatcher.php                    # Routes tasks to appropriate handlers
└── Handlers/
    ├── SyncTaskHandler.php               # Abstract base class
    ├── ProductSyncHandler.php            # Products
    ├── BatchProductSyncHandler.php       # Batch products
    ├── VariantSyncHandler.php            # Variants
    ├── BatchVariantSyncHandler.php       # Batch variants
    ├── ServiceSyncHandler.php            # Services
    ├── BatchServiceSyncHandler.php       # Batch services
    ├── BundleSyncHandler.php             # Bundles
    ├── BatchBundleSyncHandler.php        # Batch bundles
    ├── CustomerOrderSyncHandler.php      # Customer orders (child → main)
    ├── RetailDemandSyncHandler.php       # Retail sales (child → main)
    ├── PurchaseOrderSyncHandler.php      # Purchase orders (child → main)
    ├── ImageSyncHandler.php              # Image synchronization
    └── WebhookCheckHandler.php           # Webhook setup/verification
```

---

## TaskDispatcher

**Location:** `app/Services/Sync/TaskDispatcher.php`

**Responsibility:** Routes sync tasks to appropriate handlers based on `entity_type`.

### Key Methods

```php
class TaskDispatcher
{
    /**
     * Register a handler for specific entity type
     */
    public function registerHandler(SyncTaskHandler $handler): void;

    /**
     * Dispatch task to appropriate handler
     *
     * @throws \Exception if no handler registered for entity_type
     */
    public function dispatch(
        SyncQueue $task,
        Collection $accountsCache,
        Collection $settingsCache
    ): void;

    /**
     * Check if handler exists for entity type
     */
    public function hasHandler(string $entityType): bool;
}
```

### Registration (AppServiceProvider)

```php
// app/Providers/AppServiceProvider.php
$this->app->singleton(TaskDispatcher::class, function ($app) {
    $dispatcher = new TaskDispatcher();

    $dispatcher->registerHandlers([
        $app->make(ProductSyncHandler::class),
        $app->make(BatchProductSyncHandler::class),
        // ... 11 more handlers
    ]);

    return $dispatcher;
});
```

---

## SyncTaskHandler (Abstract Base)

**Location:** `app/Services/Sync/Handlers/SyncTaskHandler.php`

**Responsibility:** Defines common interface and shared logic for all handlers.

### Template Method Pattern

```php
abstract class SyncTaskHandler
{
    /**
     * Main entry point (Template Method)
     */
    public function handle(
        SyncQueue $task,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $payload = $task->payload ?? [];

        // 1. Validate payload
        $this->validatePayload($task, $payload);

        // 2. Handle delete operation (if applicable)
        if ($task->operation === 'delete') {
            $this->handleDelete($task, $payload, $accountsCache, $settingsCache);
            return;
        }

        // 3. Perform main sync
        $this->handleSync($task, $payload, $accountsCache, $settingsCache);
    }

    /**
     * Must be implemented by concrete handlers
     */
    abstract protected function handleSync(...): void;

    /**
     * Returns entity type this handler processes
     */
    abstract public function getEntityType(): string;

    /**
     * Override if delete operation is supported
     */
    protected function handleDelete(...): void {
        // Default: not supported
    }

    /**
     * Override if main_account_id not required (e.g., webhooks)
     */
    protected function requiresMainAccountId(): bool {
        return true;
    }
}
```

---

## Concrete Handlers

### ProductSyncHandler

**Entity Type:** `product`
**Direction:** Main → Child
**Supports Delete:** ✅ Yes (archives in all child accounts)

```php
class ProductSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected ProductSyncService $productSyncService
    ) {}

    protected function handleSync(...): void {
        $this->productSyncService->syncProduct(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );
    }

    protected function handleDelete(...): void {
        $archivedCount = $this->productSyncService->archiveProduct(
            $payload['main_account_id'],
            $task->entity_id
        );
    }
}
```

### BatchProductSyncHandler

**Entity Type:** `batch_products`
**Direction:** Main → Child
**Payload Requirements:** `product_ids` array

```php
class BatchProductSyncHandler extends SyncTaskHandler
{
    protected function handleSync(...): void {
        $productIds = $payload['product_ids'] ?? [];

        if (empty($productIds)) {
            throw new \Exception('Invalid payload: missing product_ids');
        }

        $result = $this->batchSyncService->batchSyncProducts(
            $payload['main_account_id'],
            $task->account_id,
            $productIds
        );
    }
}
```

### CustomerOrderSyncHandler

**Entity Type:** `customerorder`
**Direction:** Child → Main (reverse sync)
**Requires main_account_id:** ❌ No

```php
class CustomerOrderSyncHandler extends SyncTaskHandler
{
    protected function requiresMainAccountId(): bool {
        return false; // Determined from child_accounts table
    }

    protected function handleSync(...): void {
        $this->customerOrderSyncService->syncCustomerOrder(
            $task->account_id, // child account
            $task->entity_id   // order ID
        );
    }
}
```

### WebhookCheckHandler

**Entity Type:** `webhook`
**Purpose:** Verify and setup webhooks for account
**Requires main_account_id:** ❌ No

```php
class WebhookCheckHandler extends SyncTaskHandler
{
    protected function requiresMainAccountId(): bool {
        return false;
    }

    protected function handleSync(...): void {
        $result = $this->webhookService->checkAndSetupWebhooks(
            $task->account_id
        );
    }
}
```

---

## Handler Mapping

| Entity Type | Handler Class | Direction | Delete Support | Batch |
|-------------|---------------|-----------|----------------|-------|
| `product` | ProductSyncHandler | Main → Child | ✅ | ❌ |
| `batch_products` | BatchProductSyncHandler | Main → Child | ❌ | ✅ |
| `variant` | VariantSyncHandler | Main → Child | ✅ | ❌ |
| `product_variants` | BatchVariantSyncHandler | Main → Child | ❌ | ✅ |
| `service` | ServiceSyncHandler | Main → Child | ✅ | ❌ |
| `batch_services` | BatchServiceSyncHandler | Main → Child | ❌ | ✅ |
| `bundle` | BundleSyncHandler | Main → Child | ✅ | ❌ |
| `batch_bundles` | BatchBundleSyncHandler | Main → Child | ❌ | ✅ |
| `customerorder` | CustomerOrderSyncHandler | Child → Main | ❌ | ❌ |
| `retaildemand` | RetailDemandSyncHandler | Child → Main | ❌ | ❌ |
| `purchaseorder` | PurchaseOrderSyncHandler | Child → Main | ❌ | ❌ |
| `image_sync` | ImageSyncHandler | Main → Child | ❌ | ❌ |
| `webhook` | WebhookCheckHandler | - | ❌ | ❌ |

---

## Benefits

### 1. Single Responsibility Principle ✅

**Before:**
```php
// ProcessSyncQueueJob handles 14 different sync types in one class
```

**After:**
```php
// Each handler handles exactly 1 sync type
// ProcessSyncQueueJob only orchestrates
```

### 2. Open/Closed Principle ✅

**Adding new sync type:**

**Before:** Modify ProcessSyncQueueJob (add case, add method, add dependency)

**After:**
1. Create new handler class extending `SyncTaskHandler`
2. Register in `AppServiceProvider`
3. Done! No changes to existing code

**Example:**
```php
// Add new entity type: "contract"
class ContractSyncHandler extends SyncTaskHandler
{
    public function getEntityType(): string {
        return 'contract';
    }

    protected function handleSync(...): void {
        $this->contractSyncService->sync(...);
    }
}

// Register in AppServiceProvider
$dispatcher->registerHandler($app->make(ContractSyncHandler::class));
```

### 3. Testability ✅

**Before:**
```php
// To test productSync, need to mock 9 services
$job = new ProcessSyncQueueJob();
$job->handle(
    $productMock, $serviceMock, $bundleMock,
    $orderMock, $demandMock, $purchaseMock,
    $statsMock, $webhookMock, $imageMock
);
```

**After:**
```php
// Test each handler independently with 1 service mock
$handler = new ProductSyncHandler($productSyncServiceMock);
$handler->handle($task, $accounts, $settings);

// Or test dispatcher routing
$dispatcher = new TaskDispatcher();
$dispatcher->registerHandler($mockHandler);
$dispatcher->dispatch($task, $accounts, $settings);
```

### 4. Readability ✅

**File sizes:**
- ProcessSyncQueueJob: ~~2,842~~ → **829 lines** (-71%)
- Each handler: **~50-60 lines** (easily readable in 2 minutes)
- TaskDispatcher: **110 lines** (simple routing logic)

### 5. Maintainability ✅

**Changing product sync logic:**

**Before:** Find method in 2,842-line file, modify alongside unrelated code

**After:** Open [ProductSyncHandler.php](../app/Services/Sync/Handlers/ProductSyncHandler.php) (57 lines), modify isolated logic

---

## Usage in ProcessSyncQueueJob

### Simplified Flow

```php
// app/Jobs/ProcessSyncQueueJob.php
public function handle(
    TaskDispatcher $taskDispatcher,
    SyncStatisticsService $statisticsService
): void {
    // 1. Fetch tasks from queue (50 at a time)
    $tasks = SyncQueue::where('status', 'pending')
        ->limit(50)
        ->lockForUpdate()
        ->get();

    // 2. Pre-load accounts and settings (N+1 optimization)
    $accountsCache = Account::whereIn('account_id', $accountIds)->get()->keyBy('account_id');
    $settingsCache = SyncSetting::whereIn('account_id', $accountIds)->get()->keyBy('account_id');

    // 3. Process each task
    foreach ($tasks as $task) {
        try {
            // ✨ Delegate to appropriate handler via dispatcher
            $taskDispatcher->dispatch($task, $accountsCache, $settingsCache);

            // Mark as completed
            $task->update(['status' => 'completed']);

        } catch (RateLimitException $e) {
            // Handle rate limit exceeded
        } catch (\Throwable $e) {
            // Handle other errors
        }
    }
}
```

---

## Adding New Handler

### Step-by-Step Guide

**1. Create Handler Class**

```php
// app/Services/Sync/Handlers/MyNewEntitySyncHandler.php
<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\MyNewEntitySyncService;
use Illuminate\Support\Collection;

class MyNewEntitySyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected MyNewEntitySyncService $myNewEntitySyncService
    ) {}

    public function getEntityType(): string
    {
        return 'my_new_entity'; // Must match entity_type in sync_queue
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $this->myNewEntitySyncService->sync(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );

        $this->logSuccess($task, [
            'main_account_id' => $payload['main_account_id'],
            'child_account_id' => $task->account_id,
        ]);
    }
}
```

**2. Register in AppServiceProvider**

```php
// app/Providers/AppServiceProvider.php
$this->app->singleton(TaskDispatcher::class, function ($app) {
    $dispatcher = new TaskDispatcher();

    $dispatcher->registerHandlers([
        // ... existing handlers
        $app->make(MyNewEntitySyncHandler::class), // ← Add here
    ]);

    return $dispatcher;
});
```

**3. Done!**

The dispatcher will automatically route tasks with `entity_type = 'my_new_entity'` to your new handler.

---

## Migration Notes

### Removed Code

The following methods were removed from `ProcessSyncQueueJob.php` (~2100 lines):

- `processTask()` - Replaced by `TaskDispatcher::dispatch()`
- `processProductSync()` - Moved to `ProductSyncHandler`
- `processVariantSync()` - Moved to `VariantSyncHandler`
- `processBatchVariantSync()` - Moved to `BatchVariantSyncHandler`
- `processBatchProductSync()` - Moved to `BatchProductSyncHandler`
- `processServiceSync()` - Moved to `ServiceSyncHandler`
- `processBatchServiceSync()` - Moved to `BatchServiceSyncHandler`
- `processBundleSync()` - Moved to `BundleSyncHandler`
- `processBatchBundleSync()` - Moved to `BatchBundleSyncHandler`
- `processCustomerOrderSync()` - Moved to `CustomerOrderSyncHandler`
- `processRetailDemandSync()` - Moved to `RetailDemandSyncHandler`
- `processPurchaseOrderSync()` - Moved to `PurchaseOrderSyncHandler`
- `processImageSync()` - Moved to `ImageSyncHandler`
- `processWebhookCheck()` - Moved to `WebhookCheckHandler`

### Backward Compatibility

✅ **Fully backward compatible** - No changes to:
- Database schema
- Queue task payload format
- API endpoints
- Sync services
- Frontend code

Only internal task processing architecture changed.

---

## Performance Impact

### Code Metrics

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| **ProcessSyncQueueJob size** | 2,842 lines | 829 lines | **-71%** |
| **Number of methods** | 22 | 6 | -73% |
| **Dependencies injected** | 9 services | 2 services | -78% |
| **Average handler size** | - | 52 lines | New |
| **Total new files** | 1 (monolithic) | 15 (modular) | +1400% |
| **Total lines (all files)** | 2,842 | 1,719 | -40% |

### Runtime Performance

⚠️ **No performance degradation**:
- TaskDispatcher lookup: O(1) hashtable
- Handler instantiation: Singleton via DI container
- Same service calls as before

### Testing Benefits

- **Unit test coverage**: Can now test each handler independently
- **Mock complexity**: 1 service mock per test (instead of 9)
- **Test execution time**: Faster (isolated tests)

---

## See Also

- [Queue & Supervisor](02-queue-supervisor.md) - Overall queue architecture
- [Service Layer](05-services.md) - Sync services used by handlers
- [Batch Synchronization](04-batch-sync.md) - Batch optimization strategy
- [Common Patterns & Gotchas](10-common-patterns.md) - Best practices
