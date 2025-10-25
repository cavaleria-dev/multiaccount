<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\PurchaseOrderSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для синхронизации заказов поставщикам (purchaseorder)
 *
 * Обрабатывает entity_type: 'purchaseorder'
 * Направление: child → main (обратная синхронизация)
 */
class PurchaseOrderSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected PurchaseOrderSyncService $purchaseOrderSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'purchaseorder';
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
        $this->purchaseOrderSyncService->syncPurchaseOrder(
            $task->account_id,
            $task->entity_id
        );

        $this->logSuccess($task, [
            'child_account_id' => $task->account_id,
            'purchaseorder_id' => $task->entity_id,
            'direction' => 'child_to_main'
        ]);
    }
}
