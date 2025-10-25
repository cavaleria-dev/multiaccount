<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\BatchSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для пакетной синхронизации комплектов
 *
 * Обрабатывает entity_type: 'batch_bundles'
 */
class BatchBundleSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected BatchSyncService $batchSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'batch_bundles';
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $mainAccountId = $payload['main_account_id'];
        $childAccountId = $task->account_id;
        $bundleIds = $payload['bundle_ids'] ?? [];

        if (empty($bundleIds)) {
            throw new \Exception('Invalid payload: missing bundle_ids for batch sync');
        }

        Log::info('Batch bundle sync started', [
            'task_id' => $task->id,
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'bundles_count' => count($bundleIds)
        ]);

        $result = $this->batchSyncService->batchSyncBundles(
            $mainAccountId,
            $childAccountId,
            $bundleIds
        );

        $this->logSuccess($task, [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'bundles_count' => count($bundleIds),
            'success_count' => $result['success'] ?? 0,
            'failed_count' => $result['failed'] ?? 0
        ]);
    }
}
