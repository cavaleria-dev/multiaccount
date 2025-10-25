<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\BundleSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для синхронизации комплектов (bundles)
 *
 * Обрабатывает entity_type: 'bundle'
 */
class BundleSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected BundleSyncService $bundleSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'bundle';
    }

    protected function handleDelete(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        // При удалении комплекта в главном - архивируем в дочерних
        $archivedCount = $this->bundleSyncService->archiveBundle(
            $payload['main_account_id'],
            $task->entity_id
        );

        $this->logSuccess($task, [
            'operation' => 'archive',
            'bundle_id' => $task->entity_id,
            'archived_count' => $archivedCount
        ]);
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $this->bundleSyncService->syncBundle(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );

        $this->logSuccess($task, [
            'main_account_id' => $payload['main_account_id'],
            'child_account_id' => $task->account_id,
            'bundle_id' => $task->entity_id
        ]);
    }
}
