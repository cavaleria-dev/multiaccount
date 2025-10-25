<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\ServiceSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для синхронизации услуг (services)
 *
 * Обрабатывает entity_type: 'service'
 */
class ServiceSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected ServiceSyncService $serviceSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'service';
    }

    protected function handleDelete(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        // При удалении услуги в главном - архивируем в дочерних
        $archivedCount = $this->serviceSyncService->archiveService(
            $payload['main_account_id'],
            $task->entity_id
        );

        $this->logSuccess($task, [
            'operation' => 'archive',
            'service_id' => $task->entity_id,
            'archived_count' => $archivedCount
        ]);
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $this->serviceSyncService->syncService(
            $payload['main_account_id'],
            $task->account_id,
            $task->entity_id
        );

        $this->logSuccess($task, [
            'main_account_id' => $payload['main_account_id'],
            'child_account_id' => $task->account_id,
            'service_id' => $task->entity_id
        ]);
    }
}
