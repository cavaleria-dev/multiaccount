<?php

namespace App\Jobs;

use App\Models\SyncQueue;
use App\Services\ProductSyncService;
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
        CustomerOrderSyncService $customerOrderSyncService,
        RetailDemandSyncService $retailDemandSyncService,
        PurchaseOrderSyncService $purchaseOrderSyncService,
        SyncStatisticsService $statisticsService,
        WebhookService $webhookService
    ): void {
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
            Log::debug('No pending tasks in sync queue');
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
                if (in_array($task->entity_type, ['product', 'variant', 'bundle'])) {
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

            } catch (\Exception $e) {
                $task->increment('attempts');

                if ($task->attempts >= $task->max_attempts) {
                    $task->update([
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ]);

                    // Записать статистику о неудаче
                    if (in_array($task->entity_type, ['product', 'variant', 'bundle'])) {
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
                        'error' => $e->getMessage()
                    ]);

                } else {
                    $task->update([
                        'status' => 'pending',
                        'error' => $e->getMessage(),
                        'scheduled_at' => now()->addMinutes(5 * $task->attempts), // Exponential backoff
                    ]);

                    Log::warning('Task failed, will retry', [
                        'task_id' => $task->id,
                        'attempts' => $task->attempts,
                        'max_attempts' => $task->max_attempts,
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
        CustomerOrderSyncService $customerOrderSyncService,
        RetailDemandSyncService $retailDemandSyncService,
        PurchaseOrderSyncService $purchaseOrderSyncService,
        WebhookService $webhookService
    ): void {
        $payload = $task->payload;

        match($task->entity_type) {
            'product' => $this->processProductSync($task, $payload, $productSyncService),
            'variant' => $this->processVariantSync($task, $payload, $productSyncService),
            'bundle' => $this->processBundleSync($task, $payload, $productSyncService),
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
     * Обработать синхронизацию комплекта
     */
    protected function processBundleSync(SyncQueue $task, array $payload, ProductSyncService $productSyncService): void
    {
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
}
