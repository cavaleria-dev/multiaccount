<?php

namespace App\Jobs;

use App\Models\SyncQueue;
use App\Services\ProductSyncService;
use App\Services\ServiceSyncService;
use App\Services\CustomerOrderSyncService;
use App\Services\RetailDemandSyncService;
use App\Services\PurchaseOrderSyncService;
use App\Services\SyncStatisticsService;
use App\Services\WebhookService;
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
        CustomerOrderSyncService $customerOrderSyncService,
        RetailDemandSyncService $retailDemandSyncService,
        PurchaseOrderSyncService $purchaseOrderSyncService,
        SyncStatisticsService $statisticsService,
        WebhookService $webhookService
    ): void {
        // Детальное логирование для диагностики
        $totalPending = \DB::table('sync_queue')->where('status', 'pending')->count();
        $currentTime = now()->toDateTimeString();

        Log::info('ProcessSyncQueueJob START', [
            'current_time' => $currentTime,
            'total_pending_in_db' => $totalPending
        ]);

        // Обработать задачи из очереди (порциями по 50)
        $tasks = SyncQueue::where('status', 'pending')
            ->where(function($query) {
                $query->whereNull('scheduled_at')
                      ->orWhere('scheduled_at', '<=', now());
            })
            ->orderBy('priority', 'desc')
            ->orderBy('scheduled_at', 'asc')
            ->limit(50)
            ->get();

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

        foreach ($tasks as $task) {
            try {
                $task->update([
                    'status' => 'processing',
                    'started_at' => now(),
                ]);

                $startTime = microtime(true);

                // Обработать задачу
                $this->processTask(
                    $task,
                    $productSyncService,
                    $serviceSyncService,
                    $customerOrderSyncService,
                    $retailDemandSyncService,
                    $purchaseOrderSyncService,
                    $webhookService
                );

                $duration = (int)((microtime(true) - $startTime) * 1000); // ms

                // Отметить как выполненное
                $task->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                // Записать статистику
                if (in_array($task->entity_type, ['product', 'variant', 'product_variants', 'batch_products', 'bundle', 'service', 'batch_services'])) {
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

                $task->update([
                    'status' => 'pending',
                    'error' => 'Rate limit exceeded',
                    'rate_limit_info' => $e->getRateLimitInfo(),
                    'scheduled_at' => now()->addSeconds($retryAfterSeconds),
                ]);

                Log::warning('Task postponed due to rate limit', [
                    'task_id' => $task->id,
                    'retry_after_seconds' => $retryAfterSeconds
                ]);

                // Прервать обработку текущей порции
                break;

            } catch (\Throwable $e) {
                // Ловим и Exception, и Error (включая TypeError)
                $task->increment('attempts');

                // Специальная обработка для batch задач (batch_services, batch_products)
                // Если batch POST упал целиком → создать индивидуальные retry задачи для всех сущностей
                if (in_array($task->entity_type, ['batch_services', 'batch_products'])) {
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
                    if (in_array($task->entity_type, ['product', 'variant', 'product_variants', 'batch_products', 'bundle', 'service', 'batch_services'])) {
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
        CustomerOrderSyncService $customerOrderSyncService,
        RetailDemandSyncService $retailDemandSyncService,
        PurchaseOrderSyncService $purchaseOrderSyncService,
        WebhookService $webhookService
    ): void {
        // Гарантировать что payload это массив, а не null
        $payload = $task->payload ?? [];

        match($task->entity_type) {
            'product' => $this->processProductSync($task, $payload, $productSyncService),
            'variant' => $this->processVariantSync($task, $payload, $productSyncService),
            'product_variants' => $this->processBatchVariantSync($task, $payload, $productSyncService),
            'batch_products' => $this->processBatchProductSync($task, $payload, $productSyncService),
            'bundle' => $this->processBundleSync($task, $payload, $productSyncService),
            'service' => $this->processServiceSync($task, $payload, $serviceSyncService),
            'batch_services' => $this->processBatchServiceSync($task, $payload, $serviceSyncService),
            'customerorder' => $this->processCustomerOrderSync($task, $payload, $customerOrderSyncService),
            'retaildemand' => $this->processRetailDemandSync($task, $payload, $retailDemandSyncService),
            'purchaseorder' => $this->processPurchaseOrderSync($task, $payload, $purchaseOrderSyncService),
            'webhook' => $this->processWebhookCheck($task, $webhookService),
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

            // Загрузить все модификации этого товара с expand
            $response = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->get('/entity/variant', [
                    'filter' => "productid={$productId}",
                    'expand' => 'product.salePrices,characteristics,packs.uom',
                    'limit' => 1000
                ]);

            $variants = $response['data']['rows'] ?? [];

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
                'variants_count' => count($variants)
            ]);

            $successCount = 0;
            $failedCount = 0;

            // Синхронизировать каждую модификацию с уже загруженными данными
            foreach ($variants as $variant) {
                try {
                    $variantSyncService->syncVariantData($mainAccountId, $childAccountId, $variant);
                    $successCount++;

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
    protected function processBatchProductSync(SyncQueue $task, array $payload, ProductSyncService $productSyncService): void
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
            // Получить настройки синхронизации
            $syncSettings = \App\Models\SyncSetting::where('account_id', $childAccountId)->first();

            if (!$syncSettings) {
                throw new \Exception("Sync settings not found for child account {$childAccountId}");
            }

            Log::info('Batch product sync started', [
                'task_id' => $task->id,
                'products_count' => count($products),
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId
            ]);

            // Подготовить товары для batch POST (используя только кеш)
            $preparedProducts = [];
            $skippedCount = 0;

            foreach ($products as $product) {
                $prepared = $productSyncService->prepareProductForBatch(
                    $product,
                    $mainAccountId,
                    $childAccountId,
                    $syncSettings
                );

                if ($prepared) {
                    $preparedProducts[] = $prepared;
                } else {
                    $skippedCount++; // Filtered out
                }
            }

            if (empty($preparedProducts)) {
                Log::info('All products filtered out in batch', [
                    'task_id' => $task->id,
                    'total_products' => count($products),
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
    protected function processBatchServiceSync(SyncQueue $task, array $payload, ServiceSyncService $serviceSyncService): void
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
            // Получить настройки синхронизации
            $syncSettings = \App\Models\SyncSetting::where('account_id', $childAccountId)->first();

            if (!$syncSettings) {
                throw new \Exception("Sync settings not found for child account {$childAccountId}");
            }

            Log::info('Batch service sync started', [
                'task_id' => $task->id,
                'services_count' => count($services),
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId
            ]);

            // Подготовить услуги для batch POST (используя только кеш)
            $preparedServices = [];

            foreach ($services as $service) {
                $prepared = $serviceSyncService->prepareServiceForBatch(
                    $service,
                    $mainAccountId,
                    $childAccountId,
                    $syncSettings
                );

                if ($prepared) {
                    $preparedServices[] = $prepared;
                }
            }

            if (empty($preparedServices)) {
                Log::info('All services skipped in batch', [
                    'task_id' => $task->id,
                    'total_services' => count($services)
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
     * Обработать синхронизацию комплекта
     */
    protected function processBundleSync(SyncQueue $task, array $payload, ProductSyncService $productSyncService): void
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
            // При удалении или архивации комплекта в главном - архивируем во всех дочерних
            $archivedCount = $productSyncService->archiveBundle(
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

        $productSyncService->syncBundle(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );
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
}
