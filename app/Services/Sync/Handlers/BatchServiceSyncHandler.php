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
        $services = $payload['services'] ?? [];

        if (empty($services)) {
            throw new \Exception('Invalid payload: missing services array for batch sync');
        }

        Log::info('Batch service sync started', [
            'task_id' => $task->id,
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'services_count' => count($services)
        ]);

        $result = $this->batchSyncService->batchSyncServices(
            $mainAccountId,
            $childAccountId,
            $services
        );

        $this->logSuccess($task, [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'services_count' => count($services),
            'success_count' => $result['success'] ?? 0,
            'failed_count' => $result['failed'] ?? 0
        ]);
    }
}
