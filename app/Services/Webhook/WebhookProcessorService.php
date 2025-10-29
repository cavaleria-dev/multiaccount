<?php

namespace App\Services\Webhook;

use App\Models\Account;
use App\Models\WebhookLog;
use App\Models\Webhook;
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

        // Map entity types to sync operations
        $syncMap = [
            'product' => 'sync_products',
            'service' => 'sync_services',
            'variant' => 'sync_variants',
            'bundle' => 'sync_bundles',
            'productfolder' => 'sync_product_folders',
        ];

        $syncOperation = $syncMap[$entityType] ?? null;

        if (!$syncOperation) {
            Log::warning('Unknown entity type for main account', [
                'entity_type' => $entityType,
                'account_id' => $account->account_id
            ]);
            return;
        }

        // TODO: Day 5 - Dispatch sync job for each child account
        // For now, just log what would happen
        Log::info('Main account webhook event ready for sync', [
            'account_id' => $account->account_id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'sync_operation' => $syncOperation,
            'note' => 'Will dispatch to ProcessWebhookSyncJob in Day 5'
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

        // Map entity types to sync operations
        $syncMap = [
            'customerorder' => 'sync_customer_orders',
            'retaildemand' => 'sync_retail_demands',
            'purchaseorder' => 'sync_purchase_orders',
        ];

        $syncOperation = $syncMap[$entityType] ?? null;

        if (!$syncOperation) {
            Log::warning('Unknown entity type for child account', [
                'entity_type' => $entityType,
                'account_id' => $account->account_id
            ]);
            return;
        }

        // TODO: Day 5 - Dispatch sync job to main account
        // For now, just log what would happen
        Log::info('Child account webhook event ready for sync', [
            'account_id' => $account->account_id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'sync_operation' => $syncOperation,
            'note' => 'Will dispatch to ProcessWebhookSyncJob in Day 5'
        ]);
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
