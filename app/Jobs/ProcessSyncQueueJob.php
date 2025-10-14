<?php

namespace App\Jobs;

use App\Models\SyncQueue;
use App\Services\ProductSyncService;
use App\Services\CustomerOrderSyncService;
use App\Services\RetailDemandSyncService;
use App\Services\SyncStatisticsService;
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
        SyncStatisticsService $statisticsService
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
                    $retailDemandSyncService
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
        RetailDemandSyncService $retailDemandSyncService
    ): void {
        $payload = $task->payload;

        match($task->entity_type) {
            'product' => $this->processProductSync($task, $payload, $productSyncService),
            'variant' => $this->processVariantSync($task, $payload, $productSyncService),
            'bundle' => $this->processBundleSync($task, $payload, $productSyncService),
            'customerorder' => $this->processCustomerOrderSync($task, $payload, $customerOrderSyncService),
            'retaildemand' => $this->processRetailDemandSync($task, $payload, $retailDemandSyncService),
            'webhook' => $this->processWebhookCheck($task),
            default => Log::warning("Unknown entity type in queue: {$task->entity_type}")
        };
    }

    /**
     * Обработать синхронизацию товара
     */
    protected function processProductSync(SyncQueue $task, array $payload, ProductSyncService $productSyncService): void
    {
        if ($task->operation === 'delete') {
            // TODO: Реализовать удаление товара
            Log::info('Product delete operation', [
                'task_id' => $task->id,
                'product_id' => $task->entity_id
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
        // TODO: Реализовать синхронизацию модификаций
        Log::info('Variant sync operation', [
            'task_id' => $task->id,
            'variant_id' => $task->entity_id
        ]);
    }

    /**
     * Обработать синхронизацию комплекта
     */
    protected function processBundleSync(SyncQueue $task, array $payload, ProductSyncService $productSyncService): void
    {
        // TODO: Реализовать синхронизацию комплектов
        Log::info('Bundle sync operation', [
            'task_id' => $task->id,
            'bundle_id' => $task->entity_id
        ]);
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
     * Обработать проверку вебхука
     */
    protected function processWebhookCheck(SyncQueue $task): void
    {
        // TODO: Реализовать проверку вебхуков
        Log::info('Webhook check operation', [
            'task_id' => $task->id,
            'account_id' => $task->account_id
        ]);
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
