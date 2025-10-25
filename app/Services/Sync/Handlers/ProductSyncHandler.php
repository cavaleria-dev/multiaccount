<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\ProductSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для синхронизации товаров (products)
 *
 * Обрабатывает entity_type: 'product', 'batch_products'
 */
class ProductSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected ProductSyncService $productSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'product';
    }

    protected function handleDelete(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        // При удалении или архивации товара в главном - архивируем во всех дочерних
        $archivedCount = $this->productSyncService->archiveProduct(
            $payload['main_account_id'],
            $task->entity_id
        );

        $this->logSuccess($task, [
            'operation' => 'archive',
            'product_id' => $task->entity_id,
            'archived_count' => $archivedCount
        ]);
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $this->productSyncService->syncProduct(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );

        $this->logSuccess($task, [
            'main_account_id' => $payload['main_account_id'],
            'child_account_id' => $task->account_id,
            'product_id' => $task->entity_id
        ]);
    }
}
