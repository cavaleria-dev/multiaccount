<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\BatchSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для пакетной синхронизации товаров
 *
 * Обрабатывает entity_type: 'batch_products'
 */
class BatchProductSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected BatchSyncService $batchSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'batch_products';
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $mainAccountId = $payload['main_account_id'];
        $childAccountId = $task->account_id;
        $products = $payload['products'] ?? [];

        if (empty($products)) {
            throw new \Exception('Invalid payload: missing products array for batch sync');
        }

        Log::info('Batch product sync started', [
            'task_id' => $task->id,
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'products_count' => count($products)
        ]);

        // Выполнить batch синхронизацию
        $result = $this->batchSyncService->batchSyncProducts(
            $mainAccountId,
            $childAccountId,
            $products
        );

        $this->logSuccess($task, [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'products_count' => count($products),
            'success_count' => $result['success'] ?? 0,
            'failed_count' => $result['failed'] ?? 0
        ]);
    }
}
