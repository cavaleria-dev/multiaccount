<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\ProductSyncService;
use App\Services\Sync\Handlers\Traits\HandlesPartialUpdates;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для синхронизации товаров (products)
 *
 * Обрабатывает entity_type: 'product', 'batch_products'
 * Supports intelligent partial updates via HandlesPartialUpdates trait
 */
class ProductSyncHandler extends SyncTaskHandler
{
    use HandlesPartialUpdates;

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
        // Try intelligent partial update first (if updated_fields present)
        if ($this->shouldAttemptPartialUpdate($task)) {
            Log::debug('Attempting partial product update', [
                'task_id' => $task->id,
                'product_id' => $task->entity_id,
                'updated_fields' => $payload['updated_fields'] ?? [],
            ]);

            if ($this->handlePartialUpdate($task, 'product')) {
                // Partial update successful, task already marked as completed
                return;
            }

            // Fall through to full sync if partial update failed
            Log::info('Partial update failed, falling back to full sync', [
                'task_id' => $task->id,
                'product_id' => $task->entity_id,
            ]);
        }

        // Full sync (original logic)
        $this->productSyncService->syncProduct(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );

        $this->logSuccess($task, [
            'main_account_id' => $payload['main_account_id'],
            'child_account_id' => $task->account_id,
            'product_id' => $task->entity_id,
            'sync_type' => 'full'
        ]);
    }
}
