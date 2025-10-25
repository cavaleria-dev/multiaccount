<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\ProductSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для синхронизации модификаций (variants)
 *
 * Обрабатывает entity_type: 'variant', 'product_variants'
 */
class VariantSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected ProductSyncService $productSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'variant';
    }

    protected function handleDelete(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        // При удалении модификации в главном - архивируем в дочерних
        $archivedCount = $this->productSyncService->archiveVariant(
            $payload['main_account_id'],
            $task->entity_id
        );

        $this->logSuccess($task, [
            'operation' => 'archive',
            'variant_id' => $task->entity_id,
            'archived_count' => $archivedCount
        ]);
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $this->productSyncService->syncVariant(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );

        $this->logSuccess($task, [
            'main_account_id' => $payload['main_account_id'],
            'child_account_id' => $task->account_id,
            'variant_id' => $task->entity_id
        ]);
    }
}
