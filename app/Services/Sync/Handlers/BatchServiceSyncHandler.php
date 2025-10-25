<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\BatchSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для пакетной синхронизации услуг
 *
 * Обрабатывает entity_type: 'batch_services'
 */
class BatchServiceSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected BatchSyncService $batchSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'batch_services';
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $mainAccountId = $payload['main_account_id'];
        $childAccountId = $task->account_id;
        $serviceIds = $payload['service_ids'] ?? [];

        if (empty($serviceIds)) {
            throw new \Exception('Invalid payload: missing service_ids for batch sync');
        }

        Log::info('Batch service sync started', [
            'task_id' => $task->id,
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'services_count' => count($serviceIds)
        ]);

        $result = $this->batchSyncService->batchSyncServices(
            $mainAccountId,
            $childAccountId,
            $serviceIds
        );

        $this->logSuccess($task, [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'services_count' => count($serviceIds),
            'success_count' => $result['success'] ?? 0,
            'failed_count' => $result['failed'] ?? 0
        ]);
    }
}
