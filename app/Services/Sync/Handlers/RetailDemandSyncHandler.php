<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\RetailDemandSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для синхронизации розничных продаж (retaildemand)
 *
 * Обрабатывает entity_type: 'retaildemand'
 * Направление: child → main (обратная синхронизация)
 */
class RetailDemandSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected RetailDemandSyncService $retailDemandSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'retaildemand';
    }

    protected function requiresMainAccountId(): bool
    {
        return false;
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $this->retailDemandSyncService->syncRetailDemand(
            $task->account_id,
            $task->entity_id
        );

        $this->logSuccess($task, [
            'child_account_id' => $task->account_id,
            'retaildemand_id' => $task->entity_id,
            'direction' => 'child_to_main'
        ]);
    }
}
