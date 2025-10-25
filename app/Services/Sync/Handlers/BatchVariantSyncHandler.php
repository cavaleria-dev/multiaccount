<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\ProductSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для пакетной синхронизации модификаций товара
 *
 * Обрабатывает entity_type: 'product_variants'
 */
class BatchVariantSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected ProductSyncService $productSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'product_variants';
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $mainAccountId = $payload['main_account_id'];
        $childAccountId = $task->account_id;
        $productId = $task->entity_id; // Для product_variants это ID товара

        Log::info('Batch variant sync started', [
            'task_id' => $task->id,
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'product_id' => $productId
        ]);

        // Синхронизировать все модификации товара
        $result = $this->productSyncService->syncProductVariants(
            $mainAccountId,
            $childAccountId,
            $productId
        );

        $this->logSuccess($task, [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'product_id' => $productId,
            'variants_synced' => $result['synced'] ?? 0,
            'variants_failed' => $result['failed'] ?? 0
        ]);
    }
}
