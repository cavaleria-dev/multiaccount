<?php

namespace App\Jobs;

use App\Models\SyncQueue;
use App\Services\ProductSyncService;
use App\Services\ServiceSyncService;
use App\Services\BundleSyncService;
use App\Services\CustomerOrderSyncService;
use App\Services\RetailDemandSyncService;
use App\Services\PurchaseOrderSyncService;
use App\Services\SyncStatisticsService;
use App\Services\WebhookService;
use App\Services\ImageSyncService;
use App\Exceptions\RateLimitException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для обработки очереди синхронизации
 */
class ProcessSyncQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;

    /**
     * Execute the job.
     */
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
    ): void {
        // Детальное логирование для диагностики
        $totalPending = \DB::table('sync_queue')->where('status', 'pending')->count();
        $currentTime = now()->toDateTimeString();

        Log::info('ProcessSyncQueueJob START', [
            'current_time' => $currentTime,
            'total_pending_in_db' => $totalPending
        ]);

        // Обработать задачи из очереди (порциями по 50)
        // Используем короткую транзакцию с lockForUpdate для защиты от race conditions
        $tasks = \DB::transaction(function() {
            $selectedTasks = SyncQueue::where('status', 'pending')
                ->where(function($query) {
                    $query->whereNull('scheduled_at')
                          ->orWhere('scheduled_at', '<=', now());
                })
                ->orderBy('priority', 'desc')
                ->orderByRaw("payload->>'main_account_id'")  // Группировать по main account для балансировки
                ->orderBy('scheduled_at', 'asc')
                ->limit(50)
                ->lockForUpdate()  // Блокировка для предотвращения дублирования
                ->get();

            // Обновить статус ВНУТРИ транзакции (пока держим блокировку)
            foreach ($selectedTasks as $task) {
                $task->update([
                    'status' => 'processing',
                    'started_at' => now(),
                ]);
            }

            return $selectedTasks;
        }); // Транзакция завершается, блокировка снимается (~100ms)

        if ($tasks->isEmpty()) {
            Log::warning('ProcessSyncQueueJob: No tasks ready to process', [
                'total_pending_in_db' => $totalPending,
                'tasks_found_by_query' => 0,
                'reason' => 'Either all tasks have scheduled_at in future, or no pending tasks exist'
            ]);
            return;
        }

        Log::info('Processing sync queue', [
            'tasks_count' => $tasks->count()
        ]);

        // Балансировка задач по main accounts для равномерного использования rate limits
        $balancedTasks = $this->balanceTasksByMainAccount($tasks, 50);

        // Предзагрузка accounts и settings для оптимизации (N+1 fix)
        $accountIds = $balancedTasks->pluck('account_id')->unique();
        $mainAccountIds = $balancedTasks->map(function($task) {
            return $task->payload['main_account_id'] ?? null;
        })->filter()->unique();

        $allAccountIds = $accountIds->merge($mainAccountIds)->unique();

        $accountsCache = \App\Models\Account::whereIn('account_id', $allAccountIds)
            ->get()
            ->keyBy('account_id');

        $settingsCache = \App\Models\SyncSetting::whereIn('account_id', $accountIds)
            ->get()
            ->keyBy('account_id');

        Log::info('Pre-loaded accounts and settings', [
            'accounts_count' => $accountsCache->count(),
            'settings_count' => $settingsCache->count(),
            'tasks_count' => $balancedTasks->count()
        ]);

        // Кеш исчерпанных main accounts в пределах текущего batch
        $exhaustedMainAccounts = [];

        foreach ($balancedTasks as $task) {
            try {
                // Проверить: не исчерпан ли main account для этой задачи?
                $payload = $task->payload;
                $mainAccountId = $payload['main_account_id'] ?? null;

                if ($mainAccountId && isset($exhaustedMainAccounts[$mainAccountId])) {
                    $retryAfter = $exhaustedMainAccounts[$mainAccountId];

                    Log::debug('Skipping task for exhausted main account', [
                        'task_id' => $task->id,
                        'main_account_id' => substr($mainAccountId, 0, 8) . '...',
                        'retry_after' => $retryAfter
                    ]);

                    $task->update([
                        'status' => 'pending',
                        'scheduled_at' => now()->addSeconds($retryAfter),
                        'error' => 'Main account rate limit exhausted (batch skipped)'
                    ]);

                    continue;
                }

                $startTime = microtime(true);

                // Обработать задачу (передаём cache для оптимизации)
                $this->processTask(
                    $task,
                    $productSyncService,
                    $serviceSyncService,
                    $bundleSyncService,
                    $customerOrderSyncService,
                    $retailDemandSyncService,
                    $purchaseOrderSyncService,
                    $webhookService,
                    $imageSyncService,
                    $accountsCache,
                    $settingsCache
                );

                $duration = (int)((microtime(true) - $startTime) * 1000); // ms

                // Отметить как выполненное
                $task->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                // Записать статистику
                if (in_array($task->entity_type, ['product', 'variant', 'product_variants', 'batch_products', 'bundle', 'batch_bundles', 'service', 'batch_services'])) {
                    $payload = $task->payload;
                    if (isset($payload['main_account_id'])) {
                        $statisticsService->recordSync(
                            $payload['main_account_id'],
                            $task->account_id,
                            'product',
                            true,
                            $duration
                        );
                    }
                } elseif (in_array($task->entity_type, ['customerorder', 'retaildemand', 'purchaseorder'])) {
                    // Для заказов нужно получить parent_account_id из child_accounts
                    $parentAccountId = $this->getParentAccountId($task->account_id);
                    if ($parentAccountId) {
                        $statisticsService->recordSync(
                            $parentAccountId,
                            $task->account_id,
                            'order',
                            true,
                            $duration
                        );
                    }
                }

            } catch (RateLimitException $e) {
                // Rate limit превышен - отложить задачу
                $retryAfterSeconds = $e->getRetryAfterSeconds();
                $rateLimitInfo = $e->getRateLimitInfo();

                $task->update([
                    'status' => 'pending',
                    'error' => 'Rate limit exceeded',
                    'rate_limit_info' => $rateLimitInfo,
                    'scheduled_at' => now()->addSeconds($retryAfterSeconds),
                ]);

                Log::warning('Task postponed due to rate limit', [
                    'task_id' => $task->id,
                    'retry_after_seconds' => $retryAfterSeconds,
                    'rate_limit_info' => $rateLimitInfo
                ]);

                // Определить scope rate limit и добавить в кеш если это main account
                $payload = $task->payload;
                $mainAccountId = $payload['main_account_id'] ?? null;
                $isGlobalRateLimit = ($rateLimitInfo['remaining'] ?? 0) <= 1;

                if ($isGlobalRateLimit && $mainAccountId) {
                    // Глобальный rate limit на main account
                    // Добавить в кеш исчерпанных accounts
                    $exhaustedMainAccounts[$mainAccountId] = $retryAfterSeconds;

                    Log::warning('Global rate limit detected on main account', [
                        'main_account_id' => substr($mainAccountId, 0, 8) . '...',
                        'retry_after' => $retryAfterSeconds
                    ]);

                    // Отложить ВСЕ задачи для этого main account
                    $this->postponeAllMainAccountTasks($mainAccountId, $retryAfterSeconds, $task->id);

                    // Прервать обработку текущего batch (все задачи для main account отложены)
                    break;
                } else {
                    // Endpoint-specific rate limit или child account rate limit
                    // Продолжить обработку других задач
                    Log::info('Endpoint-specific rate limit, continuing with other tasks', [
                        'task_id' => $task->id
                    ]);

                    continue;
                }

            } catch (\Throwable $e) {
                // Ловим и Exception, и Error (включая TypeError)
                $task->increment('attempts');

                // Специальная обработка для batch задач (batch_services, batch_products, batch_bundles)
                // Если batch POST упал целиком → создать индивидуальные retry задачи для всех сущностей
                if (in_array($task->entity_type, ['batch_services', 'batch_products', 'batch_bundles'])) {
                    $this->handleBatchTaskFailure($task, $e, $statisticsService);
                    continue; // Переходим к следующей задаче
                }

                // Проверить, стоит ли повторять задачу
                $shouldRetry = $this->isRetryableError($e->getMessage());

                if (!$shouldRetry || $task->attempts >= $task->max_attempts) {
                    // Постоянная ошибка (4xx) или исчерпаны попытки - сразу failed
                    $task->update([
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ]);

                    // Записать статистику о неудаче
                    if (in_array($task->entity_type, ['product', 'variant', 'product_variants', 'batch_products', 'bundle', 'batch_bundles', 'service', 'batch_services'])) {
                        $payload = $task->payload;
                        if (isset($payload['main_account_id'])) {
                            $statisticsService->recordSync(
                                $payload['main_account_id'],
                                $task->account_id,
                                'product',
                                false,
                                0
                            );
                        }
                    }

                    Log::error('Task failed permanently', [
                        'task_id' => $task->id,
                        'entity_type' => $task->entity_type,
                        'entity_id' => $task->entity_id,
                        'attempts' => $task->attempts,
                        'retryable' => $shouldRetry,
                        'error' => $e->getMessage()
                    ]);

                } else {
                    // Временная ошибка (5xx, network) - retry с exponential backoff
                    $task->update([
                        'status' => 'pending',
                        'error' => $e->getMessage(),
                        'scheduled_at' => now()->addMinutes(5 * $task->attempts), // 5мин, 10мин, 15мин
                    ]);

                    Log::warning('Task failed, will retry (retryable error)', [
                        'task_id' => $task->id,
                        'attempts' => $task->attempts,
                        'max_attempts' => $task->max_attempts,
                        'retry_at' => now()->addMinutes(5 * $task->attempts)->toDateTimeString(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Обработать конкретную задачу
     */
    protected function processTask(
        SyncQueue $task,
        ProductSyncService $productSyncService,
        ServiceSyncService $serviceSyncService,
        BundleSyncService $bundleSyncService,
        CustomerOrderSyncService $customerOrderSyncService,
        RetailDemandSyncService $retailDemandSyncService,
        PurchaseOrderSyncService $purchaseOrderSyncService,
        WebhookService $webhookService,
        ImageSyncService $imageSyncService,
        \Illuminate\Support\Collection $accountsCache,
        \Illuminate\Support\Collection $settingsCache
    ): void {
        // Гарантировать что payload это массив, а не null
        $payload = $task->payload ?? [];

        match($task->entity_type) {
            'product' => $this->processProductSync($task, $payload, $productSyncService, $accountsCache, $settingsCache),
            'variant' => $this->processVariantSync($task, $payload, $productSyncService, $accountsCache, $settingsCache),
            'product_variants' => $this->processBatchVariantSync($task, $payload, $productSyncService, $accountsCache, $settingsCache),
            'batch_products' => $this->processBatchProductSync($task, $payload, $productSyncService, $accountsCache, $settingsCache),
            'bundle' => $this->processBundleSync($task, $payload, $bundleSyncService, $accountsCache, $settingsCache),
            'batch_bundles' => $this->processBatchBundleSync($task, $payload, $bundleSyncService, $accountsCache, $settingsCache),
            'service' => $this->processServiceSync($task, $payload, $serviceSyncService, $accountsCache, $settingsCache),
            'batch_services' => $this->processBatchServiceSync($task, $payload, $serviceSyncService, $accountsCache, $settingsCache),
            'customerorder' => $this->processCustomerOrderSync($task, $payload, $customerOrderSyncService, $accountsCache, $settingsCache),
            'retaildemand' => $this->processRetailDemandSync($task, $payload, $retailDemandSyncService, $accountsCache, $settingsCache),
            'purchaseorder' => $this->processPurchaseOrderSync($task, $payload, $purchaseOrderSyncService, $accountsCache, $settingsCache),
            'webhook' => $this->processWebhookCheck($task, $webhookService),
            'image_sync' => $this->processImageSync($task, $payload, $imageSyncService, $accountsCache),
            default => Log::warning("Unknown entity type in queue: {$task->entity_type}")
        };
    }

    /**
     * Обработать синхронизацию товара
     */
    protected function processProductSync(SyncQueue $task, array $payload, ProductSyncService $productSyncService): void
    {
        // Проверить что payload содержит необходимые данные
        if (empty($payload) || !isset($payload['main_account_id'])) {
            Log::warning('Task skipped: missing main_account_id in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'entity_id' => $task->entity_id,
                'payload' => $payload
            ]);
            throw new \Exception('Invalid payload: missing main_account_id');
        }

        if ($task->operation === 'delete') {
            // При удалении или архивации товара в главном - архивируем во всех дочерних
            $archivedCount = $productSyncService->archiveProduct(
                $payload['main_account_id'],
                $task->entity_id
            );

            Log::info('Product archived in child accounts', [
                'task_id' => $task->id,
                'product_id' => $task->entity_id,
                'archived_count' => $archivedCount
            ]);
            return;
        }

        $productSyncService->syncProduct(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );
    }

    /**
     * Обработать синхронизацию модификации
     */
    protected function processVariantSync(SyncQueue $task, array $payload, ProductSyncService $productSyncService): void
    {
        // Проверить что payload содержит необходимые данные
        if (empty($payload) || !isset($payload['main_account_id'])) {
            Log::warning('Task skipped: missing main_account_id in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'entity_id' => $task->entity_id,
                'payload' => $payload
            ]);
            throw new \Exception('Invalid payload: missing main_account_id');
        }

        if ($task->operation === 'delete') {
            // При удалении или архивации модификации в главном - архивируем во всех дочерних
            $archivedCount = $productSyncService->archiveVariant(
                $payload['main_account_id'],
                $task->entity_id
            );

            Log::info('Variant archived in child accounts', [
                'task_id' => $task->id,
                'variant_id' => $task->entity_id,
                'archived_count' => $archivedCount
            ]);
            return;
        }

        $productSyncService->syncVariant(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );
    }

    /**
     * Обработать пакетную синхронизацию модификаций одного товара
     *
     * entity_id = product ID, загружаем все variants этого product одним запросом
     */
    protected function processBatchVariantSync(SyncQueue $task, array $payload, ProductSyncService $productSyncService): void
    {
        // Проверить что payload содержит необходимые данные
        if (empty($payload) || !isset($payload['main_account_id'])) {
            Log::warning('Batch variant task skipped: missing main_account_id in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'entity_id' => $task->entity_id,
                'payload' => $payload
            ]);
            throw new \Exception('Invalid payload: missing main_account_id');
        }

        $mainAccountId = $payload['main_account_id'];
        $childAccountId = $task->account_id;
        $productId = $task->entity_id; // ID родительского товара

        try {
            // Получить main account для access token
            $mainAccount = \App\Models\Account::where('account_id', $mainAccountId)->firstOrFail();
            $moysklad = app(\App\Services\MoySkladService::class);
            $variantSyncService = app(\App\Services\VariantSyncService::class);

            // Получить настройки синхронизации
            $syncSettings = \App\Models\SyncSetting::where('account_id', $childAccountId)->first();

            if (!$syncSettings) {
                throw new \Exception("Sync settings not found for child account {$childAccountId}");
            }

            // Загрузить все модификации этого товара с expand
            // ВАЖНО: МойСклад API лимиты - max 100 с expand (не 1000!)
            $variants = [];
            $offset = 0;
            $limit = 100;
            $totalLoaded = 0;

            do {
                $response = $moysklad
                    ->setAccessToken($mainAccount->access_token)
                    ->get('/entity/variant', [
                        'filter' => "productid={$productId}",
                        'expand' => 'product.salePrices,characteristics,packs.uom,images',
                        'limit' => $limit,
                        'offset' => $offset
                    ]);

                $rows = $response['data']['rows'] ?? [];
                $variants = array_merge($variants, $rows);
                $totalLoaded += count($rows);
                $offset += $limit;

                Log::debug('Loaded variants batch', [
                    'task_id' => $task->id,
                    'product_id' => $productId,
                    'batch_size' => count($rows),
                    'total_loaded' => $totalLoaded,
                    'offset' => $offset
                ]);

            } while (count($rows) === $limit);

            if (empty($variants)) {
                Log::info('No variants found for product', [
                    'task_id' => $task->id,
                    'product_id' => $productId
                ]);
                return;
            }

            Log::info('Batch variant sync started', [
                'task_id' => $task->id,
                'product_id' => $productId,
                'variants_count' => count($variants),
                'api_requests' => ceil(count($variants) / $limit)
            ]);

            $successCount = 0;
            $failedCount = 0;

            // Синхронизировать каждую модификацию с уже загруженными данными
            foreach ($variants as $variant) {
                try {
                    // Синхронизировать variant и получить результат
                    $result = $variantSyncService->syncVariantData($mainAccountId, $childAccountId, $variant);

                    if ($result) {
                        $successCount++;

                        // Синхронизировать изображения (если включено)
                        if ($syncSettings->sync_images || $syncSettings->sync_images_all) {
                            $images = $variant['images']['rows'] ?? [];

                            if (!empty($images)) {
                                $this->queueImageSyncForEntity(
                                    $mainAccountId,
                                    $childAccountId,
                                    'variant',
                                    $variant['id'],
                                    $result['id'],
                                    $images,
                                    $syncSettings
                                );
                            }
                        }
                    } else {
                        // Variant был отфильтрован или sync disabled
                        $successCount++;
                    }

                } catch (\Exception $e) {
                    $failedCount++;

                    Log::error('Failed to sync variant in batch', [
                        'task_id' => $task->id,
                        'product_id' => $productId,
                        'variant_id' => $variant['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);

                    // Создать индивидуальную задачу для повторной попытки
                    SyncQueue::create([
                        'account_id' => $childAccountId,
                        'entity_type' => 'variant',
                        'entity_id' => $variant['id'],
                        'operation' => 'update',
                        'priority' => 5, // Средний приоритет для retry
                        'scheduled_at' => now()->addMinutes(5),
                        'status' => 'pending',
                        'attempts' => 0,
                        'payload' => [
                            'main_account_id' => $mainAccountId,
                            'batch_retry' => true
                        ]
                    ]);
                }
            }

            Log::info('Batch variant sync completed', [
                'task_id' => $task->id,
                'product_id' => $productId,
                'total_variants' => count($variants),
                'success_count' => $successCount,
                'failed_count' => $failedCount
            ]);

            // Если более 50% упали - выбросить исключение для retry всей задачи
            if ($failedCount > count($variants) / 2) {
                throw new \Exception("Batch sync failed: {$failedCount} of " . count($variants) . " variants failed");
            }

        } catch (\Exception $e) {
            Log::error('Batch variant sync failed completely', [
                'task_id' => $task->id,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Обработать пакетную синхронизацию товаров (batch POST)
     *
     * Получает готовые товары из payload, подготавливает их (используя только кеш),
     * отправляет batch POST в МойСклад и создает mappings.
     */
    protected function processBatchProductSync(
        SyncQueue $task,
        array $payload,
        ProductSyncService $productSyncService,
        \Illuminate\Support\Collection $accountsCache,
        \Illuminate\Support\Collection $settingsCache
    ): void
    {
        // Проверить что payload содержит необходимые данные
        if (empty($payload) || !isset($payload['main_account_id'])) {
            Log::warning('Batch product task skipped: missing main_account_id in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'payload_keys' => array_keys($payload)
            ]);
            throw new \Exception('Invalid payload: missing main_account_id');
        }

        if (!isset($payload['products']) || empty($payload['products'])) {
            Log::warning('Batch product task skipped: missing products in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'payload_keys' => array_keys($payload)
            ]);
            throw new \Exception('Invalid payload: missing products array');
        }

        $mainAccountId = $payload['main_account_id'];
        $childAccountId = $task->account_id;
        $products = $payload['products'];

        try {
            // Получить accounts и settings из cache (N+1 optimization)
            $mainAccount = $accountsCache[$mainAccountId] ?? null;
            $childAccount = $accountsCache[$childAccountId] ?? null;
            $syncSettings = $settingsCache[$childAccountId] ?? null;

            if (!$mainAccount || !$childAccount) {
                throw new \Exception("Account not found in cache: main={$mainAccountId}, child={$childAccountId}");
            }

            if (!$syncSettings) {
                throw new \Exception("Sync settings not found for child account {$childAccountId}");
            }

            // Проверить rate limit ПЕРЕД началом batch операции
            $rateLimitTracker = app(\App\Services\RateLimitTracker::class);
            $estimatedCost = $rateLimitTracker->estimateBatchCost('product', count($products), true);

            $check = $rateLimitTracker->checkAvailability($mainAccountId, $estimatedCost);

            if (!$check['available']) {
                Log::warning('Batch product sync postponed: rate limit exhausted', [
                    'task_id' => $task->id,
                    'main_account_id' => substr($mainAccountId, 0, 8) . '...',
                    'estimated_cost' => $estimatedCost,
                    'remaining' => $check['remaining'],
                    'retry_after' => $check['retry_after']
                ]);

                // Отложить задачу и все задачи для этого main account
                $this->postponeAllMainAccountTasks($mainAccountId, $check['retry_after'], $task->id);

                // Throw RateLimitException чтобы handle() мог обработать
                throw new \App\Exceptions\RateLimitException(
                    "Rate limit exhausted for main account",
                    $check['retry_after'] * 1000,
                    [
                        'remaining' => $check['remaining'],
                        'retry_after' => $check['retry_after'] * 1000
                    ]
                );
            }

            Log::info('Batch product sync started', [
                'task_id' => $task->id,
                'products_count' => count($products),
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'estimated_cost' => $estimatedCost,
                'rate_limit_remaining' => $check['remaining']
            ]);

            // Фильтровать товары ПЕРЕД синхронизацией групп
            $filteredProducts = [];
            foreach ($products as $product) {
                // Используем SyncHelpers::passesFilters для проверки
                if ($productSyncService->passesFilters($product, $syncSettings, 'product')) {
                    $filteredProducts[] = $product;
                }
            }

            if (empty($filteredProducts)) {
                Log::info('All products filtered out in batch', [
                    'task_id' => $task->id,
                    'total_products' => count($products),
                    'filtered_out' => count($products)
                ]);
                return;
            }

            Log::info('Products filtered for batch sync', [
                'task_id' => $task->id,
                'total_products' => count($products),
                'filtered_products' => count($filteredProducts),
                'filtered_out' => count($products) - count($filteredProducts)
            ]);

            // Pre-sync групп товаров (если настройка включена)
            if ($syncSettings->create_product_folders) {
                $productFolderSyncService = app(\App\Services\ProductFolderSyncService::class);
                $productFolderSyncService->syncFoldersForEntities(
                    $mainAccountId,
                    $childAccountId,
                    $filteredProducts
                );
            }

            // Подготовить товары для batch POST (используя только кеш)
            $preparedProducts = [];
            $skippedCount = 0;

            foreach ($filteredProducts as $product) {
                $prepared = $productSyncService->prepareProductForBatch(
                    $product,
                    $mainAccountId,
                    $childAccountId,
                    $syncSettings
                );

                if ($prepared) {
                    $preparedProducts[] = $prepared;
                } else {
                    $skippedCount++; // Skipped during preparation
                }
            }

            if (empty($preparedProducts)) {
                Log::info('All products skipped during preparation', [
                    'task_id' => $task->id,
                    'filtered_products' => count($filteredProducts),
                    'skipped' => $skippedCount
                ]);
                return;
            }

            Log::info('Products prepared for batch POST', [
                'task_id' => $task->id,
                'prepared_count' => count($preparedProducts),
                'skipped_count' => $skippedCount
            ]);

            // Получить child account для access token
            $childAccount = \App\Models\Account::where('account_id', $childAccountId)->firstOrFail();
            $moysklad = app(\App\Services\MoySkladService::class);

            // Отправить batch POST
            $response = $moysklad
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $mainAccountId,
                    entityType: 'batch_products',
                    entityId: null  // Batch - нет конкретного ID
                )
                ->setOperationContext(
                    operationType: 'batch_create',
                    operationResult: 'success'
                )
                ->batchCreateProducts($preparedProducts);

            $createdProducts = $response['data'] ?? [];

            Log::info('Batch POST completed', [
                'task_id' => $task->id,
                'sent_count' => count($preparedProducts),
                'received_count' => count($createdProducts)
            ]);

            // Разделить успешные результаты от ошибок
            $successfulResults = [];
            $errorResults = [];

            foreach ($createdProducts as $index => $result) {
                // МойСклад возвращает mixed array: успехи {"id": "xxx"} и ошибки {"errors": [...]}
                if (isset($result['errors']) && is_array($result['errors'])) {
                    $errorResults[$index] = $result;
                } else {
                    $successfulResults[$index] = $result;
                }
            }

            Log::info('Batch POST results separated', [
                'task_id' => $task->id,
                'successful_count' => count($successfulResults),
                'error_count' => count($errorResults)
            ]);

            // Обработать ошибки (код 1021 - сущность не найдена)
            $deletedMappingsCount = 0;
            $retryTasksCount = 0;

            foreach ($errorResults as $index => $errorResult) {
                $originalId = $preparedProducts[$index]['_original_id'] ?? null;

                if (!$originalId) {
                    Log::warning('Error result without _original_id', [
                        'task_id' => $task->id,
                        'index' => $index,
                        'errors' => $errorResult['errors']
                    ]);
                    continue;
                }

                // Извлечь код ошибки (проверяем первую ошибку в массиве)
                $firstError = $errorResult['errors'][0] ?? null;
                $errorCode = $firstError['code'] ?? null;
                $errorMessage = $firstError['error'] ?? 'Unknown error';

                Log::error('Batch POST error for product', [
                    'task_id' => $task->id,
                    'index' => $index,
                    'original_id' => $originalId,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage
                ]);

                // Код 1021 = "Объект не найден" - означает товар удален в child account
                if ($errorCode === 1021) {
                    // Удалить stale mapping
                    $deleted = \App\Models\EntityMapping::where([
                        'parent_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'entity_type' => 'product',
                        'parent_entity_id' => $originalId
                    ])->delete();

                    if ($deleted > 0) {
                        $deletedMappingsCount++;
                        Log::info('Deleted stale product mapping (entity not found in child)', [
                            'task_id' => $task->id,
                            'original_id' => $originalId,
                            'error_code' => $errorCode
                        ]);
                    }

                    // Создать retry задачу с операцией CREATE (не UPDATE)
                    SyncQueue::create([
                        'account_id' => $childAccountId,
                        'entity_type' => 'product',
                        'entity_id' => $originalId,
                        'operation' => 'create',  // ВАЖНО: CREATE, не update
                        'priority' => 5,
                        'scheduled_at' => now()->addMinutes(5),
                        'status' => 'pending',
                        'attempts' => 0,
                        'payload' => [
                            'main_account_id' => $mainAccountId,
                            'batch_retry' => true,
                            'retry_reason' => 'entity_deleted_in_child'
                        ]
                    ]);

                    $retryTasksCount++;

                    Log::info('Created retry task as CREATE for deleted product', [
                        'original_task_id' => $task->id,
                        'product_id' => $originalId
                    ]);
                } else {
                    // Другие ошибки - создать retry как UPDATE
                    SyncQueue::create([
                        'account_id' => $childAccountId,
                        'entity_type' => 'product',
                        'entity_id' => $originalId,
                        'operation' => 'update',
                        'priority' => 5,
                        'scheduled_at' => now()->addMinutes(5),
                        'status' => 'pending',
                        'attempts' => 0,
                        'payload' => [
                            'main_account_id' => $mainAccountId,
                            'batch_retry' => true,
                            'error_code' => $errorCode
                        ]
                    ]);

                    $retryTasksCount++;

                    Log::info('Created retry task for batch error', [
                        'original_task_id' => $task->id,
                        'product_id' => $originalId,
                        'error_code' => $errorCode
                    ]);
                }
            }

            // Создать mappings ТОЛЬКО для успешных результатов
            $mappingCount = 0;
            $failedCount = 0;

            foreach ($successfulResults as $index => $createdProduct) {
                try {
                    // Получить оригинальный ID из метаданных
                    $originalId = $preparedProducts[$index]['_original_id'] ?? null;
                    $isUpdate = $preparedProducts[$index]['_is_update'] ?? false;

                    if (!$originalId) {
                        Log::warning('Cannot create mapping: missing _original_id', [
                            'task_id' => $task->id,
                            'index' => $index,
                            'child_product_id' => $createdProduct['id'] ?? 'unknown'
                        ]);
                        $failedCount++;
                        continue;
                    }

                    // Обернуть создание mapping и image sync задачи в транзакцию
                    // для обеспечения атомарности (либо всё, либо ничего)
                    \DB::transaction(function() use (
                        $mainAccountId,
                        $childAccountId,
                        $originalId,
                        $createdProduct,
                        $syncSettings,
                        $preparedProducts,
                        $index
                    ) {
                        // Создать или обновить mapping
                        \App\Models\EntityMapping::updateOrCreate(
                            [
                                'parent_account_id' => $mainAccountId,
                                'child_account_id' => $childAccountId,
                                'entity_type' => 'product',
                                'parent_entity_id' => $originalId
                            ],
                            [
                                'child_entity_id' => $createdProduct['id'],
                                'sync_direction' => 'main_to_child',
                                'match_field' => $syncSettings->product_match_field ?? 'code',
                                'match_value' => $createdProduct['code'] ?? $createdProduct['name']
                            ]
                        );

                        // Синхронизировать изображения (если включено)
                        if ($syncSettings->sync_images || $syncSettings->sync_images_all) {
                            $originalImages = $preparedProducts[$index]['_original_images'] ?? [];

                            if (!empty($originalImages)) {
                                $this->queueImageSyncForEntity(
                                    $mainAccountId,
                                    $childAccountId,
                                    'product',
                                    $originalId,
                                    $createdProduct['id'],
                                    $originalImages,
                                    $syncSettings
                                );
                            }
                        }
                    }); // Транзакция завершается - либо всё создано, либо всё откачено

                    $mappingCount++;

                } catch (\Exception $e) {
                    $failedCount++;

                    // Получить оригинальный ID
                    $originalId = $preparedProducts[$index]['_original_id'] ?? null;

                    Log::error('Failed to create mapping in batch', [
                        'task_id' => $task->id,
                        'index' => $index,
                        'original_id' => $originalId,
                        'error' => $e->getMessage()
                    ]);

                    // Создать индивидуальную retry задачу для упавшего товара
                    if ($originalId) {
                        SyncQueue::create([
                            'account_id' => $childAccountId,
                            'entity_type' => 'product',
                            'entity_id' => $originalId,
                            'operation' => 'update',
                            'priority' => 5,
                            'scheduled_at' => now()->addMinutes(5),
                            'status' => 'pending',
                            'attempts' => 0,
                            'payload' => [
                                'main_account_id' => $mainAccountId,
                                'batch_retry' => true,
                                'retry_reason' => 'mapping_creation_failed'
                            ]
                        ]);

                        Log::info('Created individual retry task for failed product', [
                            'original_task_id' => $task->id,
                            'product_id' => $originalId
                        ]);
                    }
                }
            }

            Log::info('Batch product sync completed', [
                'task_id' => $task->id,
                'total_products' => count($products),
                'prepared_count' => count($preparedProducts),
                'successful_results' => count($successfulResults),
                'error_results' => count($errorResults),
                'mappings_created' => $mappingCount,
                'failed_mappings' => $failedCount,
                'deleted_stale_mappings' => $deletedMappingsCount,
                'retry_tasks_created' => $retryTasksCount
            ]);

            // Если более 50% упали - выбросить исключение для retry всей задачи
            if ($failedCount > count($preparedProducts) / 2) {
                throw new \Exception("Batch sync failed: {$failedCount} of " . count($preparedProducts) . " products failed");
            }

        } catch (\Exception $e) {
            Log::error('Batch product sync failed completely', [
                'task_id' => $task->id,
                'products_count' => count($products),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Обработать пакетную синхронизацию услуг (batch POST)
     *
     * Получает готовые услуги из payload, подготавливает их (используя только кеш),
     * отправляет batch POST в МойСклад и создает mappings.
     */
    protected function processBatchServiceSync(
        SyncQueue $task,
        array $payload,
        ServiceSyncService $serviceSyncService,
        \Illuminate\Support\Collection $accountsCache,
        \Illuminate\Support\Collection $settingsCache
    ): void
    {
        // Проверить что payload содержит необходимые данные
        if (empty($payload) || !isset($payload['main_account_id'])) {
            Log::warning('Batch service task skipped: missing main_account_id in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'payload_keys' => array_keys($payload)
            ]);
            throw new \Exception('Invalid payload: missing main_account_id');
        }

        if (!isset($payload['services']) || empty($payload['services'])) {
            Log::warning('Batch service task skipped: missing services in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'payload_keys' => array_keys($payload)
            ]);
            throw new \Exception('Invalid payload: missing services array');
        }

        $mainAccountId = $payload['main_account_id'];
        $childAccountId = $task->account_id;
        $services = $payload['services'];

        try {
            // Получить accounts и settings из cache (N+1 optimization)
            $mainAccount = $accountsCache[$mainAccountId] ?? null;
            $childAccount = $accountsCache[$childAccountId] ?? null;
            $syncSettings = $settingsCache[$childAccountId] ?? null;

            if (!$mainAccount || !$childAccount) {
                throw new \Exception("Account not found in cache: main={$mainAccountId}, child={$childAccountId}");
            }

            if (!$syncSettings) {
                throw new \Exception("Sync settings not found for child account {$childAccountId}");
            }

            // Проверить rate limit ПЕРЕД началом batch операции
            $rateLimitTracker = app(\App\Services\RateLimitTracker::class);
            $estimatedCost = $rateLimitTracker->estimateBatchCost('service', count($services), false);

            $check = $rateLimitTracker->checkAvailability($mainAccountId, $estimatedCost);

            if (!$check['available']) {
                Log::warning('Batch service sync postponed: rate limit exhausted', [
                    'task_id' => $task->id,
                    'main_account_id' => substr($mainAccountId, 0, 8) . '...',
                    'estimated_cost' => $estimatedCost,
                    'remaining' => $check['remaining'],
                    'retry_after' => $check['retry_after']
                ]);

                // Отложить задачу и все задачи для этого main account
                $this->postponeAllMainAccountTasks($mainAccountId, $check['retry_after'], $task->id);

                // Throw RateLimitException чтобы handle() мог обработать
                throw new \App\Exceptions\RateLimitException(
                    "Rate limit exhausted for main account",
                    $check['retry_after'] * 1000,
                    [
                        'remaining' => $check['remaining'],
                        'retry_after' => $check['retry_after'] * 1000
                    ]
                );
            }

            Log::info('Batch service sync started', [
                'task_id' => $task->id,
                'services_count' => count($services),
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'estimated_cost' => $estimatedCost,
                'rate_limit_remaining' => $check['remaining']
            ]);

            // Фильтровать услуги ПЕРЕД синхронизацией групп
            $filteredServices = [];
            foreach ($services as $service) {
                // Используем SyncHelpers::passesFilters для проверки
                if ($serviceSyncService->passesFilters($service, $syncSettings, 'service')) {
                    $filteredServices[] = $service;
                }
            }

            if (empty($filteredServices)) {
                Log::info('All services filtered out in batch', [
                    'task_id' => $task->id,
                    'total_services' => count($services),
                    'filtered_out' => count($services)
                ]);
                return;
            }

            Log::info('Services filtered for batch sync', [
                'task_id' => $task->id,
                'total_services' => count($services),
                'filtered_services' => count($filteredServices),
                'filtered_out' => count($services) - count($filteredServices)
            ]);

            // Pre-sync групп товаров (если настройка включена)
            if ($syncSettings->create_product_folders) {
                $productFolderSyncService = app(\App\Services\ProductFolderSyncService::class);
                $productFolderSyncService->syncFoldersForEntities(
                    $mainAccountId,
                    $childAccountId,
                    $filteredServices
                );
            }

            // Подготовить услуги для batch POST (используя только кеш)
            $preparedServices = [];
            $skippedCount = 0;

            foreach ($filteredServices as $service) {
                $prepared = $serviceSyncService->prepareServiceForBatch(
                    $service,
                    $mainAccountId,
                    $childAccountId,
                    $syncSettings
                );

                if ($prepared) {
                    $preparedServices[] = $prepared;
                } else {
                    $skippedCount++;
                }
            }

            if (empty($preparedServices)) {
                Log::info('All services skipped during preparation', [
                    'task_id' => $task->id,
                    'filtered_services' => count($filteredServices),
                    'skipped' => $skippedCount
                ]);
                return;
            }

            // Логировать служебные поля для диагностики
            $updateCount = 0;
            $createCount = 0;
            foreach ($preparedServices as $service) {
                if ($service['_is_update'] ?? false) {
                    $updateCount++;
                } else {
                    $createCount++;
                }
            }

            Log::info('Services prepared for batch POST', [
                'task_id' => $task->id,
                'prepared_count' => count($preparedServices),
                'update_count' => $updateCount,
                'create_count' => $createCount
            ]);

            // Получить child account для access token
            $childAccount = \App\Models\Account::where('account_id', $childAccountId)->firstOrFail();
            $moysklad = app(\App\Services\MoySkladService::class);

            // Отправить batch POST
            $response = $moysklad
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $mainAccountId,
                    entityType: 'batch_services',
                    entityId: null  // Batch - нет конкретного ID
                )
                ->setOperationContext(
                    operationType: 'batch_create',
                    operationResult: 'success'
                )
                ->batchCreateServices($preparedServices);

            $createdServices = $response['data'] ?? [];

            Log::info('Batch POST completed', [
                'task_id' => $task->id,
                'sent_count' => count($preparedServices),
                'received_count' => count($createdServices)
            ]);

            // Разделить успешные результаты от ошибок
            $successfulResults = [];
            $errorResults = [];

            foreach ($createdServices as $index => $result) {
                // МойСклад возвращает mixed array: успехи {"id": "xxx"} и ошибки {"errors": [...]}
                if (isset($result['errors']) && is_array($result['errors'])) {
                    $errorResults[$index] = $result;
                } else {
                    $successfulResults[$index] = $result;
                }
            }

            Log::info('Batch POST results separated', [
                'task_id' => $task->id,
                'successful_count' => count($successfulResults),
                'error_count' => count($errorResults)
            ]);

            // Обработать ошибки (код 1021 - сущность не найдена)
            $deletedMappingsCount = 0;
            $retryTasksCount = 0;

            foreach ($errorResults as $index => $errorResult) {
                $originalId = $preparedServices[$index]['_original_id'] ?? null;

                if (!$originalId) {
                    Log::warning('Error result without _original_id', [
                        'task_id' => $task->id,
                        'index' => $index,
                        'errors' => $errorResult['errors']
                    ]);
                    continue;
                }

                // Извлечь код ошибки (проверяем первую ошибку в массиве)
                $firstError = $errorResult['errors'][0] ?? null;
                $errorCode = $firstError['code'] ?? null;
                $errorMessage = $firstError['error'] ?? 'Unknown error';

                Log::error('Batch POST error for service', [
                    'task_id' => $task->id,
                    'index' => $index,
                    'original_id' => $originalId,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage
                ]);

                // Код 1021 = "Объект не найден" - означает услуга удалена в child account
                if ($errorCode === 1021) {
                    // Удалить stale mapping
                    $deleted = \App\Models\EntityMapping::where([
                        'parent_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'entity_type' => 'service',
                        'parent_entity_id' => $originalId
                    ])->delete();

                    if ($deleted > 0) {
                        $deletedMappingsCount++;
                        Log::info('Deleted stale service mapping (entity not found in child)', [
                            'task_id' => $task->id,
                            'original_id' => $originalId,
                            'error_code' => $errorCode
                        ]);
                    }

                    // Создать retry задачу с операцией CREATE (не UPDATE)
                    SyncQueue::create([
                        'account_id' => $childAccountId,
                        'entity_type' => 'service',
                        'entity_id' => $originalId,
                        'operation' => 'create',  // ВАЖНО: CREATE, не update
                        'priority' => 5,
                        'scheduled_at' => now()->addMinutes(5),
                        'status' => 'pending',
                        'attempts' => 0,
                        'payload' => [
                            'main_account_id' => $mainAccountId,
                            'batch_retry' => true,
                            'retry_reason' => 'entity_deleted_in_child'
                        ]
                    ]);

                    $retryTasksCount++;

                    Log::info('Created retry task as CREATE for deleted service', [
                        'original_task_id' => $task->id,
                        'service_id' => $originalId
                    ]);
                } else {
                    // Другие ошибки - создать retry как UPDATE
                    SyncQueue::create([
                        'account_id' => $childAccountId,
                        'entity_type' => 'service',
                        'entity_id' => $originalId,
                        'operation' => 'update',
                        'priority' => 5,
                        'scheduled_at' => now()->addMinutes(5),
                        'status' => 'pending',
                        'attempts' => 0,
                        'payload' => [
                            'main_account_id' => $mainAccountId,
                            'batch_retry' => true,
                            'error_code' => $errorCode
                        ]
                    ]);

                    $retryTasksCount++;

                    Log::info('Created retry task for batch error', [
                        'original_task_id' => $task->id,
                        'service_id' => $originalId,
                        'error_code' => $errorCode
                    ]);
                }
            }

            // Создать mappings ТОЛЬКО для успешных результатов
            $mappingCount = 0;
            $failedCount = 0;

            foreach ($successfulResults as $index => $createdService) {
                try {
                    // Получить оригинальный ID из метаданных
                    $originalId = $preparedServices[$index]['_original_id'] ?? null;
                    $isUpdate = $preparedServices[$index]['_is_update'] ?? false;

                    if (!$originalId) {
                        Log::warning('Cannot create mapping: missing _original_id', [
                            'task_id' => $task->id,
                            'index' => $index,
                            'child_service_id' => $createdService['id'] ?? 'unknown'
                        ]);
                        $failedCount++;
                        continue;
                    }

                    // Создать или обновить mapping
                    \App\Models\EntityMapping::updateOrCreate(
                        [
                            'parent_account_id' => $mainAccountId,
                            'child_account_id' => $childAccountId,
                            'entity_type' => 'service',
                            'parent_entity_id' => $originalId
                        ],
                        [
                            'child_entity_id' => $createdService['id'],
                            'sync_direction' => 'main_to_child',
                            'match_field' => $syncSettings->service_match_field ?? 'code',
                            'match_value' => $createdService['code'] ?? $createdService['name']
                        ]
                    );

                    $mappingCount++;

                } catch (\Exception $e) {
                    $failedCount++;

                    // Получить оригинальный ID
                    $originalId = $preparedServices[$index]['_original_id'] ?? null;

                    Log::error('Failed to create mapping in batch', [
                        'task_id' => $task->id,
                        'index' => $index,
                        'original_id' => $originalId,
                        'error' => $e->getMessage()
                    ]);

                    // Создать индивидуальную retry задачу для упавшей услуги
                    if ($originalId) {
                        SyncQueue::create([
                            'account_id' => $childAccountId,
                            'entity_type' => 'service',
                            'entity_id' => $originalId,
                            'operation' => 'update',
                            'priority' => 5,
                            'scheduled_at' => now()->addMinutes(5),
                            'status' => 'pending',
                            'attempts' => 0,
                            'payload' => [
                                'main_account_id' => $mainAccountId,
                                'batch_retry' => true,
                                'retry_reason' => 'mapping_creation_failed'
                            ]
                        ]);

                        Log::info('Created individual retry task for failed service', [
                            'original_task_id' => $task->id,
                            'service_id' => $originalId
                        ]);
                    }
                }
            }

            Log::info('Batch service sync completed', [
                'task_id' => $task->id,
                'total_services' => count($services),
                'prepared_count' => count($preparedServices),
                'successful_results' => count($successfulResults),
                'error_results' => count($errorResults),
                'mappings_created' => $mappingCount,
                'failed_mappings' => $failedCount,
                'deleted_stale_mappings' => $deletedMappingsCount,
                'retry_tasks_created' => $retryTasksCount
            ]);

            // Если более 50% упали - выбросить исключение для retry всей задачи
            if ($failedCount > count($preparedServices) / 2) {
                throw new \Exception("Batch sync failed: {$failedCount} of " . count($preparedServices) . " services failed");
            }

        } catch (\Exception $e) {
            Log::error('Batch service sync failed completely', [
                'task_id' => $task->id,
                'services_count' => count($services),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Обработать пакетную синхронизацию комплектов (batch POST)
     *
     * Получает готовые комплекты из payload, подготавливает их (используя только кеш),
     * отправляет batch POST в МойСклад и создает mappings.
     */
    protected function processBatchBundleSync(
        SyncQueue $task,
        array $payload,
        BundleSyncService $bundleSyncService,
        \Illuminate\Support\Collection $accountsCache,
        \Illuminate\Support\Collection $settingsCache
    ): void
    {
        // Проверить что payload содержит необходимые данные
        if (empty($payload) || !isset($payload['main_account_id'])) {
            Log::warning('Batch bundle task skipped: missing main_account_id in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'payload_keys' => array_keys($payload)
            ]);
            throw new \Exception('Invalid payload: missing main_account_id');
        }

        if (!isset($payload['bundles']) || empty($payload['bundles'])) {
            Log::warning('Batch bundle task skipped: missing bundles in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'payload_keys' => array_keys($payload)
            ]);
            throw new \Exception('Invalid payload: missing bundles array');
        }

        $mainAccountId = $payload['main_account_id'];
        $childAccountId = $task->account_id;
        $bundles = $payload['bundles'];

        try {
            // Получить accounts и settings из cache (N+1 optimization)
            $mainAccount = $accountsCache[$mainAccountId] ?? null;
            $childAccount = $accountsCache[$childAccountId] ?? null;
            $syncSettings = $settingsCache[$childAccountId] ?? null;

            if (!$mainAccount || !$childAccount) {
                throw new \Exception("Account not found in cache: main={$mainAccountId}, child={$childAccountId}");
            }

            if (!$syncSettings) {
                throw new \Exception("Sync settings not found for child account {$childAccountId}");
            }

            // Проверить rate limit ПЕРЕД началом batch операции
            $rateLimitTracker = app(\App\Services\RateLimitTracker::class);
            $estimatedCost = $rateLimitTracker->estimateBatchCost('bundle', count($bundles), false);

            $check = $rateLimitTracker->checkAvailability($mainAccountId, $estimatedCost);

            if (!$check['available']) {
                Log::warning('Batch bundle sync postponed: rate limit exhausted', [
                    'task_id' => $task->id,
                    'main_account_id' => substr($mainAccountId, 0, 8) . '...',
                    'estimated_cost' => $estimatedCost,
                    'remaining' => $check['remaining'],
                    'retry_after' => $check['retry_after']
                ]);

                // Отложить задачу и все задачи для этого main account
                $this->postponeAllMainAccountTasks($mainAccountId, $check['retry_after'], $task->id);

                // Throw RateLimitException чтобы handle() мог обработать
                throw new \App\Exceptions\RateLimitException(
                    "Rate limit exhausted for main account",
                    $check['retry_after'] * 1000,
                    [
                        'remaining' => $check['remaining'],
                        'retry_after' => $check['retry_after'] * 1000
                    ]
                );
            }

            Log::info('Batch bundle sync started', [
                'task_id' => $task->id,
                'bundles_count' => count($bundles),
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'estimated_cost' => $estimatedCost,
                'rate_limit_remaining' => $check['remaining']
            ]);

            // Фильтровать комплекты ПЕРЕД синхронизацией групп
            $filteredBundles = [];
            foreach ($bundles as $bundle) {
                // Используем SyncHelpers::passesFilters для проверки
                if ($bundleSyncService->passesFilters($bundle, $syncSettings, 'bundle')) {
                    $filteredBundles[] = $bundle;
                }
            }

            if (empty($filteredBundles)) {
                Log::info('All bundles filtered out in batch', [
                    'task_id' => $task->id,
                    'total_bundles' => count($bundles),
                    'filtered_out' => count($bundles)
                ]);
                return;
            }

            Log::info('Bundles filtered for batch sync', [
                'task_id' => $task->id,
                'total_bundles' => count($bundles),
                'filtered_bundles' => count($filteredBundles),
                'filtered_out' => count($bundles) - count($filteredBundles)
            ]);

            // Pre-sync групп товаров (если настройка включена)
            if ($syncSettings->create_product_folders) {
                $productFolderSyncService = app(\App\Services\ProductFolderSyncService::class);
                $productFolderSyncService->syncFoldersForEntities(
                    $mainAccountId,
                    $childAccountId,
                    $filteredBundles
                );
            }

            // Подготовить комплекты для batch POST (используя только кеш)
            $preparedBundles = [];
            $skippedCount = 0;

            foreach ($filteredBundles as $bundle) {
                $prepared = $bundleSyncService->prepareBundleForBatch(
                    $bundle,
                    $mainAccountId,
                    $childAccountId,
                    $syncSettings
                );

                if ($prepared) {
                    $preparedBundles[] = $prepared;
                } else {
                    $skippedCount++; // Skipped during preparation or missing components
                }
            }

            if (empty($preparedBundles)) {
                Log::info('All bundles skipped during preparation', [
                    'task_id' => $task->id,
                    'filtered_bundles' => count($filteredBundles),
                    'skipped' => $skippedCount
                ]);
                return;
            }

            Log::info('Bundles prepared for batch POST', [
                'task_id' => $task->id,
                'prepared_count' => count($preparedBundles),
                'skipped_count' => $skippedCount
            ]);

            // Получить child account для access token
            $childAccount = \App\Models\Account::where('account_id', $childAccountId)->firstOrFail();
            $moysklad = app(\App\Services\MoySkladService::class);

            // Отправить batch POST
            $response = $moysklad
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $mainAccountId,
                    entityType: 'batch_bundles',
                    entityId: null  // Batch - нет конкретного ID
                )
                ->setOperationContext(
                    operationType: 'batch_create',
                    operationResult: 'success'
                )
                ->batchCreateBundles($preparedBundles);

            $createdBundles = $response['data'] ?? [];

            Log::info('Batch POST completed', [
                'task_id' => $task->id,
                'sent_count' => count($preparedBundles),
                'received_count' => count($createdBundles)
            ]);

            // Разделить успешные результаты от ошибок
            $successfulResults = [];
            $errorResults = [];

            foreach ($createdBundles as $index => $result) {
                // МойСклад возвращает mixed array: успехи {"id": "xxx"} и ошибки {"errors": [...]}
                if (isset($result['errors']) && is_array($result['errors'])) {
                    $errorResults[$index] = $result;
                } else {
                    $successfulResults[$index] = $result;
                }
            }

            Log::info('Batch POST results separated', [
                'task_id' => $task->id,
                'successful_count' => count($successfulResults),
                'error_count' => count($errorResults)
            ]);

            // Обработать ошибки (код 1021 - сущность не найдена)
            $deletedMappingsCount = 0;
            $retryTasksCount = 0;

            foreach ($errorResults as $index => $errorResult) {
                $originalId = $preparedBundles[$index]['_original_id'] ?? null;

                if (!$originalId) {
                    Log::warning('Error result without _original_id', [
                        'task_id' => $task->id,
                        'index' => $index,
                        'errors' => $errorResult['errors']
                    ]);
                    continue;
                }

                // Извлечь код ошибки (проверяем первую ошибку в массиве)
                $firstError = $errorResult['errors'][0] ?? null;
                $errorCode = $firstError['code'] ?? null;
                $errorMessage = $firstError['error'] ?? 'Unknown error';

                Log::error('Batch POST error for bundle', [
                    'task_id' => $task->id,
                    'index' => $index,
                    'original_id' => $originalId,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage
                ]);

                // Код 1021 = "Объект не найден" - означает комплект удален в child account
                if ($errorCode === 1021) {
                    // Удалить stale mapping
                    $deleted = \App\Models\EntityMapping::where([
                        'parent_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'entity_type' => 'bundle',
                        'parent_entity_id' => $originalId
                    ])->delete();

                    if ($deleted > 0) {
                        $deletedMappingsCount++;
                        Log::info('Deleted stale bundle mapping (entity not found in child)', [
                            'task_id' => $task->id,
                            'original_id' => $originalId,
                            'error_code' => $errorCode
                        ]);
                    }

                    // Создать retry задачу с операцией CREATE (не UPDATE)
                    SyncQueue::create([
                        'account_id' => $childAccountId,
                        'entity_type' => 'bundle',
                        'entity_id' => $originalId,
                        'operation' => 'create',  // ВАЖНО: CREATE, не update
                        'priority' => 5,
                        'scheduled_at' => now()->addMinutes(5),
                        'status' => 'pending',
                        'attempts' => 0,
                        'payload' => [
                            'main_account_id' => $mainAccountId,
                            'batch_retry' => true,
                            'retry_reason' => 'entity_deleted_in_child'
                        ]
                    ]);

                    $retryTasksCount++;

                    Log::info('Created retry task as CREATE for deleted bundle', [
                        'original_task_id' => $task->id,
                        'bundle_id' => $originalId
                    ]);
                } else {
                    // Другие ошибки - создать retry как UPDATE
                    SyncQueue::create([
                        'account_id' => $childAccountId,
                        'entity_type' => 'bundle',
                        'entity_id' => $originalId,
                        'operation' => 'update',
                        'priority' => 5,
                        'scheduled_at' => now()->addMinutes(5),
                        'status' => 'pending',
                        'attempts' => 0,
                        'payload' => [
                            'main_account_id' => $mainAccountId,
                            'batch_retry' => true,
                            'error_code' => $errorCode
                        ]
                    ]);

                    $retryTasksCount++;

                    Log::info('Created retry task for batch error', [
                        'original_task_id' => $task->id,
                        'bundle_id' => $originalId,
                        'error_code' => $errorCode
                    ]);
                }
            }

            // Создать mappings ТОЛЬКО для успешных результатов
            $mappingCount = 0;
            $failedCount = 0;

            foreach ($successfulResults as $index => $createdBundle) {
                try {
                    // Получить оригинальный ID из метаданных
                    $originalId = $preparedBundles[$index]['_original_id'] ?? null;
                    $isUpdate = $preparedBundles[$index]['_is_update'] ?? false;

                    if (!$originalId) {
                        Log::warning('Cannot create mapping: missing _original_id', [
                            'task_id' => $task->id,
                            'index' => $index,
                            'child_bundle_id' => $createdBundle['id'] ?? 'unknown'
                        ]);
                        $failedCount++;
                        continue;
                    }

                    // Создать или обновить mapping
                    \App\Models\EntityMapping::updateOrCreate(
                        [
                            'parent_account_id' => $mainAccountId,
                            'child_account_id' => $childAccountId,
                            'entity_type' => 'bundle',
                            'parent_entity_id' => $originalId
                        ],
                        [
                            'child_entity_id' => $createdBundle['id'],
                            'sync_direction' => 'main_to_child',
                            'match_field' => $syncSettings->product_match_field ?? 'code',
                            'match_value' => $createdBundle['code'] ?? $createdBundle['name']
                        ]
                    );

                    $mappingCount++;

                    // Синхронизировать изображения (если включено)
                    if ($syncSettings->sync_images || $syncSettings->sync_images_all) {
                        $originalImages = $preparedBundles[$index]['_original_images'] ?? [];

                        if (!empty($originalImages)) {
                            $this->queueImageSyncForEntity(
                                $mainAccountId,
                                $childAccountId,
                                'bundle',
                                $originalId,
                                $createdBundle['id'],
                                $originalImages,
                                $syncSettings
                            );
                        }
                    }

                } catch (\Exception $e) {
                    $failedCount++;

                    // Получить оригинальный ID
                    $originalId = $preparedBundles[$index]['_original_id'] ?? null;

                    Log::error('Failed to create mapping in batch', [
                        'task_id' => $task->id,
                        'index' => $index,
                        'original_id' => $originalId,
                        'error' => $e->getMessage()
                    ]);

                    // Создать индивидуальную retry задачу для упавшего комплекта
                    if ($originalId) {
                        SyncQueue::create([
                            'account_id' => $childAccountId,
                            'entity_type' => 'bundle',
                            'entity_id' => $originalId,
                            'operation' => 'update',
                            'priority' => 5,
                            'scheduled_at' => now()->addMinutes(5),
                            'status' => 'pending',
                            'attempts' => 0,
                            'payload' => [
                                'main_account_id' => $mainAccountId,
                                'batch_retry' => true,
                                'retry_reason' => 'mapping_creation_failed'
                            ]
                        ]);

                        Log::info('Created individual retry task for failed bundle', [
                            'original_task_id' => $task->id,
                            'bundle_id' => $originalId
                        ]);
                    }
                }
            }

            Log::info('Batch bundle sync completed', [
                'task_id' => $task->id,
                'total_bundles' => count($bundles),
                'prepared_count' => count($preparedBundles),
                'successful_results' => count($successfulResults),
                'error_results' => count($errorResults),
                'mappings_created' => $mappingCount,
                'failed_mappings' => $failedCount,
                'deleted_stale_mappings' => $deletedMappingsCount,
                'retry_tasks_created' => $retryTasksCount
            ]);

            // Если более 50% упали - выбросить исключение для retry всей задачи
            if ($failedCount > count($preparedBundles) / 2) {
                throw new \Exception("Batch sync failed: {$failedCount} of " . count($preparedBundles) . " bundles failed");
            }

        } catch (\Exception $e) {
            Log::error('Batch bundle sync failed completely', [
                'task_id' => $task->id,
                'bundles_count' => count($bundles),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Обработать синхронизацию комплекта
     */
    protected function processBundleSync(SyncQueue $task, array $payload, BundleSyncService $bundleSyncService): void
    {
        // Проверить что payload содержит необходимые данные
        if (empty($payload) || !isset($payload['main_account_id'])) {
            Log::warning('Task skipped: missing main_account_id in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'entity_id' => $task->entity_id,
                'payload' => $payload
            ]);
            throw new \Exception('Invalid payload: missing main_account_id');
        }

        Log::info('Processing bundle sync task', [
            'task_id' => $task->id,
            'bundle_id' => $task->entity_id,
            'main_account_id' => $payload['main_account_id'],
            'child_account_id' => $task->account_id,
            'operation' => $task->operation
        ]);

        if ($task->operation === 'delete') {
            // При удалении или архивации комплекта в главном - архивируем во всех дочерних
            $archivedCount = $bundleSyncService->archiveBundle(
                $payload['main_account_id'],
                $task->entity_id
            );

            Log::info('Bundle archived in child accounts', [
                'task_id' => $task->id,
                'bundle_id' => $task->entity_id,
                'archived_count' => $archivedCount
            ]);
            return;
        }

        $result = $bundleSyncService->syncBundle(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );

        if ($result) {
            Log::info('Bundle sync completed successfully', [
                'task_id' => $task->id,
                'bundle_id' => $task->entity_id,
                'child_bundle_id' => $result['id'] ?? 'unknown'
            ]);
        } else {
            Log::warning('Bundle sync returned null (possibly filtered out or disabled)', [
                'task_id' => $task->id,
                'bundle_id' => $task->entity_id
            ]);
        }
    }

    /**
     * Обработать синхронизацию услуги
     */
    protected function processServiceSync(SyncQueue $task, array $payload, ServiceSyncService $serviceSyncService): void
    {
        // Проверить что payload содержит необходимые данные
        if (empty($payload) || !isset($payload['main_account_id'])) {
            Log::warning('Task skipped: missing main_account_id in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'entity_id' => $task->entity_id,
                'payload' => $payload
            ]);
            throw new \Exception('Invalid payload: missing main_account_id');
        }

        if ($task->operation === 'delete') {
            // При удалении или архивации услуги в главном - архивируем во всех дочерних
            $archivedCount = $serviceSyncService->archiveService(
                $payload['main_account_id'],
                $task->entity_id
            );

            Log::info('Service archived in child accounts', [
                'task_id' => $task->id,
                'service_id' => $task->entity_id,
                'archived_count' => $archivedCount
            ]);
            return;
        }

        $serviceSyncService->syncService(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );
    }

    /**
     * Обработать синхронизацию заказа покупателя
     */
    protected function processCustomerOrderSync(SyncQueue $task, array $payload, CustomerOrderSyncService $customerOrderSyncService): void
    {
        $customerOrderSyncService->syncCustomerOrder(
            $task->account_id,
            $task->entity_id
        );
    }

    /**
     * Обработать синхронизацию розничной продажи
     */
    protected function processRetailDemandSync(SyncQueue $task, array $payload, RetailDemandSyncService $retailDemandSyncService): void
    {
        $retailDemandSyncService->syncRetailDemand(
            $task->account_id,
            $task->entity_id
        );
    }

    /**
     * Обработать синхронизацию заказа поставщику
     */
    protected function processPurchaseOrderSync(SyncQueue $task, array $payload, PurchaseOrderSyncService $purchaseOrderSyncService): void
    {
        $purchaseOrderSyncService->syncPurchaseOrder(
            $task->account_id,
            $task->entity_id
        );
    }

    /**
     * Обработать проверку вебхука
     */
    protected function processWebhookCheck(SyncQueue $task, WebhookService $webhookService): void
    {
        try {
            $webhookService->checkWebhookHealth($task->account_id);

            Log::info('Webhook health check completed', [
                'task_id' => $task->id,
                'account_id' => $task->account_id
            ]);

        } catch (\Exception $e) {
            Log::error('Webhook health check failed', [
                'task_id' => $task->id,
                'account_id' => $task->account_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Обработать синхронизацию изображения
     */
    protected function processImageSync(SyncQueue $task, array $payload, ImageSyncService $imageSyncService): void
    {
        // Проверить что payload содержит необходимые данные
        if (empty($payload) || !isset($payload['main_account_id'])) {
            Log::warning('Image sync task skipped: missing main_account_id in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'entity_id' => $task->entity_id,
                'payload' => $payload
            ]);
            throw new \Exception('Invalid payload: missing main_account_id');
        }

        // Проверить новый формат payload (с массивом images)
        if (isset($payload['images']) && is_array($payload['images'])) {
            // Новый batch формат
            $requiredFields = ['child_account_id', 'entity_type', 'parent_entity_id', 'child_entity_id', 'images'];
            foreach ($requiredFields as $field) {
                if (!isset($payload[$field])) {
                    Log::error('Image sync task skipped: missing required field', [
                        'task_id' => $task->id,
                        'missing_field' => $field,
                        'payload' => $payload
                    ]);
                    throw new \Exception("Invalid payload: missing {$field}");
                }
            }

            Log::info('Starting batch image sync', [
                'task_id' => $task->id,
                'entity_type' => $payload['entity_type'],
                'parent_entity_id' => $payload['parent_entity_id'],
                'child_entity_id' => $payload['child_entity_id'],
                'images_count' => count($payload['images'])
            ]);

            // Вызвать новый batch метод
            $result = $imageSyncService->syncImagesForEntity(
                $payload['main_account_id'],
                $payload['child_account_id'],
                $payload['entity_type'],
                $payload['parent_entity_id'],
                $payload['child_entity_id'],
                $payload['images']
            );

            if (!$result) {
                throw new \Exception('Batch image sync failed - no images were successfully synced');
            }

            Log::info('Batch image sync completed successfully', [
                'task_id' => $task->id,
                'entity_type' => $payload['entity_type'],
                'parent_entity_id' => $payload['parent_entity_id'],
                'images_count' => count($payload['images'])
            ]);

        } else {
            // Старый формат (одно изображение за раз) - для обратной совместимости
            $requiredFields = ['child_account_id', 'parent_entity_type', 'parent_entity_id', 'child_entity_id', 'image_url', 'filename'];
            foreach ($requiredFields as $field) {
                if (!isset($payload[$field])) {
                    Log::error('Image sync task skipped: missing required field', [
                        'task_id' => $task->id,
                        'missing_field' => $field,
                        'payload' => $payload
                    ]);
                    throw new \Exception("Invalid payload: missing {$field}");
                }
            }

            Log::info('Starting single image sync (legacy)', [
                'task_id' => $task->id,
                'parent_entity_type' => $payload['parent_entity_type'],
                'parent_entity_id' => $payload['parent_entity_id'],
                'child_entity_id' => $payload['child_entity_id'],
                'filename' => $payload['filename']
            ]);

            // Вызвать старый метод
            $result = $imageSyncService->syncImages(
                $payload['main_account_id'],
                $payload['child_account_id'],
                $payload['parent_entity_type'],
                $payload['parent_entity_id'],
                [$payload] // Pass single image as array
            );

            if (!$result) {
                throw new \Exception('Image sync failed - no images were successfully synced');
            }

            Log::info('Single image sync completed successfully', [
                'task_id' => $task->id,
                'parent_entity_type' => $payload['parent_entity_type'],
                'parent_entity_id' => $payload['parent_entity_id'],
                'filename' => $payload['filename']
            ]);
        }
    }

    /**
     * Поставить в очередь синхронизацию изображений для сущности (batch mode)
     *
     * Создает одну задачу image_sync с массивом всех изображений сущности.
     * Вызывается из processBatchProductSync() и processBatchBundleSync().
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $entityType Тип сущности (product, bundle, variant)
     * @param string $parentEntityId UUID сущности в главном аккаунте
     * @param string $childEntityId UUID сущности в дочернем аккаунте
     * @param array $images Массив изображений из МойСклад API (images.rows)
     * @param \App\Models\SyncSetting $settings Настройки синхронизации
     * @return bool Успех создания задачи
     */
    protected function queueImageSyncForEntity(
        string $mainAccountId,
        string $childAccountId,
        string $entityType,
        string $parentEntityId,
        string $childEntityId,
        array $images,
        \App\Models\SyncSetting $settings
    ): bool {
        try {
            if (empty($images)) {
                return false; // Nothing to queue
            }

            // Получить лимит изображений из настроек
            $imageSyncService = app(\App\Services\ImageSyncService::class);
            $limit = $imageSyncService->getImageLimit($settings);

            if ($limit === 0) {
                Log::debug('Image sync is disabled', [
                    'entity_type' => $entityType,
                    'parent_entity_id' => $parentEntityId
                ]);
                return false;
            }

            // Ограничить количество изображений
            $imagesToSync = array_slice($images, 0, $limit);

            Log::info('Queueing batch image sync task', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'parent_entity_id' => $parentEntityId,
                'child_entity_id' => $childEntityId,
                'images_count' => count($imagesToSync),
                'total_images' => count($images),
                'limit' => $limit
            ]);

            // Создать одну задачу с массивом всех изображений
            SyncQueue::create([
                'account_id' => $childAccountId,
                'entity_type' => 'image_sync',
                'entity_id' => $parentEntityId, // ID родительской сущности
                'operation' => 'sync',
                'priority' => 50, // Medium priority (changed from 80 to 50)
                'status' => 'pending',
                'attempts' => 0,
                'scheduled_at' => now(),
                'payload' => [
                    'main_account_id' => $mainAccountId,
                    'child_account_id' => $childAccountId,
                    'entity_type' => $entityType,
                    'parent_entity_id' => $parentEntityId,
                    'child_entity_id' => $childEntityId,
                    'images' => $imagesToSync, // Массив всех изображений
                ],
                'max_attempts' => 3,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to queue image sync task', [
                'entity_type' => $entityType,
                'parent_entity_id' => $parentEntityId,
                'child_entity_id' => $childEntityId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Получить parent_account_id для дочернего аккаунта
     */
    protected function getParentAccountId(string $childAccountId): ?string
    {
        $link = \DB::table('child_accounts')
            ->where('child_account_id', $childAccountId)
            ->first();

        return $link?->parent_account_id;
    }

    /**
     * Обработать полное падение batch задачи
     *
     * Когда batch POST запрос падает целиком (404, 500, etc.),
     * создаём индивидуальные retry задачи для ВСЕХ сущностей из payload.
     * Это позволяет не потерять сущности и попытаться синхронизировать их по одной.
     */
    protected function handleBatchTaskFailure(
        SyncQueue $task,
        \Throwable $e,
        SyncStatisticsService $statisticsService
    ): void {
        $payload = $task->payload ?? [];

        Log::error('Batch task failed completely, creating individual retry tasks', [
            'task_id' => $task->id,
            'entity_type' => $task->entity_type,
            'attempts' => $task->attempts,
            'error' => $e->getMessage()
        ]);

        // Извлечь сущности из payload
        $entities = [];
        $entityTypeSingular = '';

        if ($task->entity_type === 'batch_services') {
            $entities = $payload['services'] ?? [];
            $entityTypeSingular = 'service';
        } elseif ($task->entity_type === 'batch_products') {
            $entities = $payload['products'] ?? [];
            $entityTypeSingular = 'product';
        } elseif ($task->entity_type === 'batch_bundles') {
            $entities = $payload['bundles'] ?? [];
            $entityTypeSingular = 'bundle';
        }

        if (empty($entities)) {
            Log::warning('Batch task has no entities in payload', [
                'task_id' => $task->id,
                'payload_keys' => array_keys($payload)
            ]);

            // Пометить как failed если нет данных
            $task->update([
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);
            return;
        }

        $mainAccountId = $payload['main_account_id'] ?? null;
        if (!$mainAccountId) {
            Log::warning('Batch task missing main_account_id', [
                'task_id' => $task->id
            ]);

            $task->update([
                'status' => 'failed',
                'error' => 'Missing main_account_id in payload'
            ]);
            return;
        }

        // Создать индивидуальные retry задачи для ВСЕХ сущностей
        $createdRetryTasks = 0;
        $deletedMappingsCount = 0;

        foreach ($entities as $entity) {
            $entityId = $entity['id'] ?? null;

            if (!$entityId) {
                Log::warning('Entity missing id, skipping', [
                    'task_id' => $task->id,
                    'entity_name' => $entity['name'] ?? 'unknown'
                ]);
                continue;
            }

            // Удалить stale mapping (если существует)
            $deleted = \App\Models\EntityMapping::where([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $task->account_id,
                'entity_type' => $entityTypeSingular,
                'parent_entity_id' => $entityId
            ])->delete();

            if ($deleted > 0) {
                $deletedMappingsCount++;
            }

            // Создать retry задачу с операцией CREATE (не UPDATE)
            SyncQueue::create([
                'account_id' => $task->account_id,
                'entity_type' => $entityTypeSingular,  // 'service' или 'product'
                'entity_id' => $entityId,
                'operation' => 'create',  // ⭐ CREATE (не update) - маппинг удален, создаем заново
                'priority' => 5,
                'scheduled_at' => now()->addMinute(),  // 1 минута
                'status' => 'pending',
                'attempts' => 0,
                'payload' => [
                    'main_account_id' => $mainAccountId,
                    'batch_retry' => true,
                    'original_batch_task_id' => $task->id,
                    'batch_failure_reason' => substr($e->getMessage(), 0, 200)
                ]
            ]);

            $createdRetryTasks++;
        }

        Log::info('Created individual retry tasks for failed batch', [
            'original_task_id' => $task->id,
            'entity_type' => $task->entity_type,
            'entities_count' => count($entities),
            'deleted_stale_mappings' => $deletedMappingsCount,
            'retry_tasks_created' => $createdRetryTasks
        ]);

        // Пометить batch задачу как failed (уже создали retry задачи)
        $task->update([
            'status' => 'failed',
            'error' => $e->getMessage()
        ]);

        // Записать статистику о неудаче (batch упал, но retry задачи созданы)
        if (isset($payload['main_account_id'])) {
            $statisticsService->recordSync(
                $payload['main_account_id'],
                $task->account_id,
                'product',
                false,
                0
            );
        }
    }

    /**
     * Проверить, стоит ли повторять задачу при данной ошибке
     *
     * Retry имеет смысл ТОЛЬКО для:
     * - 5xx Server Errors (500, 502, 503, 504) - временные проблемы МойСклад
     * - Network errors (timeout, connection refused)
     *
     * Все 4xx ошибки (404, 400, 403, etc.) - постоянные, retry бессмыслен
     */
    protected function isRetryableError(string $errorMessage): bool
    {
        // Извлечь HTTP статус из сообщения (формат: "[HTTP 404 Not Found] ...")
        if (preg_match('/\[HTTP (\d{3})/i', $errorMessage, $matches)) {
            $httpStatus = (int)$matches[1];

            // Retry только для 5xx серверных ошибок
            if ($httpStatus >= 500 && $httpStatus < 600) {
                return true;
            }

            // Все 4xx (400, 404, 403, etc.) - не retry
            if ($httpStatus >= 400 && $httpStatus < 500) {
                return false;
            }
        }

        // Network errors - retry
        $networkErrors = [
            'timeout',
            'connection refused',
            'connection timed out',
            'could not resolve host',
            'failed to connect',
            'network is unreachable',
            'dns',
        ];

        $lowerMessage = strtolower($errorMessage);
        foreach ($networkErrors as $pattern) {
            if (str_contains($lowerMessage, $pattern)) {
                return true;
            }
        }

        // По умолчанию - не retry (безопаснее)
        return false;
    }

    /**
     * Сбалансировать задачи по main accounts для равномерного использования rate limits
     *
     * Вместо обработки 50 задач подряд для Main A,
     * чередуем задачи разных main accounts: Main A → Main B → Main C → Main A → ...
     *
     * Результат: все main accounts обрабатываются параллельно в одном цикле
     *
     * @param Collection $tasks Исходные задачи
     * @param int $limit Максимальное количество задач
     * @return Collection Сбалансированные задачи
     */
    protected function balanceTasksByMainAccount(\Illuminate\Support\Collection $tasks, int $limit): \Illuminate\Support\Collection
    {
        // Группировать задачи по main_account_id
        $grouped = $tasks->groupBy(function($task) {
            return $task->payload['main_account_id'] ?? 'unknown';
        });

        $totalMainAccounts = $grouped->count();

        Log::info('Balancing tasks across main accounts', [
            'total_tasks' => $tasks->count(),
            'main_accounts_count' => $totalMainAccounts,
            'tasks_per_account' => $grouped->map->count()->toArray()
        ]);

        // Если только один main account → балансировка не нужна
        if ($totalMainAccounts <= 1) {
            return $tasks->take($limit);
        }

        // Интерливинг: брать по одной задаче из каждой группы циклически
        $balanced = collect();
        $maxIterations = (int)ceil($limit / $totalMainAccounts) + 1;

        for ($i = 0; $i < $maxIterations && $balanced->count() < $limit; $i++) {
            foreach ($grouped as $mainAccountId => $accountTasks) {
                // Взять i-ю задачу из этой группы (если есть)
                if (isset($accountTasks[$i])) {
                    $balanced->push($accountTasks[$i]);

                    if ($balanced->count() >= $limit) {
                        break 2; // Достигли лимита
                    }
                }
            }
        }

        Log::debug('Tasks balanced', [
            'balanced_count' => $balanced->count(),
            'distribution' => $balanced->groupBy(function($task) {
                return $task->payload['main_account_id'] ?? 'unknown';
            })->map->count()->toArray()
        ]);

        return $balanced;
    }

    /**
     * Отложить ВСЕ pending задачи для main account из-за rate limit exhaustion
     *
     * Используется когда main account исчерпал свой rate limit,
     * чтобы избежать бесполезных попыток обработки других задач для этого account.
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param int $retryAfter Время задержки в секундах
     * @param int|null $excludeTaskId ID задачи которую НЕ нужно откладывать (уже обработана)
     */
    protected function postponeAllMainAccountTasks(string $mainAccountId, int $retryAfter, ?int $excludeTaskId = null): void
    {
        $query = SyncQueue::where('status', 'pending')
            ->whereRaw("payload->>'main_account_id' = ?", [$mainAccountId]);

        if ($excludeTaskId) {
            $query->where('id', '!=', $excludeTaskId);
        }

        $postponed = $query->update([
            'scheduled_at' => now()->addSeconds($retryAfter),
            'error' => 'Main account rate limit - batch postponed'
        ]);

        Log::warning('Postponed all tasks for main account due to rate limit', [
            'main_account_id' => substr($mainAccountId, 0, 8) . '...',
            'postponed_count' => $postponed,
            'retry_after_seconds' => $retryAfter
        ]);
    }
}
