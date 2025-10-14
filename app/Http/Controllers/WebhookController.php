<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\BatchSyncService;
use App\Services\CustomerOrderSyncService;
use App\Services\RetailDemandSyncService;
use App\Services\PurchaseOrderSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер для обработки вебхуков МойСклад
 */
class WebhookController extends Controller
{
    protected BatchSyncService $batchSyncService;
    protected CustomerOrderSyncService $customerOrderSyncService;
    protected RetailDemandSyncService $retailDemandSyncService;
    protected PurchaseOrderSyncService $purchaseOrderSyncService;

    public function __construct(
        BatchSyncService $batchSyncService,
        CustomerOrderSyncService $customerOrderSyncService,
        RetailDemandSyncService $retailDemandSyncService,
        PurchaseOrderSyncService $purchaseOrderSyncService
    ) {
        $this->batchSyncService = $batchSyncService;
        $this->customerOrderSyncService = $customerOrderSyncService;
        $this->retailDemandSyncService = $retailDemandSyncService;
        $this->purchaseOrderSyncService = $purchaseOrderSyncService;
    }

    /**
     * Обработать вебхук от МойСклад
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            // Получить данные вебхука
            $accountId = $request->input('accountId');
            $entityType = $request->input('entityType');
            $action = $request->input('action');
            $events = $request->input('events', []);

            Log::info('Webhook received', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'action' => $action,
                'events_count' => count($events)
            ]);

            // Проверить аккаунт
            $account = Account::where('account_id', $accountId)->first();

            if (!$account) {
                Log::warning('Webhook for unknown account', [
                    'account_id' => $accountId
                ]);
                return response()->json(['status' => 'ignored', 'reason' => 'unknown_account'], 200);
            }

            // Обработать события
            foreach ($events as $event) {
                $this->processEvent($account, $entityType, $action, $event);
            }

            return response()->json(['status' => 'ok'], 200);

        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработать событие вебхука
     */
    protected function processEvent(Account $account, string $entityType, string $action, array $event): void
    {
        $entityId = $this->extractEntityId($event);

        if (!$entityId) {
            Log::warning('Cannot extract entity ID from event', [
                'account_id' => $account->account_id,
                'entity_type' => $entityType,
                'event' => $event
            ]);
            return;
        }

        Log::debug('Processing webhook event', [
            'account_id' => $account->account_id,
            'account_type' => $account->account_type,
            'entity_type' => $entityType,
            'action' => $action,
            'entity_id' => $entityId
        ]);

        // Роутинг по типу сущности и типу аккаунта
        match($entityType) {
            'product' => $this->handleProduct($account, $action, $entityId),
            'variant' => $this->handleVariant($account, $action, $entityId),
            'bundle' => $this->handleBundle($account, $action, $entityId),
            'customerorder' => $this->handleCustomerOrder($account, $action, $entityId),
            'retaildemand' => $this->handleRetailDemand($account, $action, $entityId),
            'purchaseorder' => $this->handlePurchaseOrder($account, $action, $entityId),
            default => Log::debug('Unhandled entity type', ['entity_type' => $entityType])
        };
    }

    /**
     * Обработать событие товара
     */
    protected function handleProduct(Account $account, string $action, string $entityId): void
    {
        // Синхронизация товаров идет только из главного аккаунта в дочерние
        if ($account->account_type !== 'main') {
            Log::debug('Product event from non-main account, ignoring', [
                'account_id' => $account->account_id,
                'entity_id' => $entityId
            ]);
            return;
        }

        match($action) {
            'CREATE', 'UPDATE' => $this->batchSyncService->batchSyncProduct(
                $account->account_id,
                $entityId
            ),
            'DELETE' => $this->batchSyncService->batchArchiveProduct(
                $account->account_id,
                $entityId
            ),
            default => Log::debug('Unhandled product action', ['action' => $action])
        };

        Log::info('Product sync queued', [
            'account_id' => $account->account_id,
            'product_id' => $entityId,
            'action' => $action
        ]);
    }

    /**
     * Обработать событие модификации
     */
    protected function handleVariant(Account $account, string $action, string $entityId): void
    {
        if ($account->account_type !== 'main') {
            return;
        }

        match($action) {
            'CREATE', 'UPDATE' => $this->batchSyncService->batchSyncVariant(
                $account->account_id,
                $entityId
            ),
            'DELETE' => $this->batchSyncService->batchArchiveVariant(
                $account->account_id,
                $entityId
            ),
            default => null
        };

        Log::info('Variant sync queued', [
            'account_id' => $account->account_id,
            'variant_id' => $entityId,
            'action' => $action
        ]);
    }

    /**
     * Обработать событие комплекта
     */
    protected function handleBundle(Account $account, string $action, string $entityId): void
    {
        if ($account->account_type !== 'main') {
            return;
        }

        match($action) {
            'CREATE', 'UPDATE' => $this->batchSyncService->batchSyncBundle(
                $account->account_id,
                $entityId
            ),
            'DELETE' => $this->batchSyncService->batchArchiveBundle(
                $account->account_id,
                $entityId
            ),
            default => null
        };

        Log::info('Bundle sync queued', [
            'account_id' => $account->account_id,
            'bundle_id' => $entityId,
            'action' => $action
        ]);
    }

    /**
     * Обработать событие заказа покупателя
     */
    protected function handleCustomerOrder(Account $account, string $action, string $entityId): void
    {
        // Заказы синхронизируются из дочерних в главный
        if ($account->account_type !== 'child') {
            Log::debug('Customer order event from non-child account, ignoring', [
                'account_id' => $account->account_id,
                'entity_id' => $entityId
            ]);
            return;
        }

        if ($action !== 'UPDATE') {
            Log::debug('Customer order non-update action, ignoring', [
                'action' => $action,
                'entity_id' => $entityId
            ]);
            return;
        }

        try {
            // Синхронизировать немедленно (не через очередь)
            // Так как заказы критичны по времени
            $this->customerOrderSyncService->syncCustomerOrder(
                $account->account_id,
                $entityId
            );

            Log::info('Customer order synced', [
                'account_id' => $account->account_id,
                'order_id' => $entityId
            ]);

        } catch (\Exception $e) {
            Log::error('Customer order sync failed', [
                'account_id' => $account->account_id,
                'order_id' => $entityId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Обработать событие розничной продажи
     */
    protected function handleRetailDemand(Account $account, string $action, string $entityId): void
    {
        // Розничные продажи синхронизируются из дочерних в главный
        if ($account->account_type !== 'child') {
            return;
        }

        if ($action !== 'UPDATE') {
            return;
        }

        try {
            // Синхронизировать немедленно
            $this->retailDemandSyncService->syncRetailDemand(
                $account->account_id,
                $entityId
            );

            Log::info('Retail demand synced', [
                'account_id' => $account->account_id,
                'demand_id' => $entityId
            ]);

        } catch (\Exception $e) {
            Log::error('Retail demand sync failed', [
                'account_id' => $account->account_id,
                'demand_id' => $entityId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Обработать событие заказа поставщику
     */
    protected function handlePurchaseOrder(Account $account, string $action, string $entityId): void
    {
        // Заказы поставщику синхронизируются из дочерних в главный
        if ($account->account_type !== 'child') {
            Log::debug('Purchase order event from non-child account, ignoring', [
                'account_id' => $account->account_id,
                'entity_id' => $entityId
            ]);
            return;
        }

        if ($action !== 'UPDATE') {
            Log::debug('Purchase order non-update action, ignoring', [
                'action' => $action,
                'entity_id' => $entityId
            ]);
            return;
        }

        try {
            // Синхронизировать немедленно (не через очередь)
            // Так как заказы критичны по времени
            $this->purchaseOrderSyncService->syncPurchaseOrder(
                $account->account_id,
                $entityId
            );

            Log::info('Purchase order synced', [
                'account_id' => $account->account_id,
                'order_id' => $entityId
            ]);

        } catch (\Exception $e) {
            Log::error('Purchase order sync failed', [
                'account_id' => $account->account_id,
                'order_id' => $entityId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Извлечь ID сущности из события
     */
    protected function extractEntityId(array $event): ?string
    {
        // Из meta.href извлечь последнюю часть URL
        $href = $event['meta']['href'] ?? null;

        if (!$href) {
            return null;
        }

        $parts = explode('/', $href);
        return end($parts) ?: null;
    }
}
