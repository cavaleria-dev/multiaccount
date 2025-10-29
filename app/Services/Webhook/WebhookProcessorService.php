<?php

namespace App\Services\Webhook;

use App\Models\Account;
use App\Models\WebhookLog;
use App\Models\Webhook;
use App\Services\BatchSyncService;
use App\Services\CustomerOrderSyncService;
use App\Services\RetailDemandSyncService;
use App\Services\PurchaseOrderSyncService;
use Illuminate\Support\Facades\Log;

/**
 * WebhookProcessorService
 *
 * Service for processing webhook payloads and triggering sync operations
 *
 * Responsibilities:
 * - Extract entities from webhook payload
 * - Route to appropriate sync handlers based on entity type
 * - Handle both main and child account webhooks
 * - Update processing metrics and status
 */
class WebhookProcessorService
{
    public function __construct(
        protected BatchSyncService $batchSyncService,
        protected CustomerOrderSyncService $customerOrderSyncService,
        protected RetailDemandSyncService $retailDemandSyncService,
        protected PurchaseOrderSyncService $purchaseOrderSyncService
    ) {}
    /**
     * Обработать webhook лог
     *
     * @param WebhookLog $webhookLog Лог вебхука для обработки
     * @return void
     */
    public function process(WebhookLog $webhookLog): void
    {
        $startTime = microtime(true);

        try {
            // 1. Mark as processing
            $webhookLog->markAsProcessing();

            // 2. Extract events from payload
            $payload = $webhookLog->payload;
            $events = $payload['events'] ?? [];

            if (empty($events)) {
                throw new \Exception('Webhook payload contains no events');
            }

            Log::info('Processing webhook', [
                'webhook_log_id' => $webhookLog->id,
                'request_id' => $webhookLog->request_id,
                'account_id' => $webhookLog->account_id,
                'entity_type' => $webhookLog->entity_type,
                'action' => $webhookLog->action,
                'events_count' => count($events)
            ]);

            // 3. Get account to determine routing
            $account = Account::where('account_id', $webhookLog->account_id)->firstOrFail();
            $accountType = $account->account_type ?? 'main';

            // 4. Process each event
            $processedCount = 0;
            $errors = [];

            foreach ($events as $event) {
                try {
                    $this->processEvent($event, $account, $accountType, $webhookLog);
                    $processedCount++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'entity_href' => $event['meta']['href'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ];

                    Log::error('Failed to process webhook event', [
                        'webhook_log_id' => $webhookLog->id,
                        'event' => $event,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // 5. Calculate processing time
            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // 6. Mark as completed or failed
            if (empty($errors)) {
                $webhookLog->markAsCompleted($processingTimeMs);

                Log::info('Webhook processed successfully', [
                    'webhook_log_id' => $webhookLog->id,
                    'request_id' => $webhookLog->request_id,
                    'processed_count' => $processedCount,
                    'processing_time_ms' => $processingTimeMs
                ]);
            } else {
                $errorMessage = sprintf(
                    'Processed %d/%d events. Errors: %s',
                    $processedCount,
                    count($events),
                    json_encode($errors)
                );

                $webhookLog->markAsFailed($errorMessage);

                // Update webhook failure counter
                if ($webhookLog->webhook) {
                    $webhookLog->webhook->incrementFailed();
                }

                Log::warning('Webhook processed with errors', [
                    'webhook_log_id' => $webhookLog->id,
                    'request_id' => $webhookLog->request_id,
                    'processed_count' => $processedCount,
                    'errors_count' => count($errors),
                    'errors' => $errors
                ]);
            }

        } catch (\Exception $e) {
            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $webhookLog->markAsFailed($e->getMessage());

            // Update webhook failure counter
            if ($webhookLog->webhook) {
                $webhookLog->webhook->incrementFailed();
            }

            Log::error('Webhook processing failed', [
                'webhook_log_id' => $webhookLog->id,
                'request_id' => $webhookLog->request_id,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTimeMs
            ]);

            throw $e;
        }
    }

    /**
     * Обработать одно событие из webhook payload
     *
     * @param array $event Событие из events массива
     * @param Account $account Аккаунт
     * @param string $accountType Тип аккаунта (main/child)
     * @param WebhookLog $webhookLog Лог вебхука
     * @return void
     */
    protected function processEvent(array $event, Account $account, string $accountType, WebhookLog $webhookLog): void
    {
        $action = $event['action'] ?? null;
        $entityType = $event['meta']['type'] ?? null;
        $entityHref = $event['meta']['href'] ?? null;

        if (!$action || !$entityType || !$entityHref) {
            throw new \Exception('Invalid event structure: missing action, type, or href');
        }

        // Route based on account type and entity type
        if ($accountType === 'main') {
            $this->processMainAccountEvent($event, $account, $entityType, $action);
        } else {
            $this->processChildAccountEvent($event, $account, $entityType, $action);
        }

        Log::debug('Webhook event processed', [
            'webhook_log_id' => $webhookLog->id,
            'entity_type' => $entityType,
            'action' => $action,
            'entity_href' => $entityHref
        ]);
    }

    /**
     * Обработать событие главного аккаунта (товары)
     *
     * @param array $event Событие
     * @param Account $account Аккаунт
     * @param string $entityType Тип сущности
     * @param string $action Действие
     * @return void
     */
    protected function processMainAccountEvent(array $event, Account $account, string $entityType, string $action): void
    {
        // Main account processes product-related entities
        // These should be synced to child accounts

        $entityHref = $event['meta']['href'];
        $entityId = $this->extractEntityId($entityHref);

        try {
            // Route to appropriate sync method based on entity type and action
            match($entityType) {
                'product' => $this->syncProduct($account->account_id, $entityId, $action),
                'service' => $this->syncService($account->account_id, $entityId, $action),
                'variant' => $this->syncVariant($account->account_id, $entityId, $action),
                'bundle' => $this->syncBundle($account->account_id, $entityId, $action),
                'productfolder' => $this->syncProductFolder($account->account_id, $entityId, $action),
                default => Log::warning('Unknown entity type for main account', [
                    'entity_type' => $entityType,
                    'account_id' => $account->account_id
                ])
            };

            Log::info('Main account webhook event synced', [
                'account_id' => $account->account_id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'action' => $action
            ]);

        } catch (\Exception $e) {
            Log::error('Main account webhook sync failed', [
                'account_id' => $account->account_id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'action' => $action,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Синхронизировать product
     */
    protected function syncProduct(string $mainAccountId, string $productId, string $action): void
    {
        if ($action === 'DELETE') {
            $this->batchSyncService->batchArchiveProduct($mainAccountId, $productId);
        } else {
            // CREATE or UPDATE
            $this->batchSyncService->batchSyncProduct($mainAccountId, $productId);
        }
    }

    /**
     * Синхронизировать service
     */
    protected function syncService(string $mainAccountId, string $serviceId, string $action): void
    {
        // TODO: Implement batchSyncService for single service
        // For now, use mass sync as workaround
        Log::info('Service sync triggered (mass sync fallback)', [
            'main_account_id' => $mainAccountId,
            'service_id' => $serviceId,
            'action' => $action,
            'note' => 'Using mass batchSyncServices as single service sync not implemented yet'
        ]);

        // Fallback to mass sync
        // TODO: Create batchSyncService method for single service
        // $this->batchSyncService->batchSyncServices($mainAccountId, ...);
    }

    /**
     * Синхронизировать variant
     */
    protected function syncVariant(string $mainAccountId, string $variantId, string $action): void
    {
        if ($action === 'DELETE') {
            $this->batchSyncService->batchArchiveVariant($mainAccountId, $variantId);
        } else {
            // CREATE or UPDATE
            $this->batchSyncService->batchSyncVariant($mainAccountId, $variantId);
        }
    }

    /**
     * Синхронизировать bundle
     */
    protected function syncBundle(string $mainAccountId, string $bundleId, string $action): void
    {
        if ($action === 'DELETE') {
            $this->batchSyncService->batchArchiveBundle($mainAccountId, $bundleId);
        } else {
            // CREATE or UPDATE
            $this->batchSyncService->batchSyncBundle($mainAccountId, $bundleId);
        }
    }

    /**
     * Синхронизировать productfolder
     */
    protected function syncProductFolder(string $mainAccountId, string $folderId, string $action): void
    {
        // TODO: Implement product folder sync
        // Product folders are currently synced as part of product sync (pre-sync phase)
        Log::info('ProductFolder webhook received', [
            'main_account_id' => $mainAccountId,
            'folder_id' => $folderId,
            'action' => $action,
            'note' => 'ProductFolder sync not implemented yet - folders synced during product sync'
        ]);
    }

    /**
     * Обработать событие дочернего аккаунта (заказы)
     *
     * @param array $event Событие
     * @param Account $account Аккаунт
     * @param string $entityType Тип сущности
     * @param string $action Действие
     * @return void
     */
    protected function processChildAccountEvent(array $event, Account $account, string $entityType, string $action): void
    {
        // Child account processes order-related entities
        // These should be synced to main account

        $entityHref = $event['meta']['href'];
        $entityId = $this->extractEntityId($entityHref);

        try {
            // Route to appropriate sync method based on entity type
            match($entityType) {
                'customerorder' => $this->syncCustomerOrder($account->account_id, $entityId, $action),
                'retaildemand' => $this->syncRetailDemand($account->account_id, $entityId, $action),
                'purchaseorder' => $this->syncPurchaseOrder($account->account_id, $entityId, $action),
                default => Log::warning('Unknown entity type for child account', [
                    'entity_type' => $entityType,
                    'account_id' => $account->account_id
                ])
            };

            Log::info('Child account webhook event synced', [
                'account_id' => $account->account_id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'action' => $action
            ]);

        } catch (\Exception $e) {
            Log::error('Child account webhook sync failed', [
                'account_id' => $account->account_id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'action' => $action,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Синхронизировать customer order
     */
    protected function syncCustomerOrder(string $childAccountId, string $orderId, string $action): void
    {
        // Orders are synced immediately (time-sensitive)
        // Only process CREATE and UPDATE (not DELETE)
        if (!in_array($action, ['CREATE', 'UPDATE'])) {
            Log::debug('Customer order action ignored', [
                'action' => $action,
                'order_id' => $orderId
            ]);
            return;
        }

        $this->customerOrderSyncService->syncCustomerOrder($childAccountId, $orderId);
    }

    /**
     * Синхронизировать retail demand
     */
    protected function syncRetailDemand(string $childAccountId, string $demandId, string $action): void
    {
        // Retail demands are synced immediately (time-sensitive)
        // Only process CREATE and UPDATE (not DELETE)
        if (!in_array($action, ['CREATE', 'UPDATE'])) {
            Log::debug('Retail demand action ignored', [
                'action' => $action,
                'demand_id' => $demandId
            ]);
            return;
        }

        $this->retailDemandSyncService->syncRetailDemand($childAccountId, $demandId);
    }

    /**
     * Синхронизировать purchase order
     */
    protected function syncPurchaseOrder(string $childAccountId, string $orderId, string $action): void
    {
        // Purchase orders are synced immediately (time-sensitive)
        // Process CREATE and UPDATE (not DELETE)
        if (!in_array($action, ['CREATE', 'UPDATE'])) {
            Log::debug('Purchase order action ignored', [
                'action' => $action,
                'order_id' => $orderId
            ]);
            return;
        }

        $this->purchaseOrderSyncService->syncPurchaseOrder($childAccountId, $orderId);
    }

    /**
     * Извлечь ID сущности из href
     *
     * @param string $href URL сущности
     * @return string UUID сущности
     */
    protected function extractEntityId(string $href): string
    {
        // href format: https://api.moysklad.ru/api/remap/1.2/entity/{type}/{id}
        $parts = explode('/', $href);
        return end($parts);
    }

    /**
     * Получить статистику обработки вебхуков
     *
     * @param string $accountId UUID аккаунта
     * @param int $hours Количество часов для анализа (по умолчанию 24)
     * @return array Статистика
     */
    public function getProcessingStats(string $accountId, int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $logs = WebhookLog::where('account_id', $accountId)
            ->where('created_at', '>=', $since)
            ->get();

        $total = $logs->count();
        $pending = $logs->where('status', 'pending')->count();
        $processing = $logs->where('status', 'processing')->count();
        $completed = $logs->where('status', 'completed')->count();
        $failed = $logs->where('status', 'failed')->count();

        $avgProcessingTime = $logs->where('status', 'completed')
            ->where('processing_time_ms', '>', 0)
            ->avg('processing_time_ms');

        return [
            'period_hours' => $hours,
            'total' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
            'avg_processing_time_ms' => $avgProcessingTime ? round($avgProcessingTime, 2) : 0,
        ];
    }
}
