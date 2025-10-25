<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\BatchVariantSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для пакетной синхронизации модификаций
 *
 * Обрабатывает entity_type: 'batch_variants'
 */
class BatchVariantSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected BatchVariantSyncService $batchVariantSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'batch_variants';
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $mainAccountId = $payload['main_account_id'];
        $childAccountId = $task->account_id;

        // Новый формат: массив variants в payload
        $variants = $payload['variants'] ?? [];

        // Graceful degradation: старый формат (product_variants с productId)
        if (empty($variants) && $task->entity_id) {
            Log::warning('Old format batch variant task detected, skipping', [
                'task_id' => $task->id,
                'entity_id' => $task->entity_id,
                'entity_type' => $task->entity_type
            ]);
            return;
        }

        if (empty($variants)) {
            throw new \Exception('Invalid payload: missing variants array');
        }

        Log::info('Batch variant sync started', [
            'task_id' => $task->id,
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'variants_count' => count($variants)
        ]);

        // Выполнить batch синхронизацию
        $result = $this->batchVariantSyncService->batchSyncVariants(
            $mainAccountId,
            $childAccountId,
            $variants
        );

        $this->logSuccess($task, [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'variants_count' => count($variants),
            'success_count' => $result['success'] ?? 0,
            'failed_count' => $result['failed'] ?? 0
        ]);
    }
}
