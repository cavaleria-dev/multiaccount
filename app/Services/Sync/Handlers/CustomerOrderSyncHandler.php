<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\CustomerOrderSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для синхронизации заказов покупателей (customerorder)
 *
 * Обрабатывает entity_type: 'customerorder'
 * Направление: child → main (обратная синхронизация)
 */
class CustomerOrderSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected CustomerOrderSyncService $customerOrderSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'customerorder';
    }

    /**
     * Для заказов main_account_id берется из child_accounts таблицы
     */
    protected function requiresMainAccountId(): bool
    {
        return false; // Payload может не содержать main_account_id
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        // Для customerorder синхронизация идет child → main
        $this->customerOrderSyncService->syncCustomerOrder(
            $task->account_id, // child account
            $task->entity_id   // order ID
        );

        $this->logSuccess($task, [
            'child_account_id' => $task->account_id,
            'order_id' => $task->entity_id,
            'direction' => 'child_to_main'
        ]);
    }
}
