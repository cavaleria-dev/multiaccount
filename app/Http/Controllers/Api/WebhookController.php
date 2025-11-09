<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Services\EntityConfig;

/**
 * Контроллер для обработки вебхуков от МойСклад
 */
class WebhookController extends Controller
{
    /**
     * Обработка входящего вебхука
     *
     * POST /api/webhooks/moysklad
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();

            Log::info('МойСклад Webhook получен', [
                'action' => $payload['action'] ?? null,
                'entity_type' => $payload['entityType'] ?? null
            ]);

            // Получение данных вебхука
            $action = $payload['action'] ?? null; // CREATE, UPDATE, DELETE
            $entityType = $payload['entityType'] ?? null; // product, customerorder и т.д.
            $entities = $payload['events'] ?? [];

            if (!$action || !$entityType) {
                return response()->json(['error' => 'Invalid webhook payload'], 400);
            }

            // Обработка в зависимости от типа сущности
            foreach ($entities as $event) {
                $this->processEvent($action, $entityType, $event);
            }

            return response()->json(['status' => 'success'], 200);

        } catch (\Exception $e) {
            Log::error('МойСклад Webhook: Ошибка обработки', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'error' => 'Webhook processing failed'
            ], 500);
        }
    }

    /**
     * Обработка отдельного события
     */
    private function processEvent(string $action, string $entityType, array $event): void
    {
        $entityId = $event['meta']['href'] ?? null;
        $accountId = $event['accountId'] ?? null;

        Log::info('Обработка события', [
            'action' => $action,
            'entityType' => $entityType,
            'entityId' => $entityId
        ]);

        // Здесь логика обработки разных типов событий
        switch ($entityType) {
            case 'product':
                $this->handleProductEvent($action, $event, $accountId);
                break;

            case 'variant':
                $this->handleVariantEvent($action, $event, $accountId);
                break;

            case 'service':
                $this->handleServiceEvent($action, $event, $accountId);
                break;

            case 'customerorder':
                $this->handleCustomerOrderEvent($action, $event, $accountId);
                break;

            case 'demand':
                $this->handleDemandEvent($action, $event, $accountId);
                break;

            default:
                Log::info('Неизвестный тип сущности', ['type' => $entityType]);
        }
    }

    /**
     * Обработка события товара
     */
    private function handleProductEvent(string $action, array $event, ?string $accountId): void
    {
        if ($action === 'CREATE' || $action === 'UPDATE') {
            // Логика синхронизации товара
            // Например, запустить Job для синхронизации с дочерними аккаунтами
            Log::info('Товар изменен', [
                'action' => $action,
                'productId' => $event['meta']['href'] ?? null
            ]);

            // Dispatch job для синхронизации
            // \App\Jobs\SyncProductJob::dispatch($accountId, $event);
        }

        if ($action === 'DELETE') {
            Log::info('Товар удален', [
                'productId' => $event['meta']['href'] ?? null
            ]);
        }
    }

    /**
     * Обработка события модификации
     */
    private function handleVariantEvent(string $action, array $event, ?string $accountId): void
    {
        $variantId = $this->extractEntityId($event['meta']['href'] ?? '');

        if (!$variantId || !$accountId) {
            Log::warning('Cannot extract variant ID or account ID from webhook', ['event' => $event]);
            return;
        }

        if ($action === 'CREATE' || $action === 'UPDATE') {
            Log::info('Variant modified via webhook', [
                'action' => $action,
                'variant_id' => $variantId,
                'account_id' => $accountId
            ]);

            // Создать задачи синхронизации для всех дочерних аккаунтов
            $childAccounts = DB::table('child_accounts')
                ->where('parent_account_id', $accountId)
                ->where('status', 'active')
                ->get();

            foreach ($childAccounts as $child) {
                \App\Models\SyncQueue::create([
                    'account_id' => $child->child_account_id,
                    'entity_type' => 'variant',
                    'entity_id' => $variantId,
                    'operation' => 'create',
                    'priority' => EntityConfig::get('variant')['batch_priority'] ?? 6, // Same as batch variants
                    'scheduled_at' => now(),
                    'status' => 'pending',
                    'attempts' => 0,
                    'payload' => [
                        'main_account_id' => $accountId
                    ]
                ]);
            }

            Log::info('Variant sync tasks created from webhook', [
                'variant_id' => $variantId,
                'tasks_created' => $childAccounts->count()
            ]);
        }

        if ($action === 'DELETE') {
            Log::info('Variant deleted via webhook', ['variant_id' => $variantId]);

            // Архивировать variant во всех дочерних аккаунтах
            $childAccounts = DB::table('child_accounts')
                ->where('parent_account_id', $accountId)
                ->where('status', 'active')
                ->get();

            foreach ($childAccounts as $child) {
                \App\Models\SyncQueue::create([
                    'account_id' => $child->child_account_id,
                    'entity_type' => 'variant',
                    'entity_id' => $variantId,
                    'operation' => 'delete',
                    'priority' => 3, // Archive operations - lower than bundles
                    'scheduled_at' => now(),
                    'status' => 'pending',
                    'attempts' => 0,
                    'payload' => [
                        'main_account_id' => $accountId
                    ]
                ]);
            }

            Log::info('Variant archive tasks created from webhook', [
                'variant_id' => $variantId,
                'tasks_created' => $childAccounts->count()
            ]);
        }
    }

    /**
     * Обработка события услуги
     */
    private function handleServiceEvent(string $action, array $event, ?string $accountId): void
    {
        if ($action === 'CREATE' || $action === 'UPDATE') {
            // Логика синхронизации услуги
            Log::info('Услуга изменена', [
                'action' => $action,
                'serviceId' => $event['meta']['href'] ?? null
            ]);

            // Dispatch job для синхронизации
            // \App\Jobs\SyncServiceJob::dispatch($accountId, $event);
        }

        if ($action === 'DELETE') {
            Log::info('Услуга удалена', [
                'serviceId' => $event['meta']['href'] ?? null
            ]);
            // Архивировать услугу в дочерних аккаунтах
        }
    }

    /**
     * Обработка события заказа покупателя
     */
    private function handleCustomerOrderEvent(string $action, array $event, ?string $accountId): void
    {
        if ($action === 'CREATE') {
            Log::info('Новый заказ покупателя', [
                'orderId' => $event['meta']['href'] ?? null
            ]);

            // Dispatch job для обработки заказа
            // \App\Jobs\ProcessCustomerOrderJob::dispatch($accountId, $event);
        }
    }

    /**
     * Обработка события отгрузки
     */
    private function handleDemandEvent(string $action, array $event, ?string $accountId): void
    {
        if ($action === 'CREATE') {
            Log::info('Новая отгрузка', [
                'demandId' => $event['meta']['href'] ?? null
            ]);
        }
    }

    /**
     * Извлечь ID сущности из href
     */
    private function extractEntityId(?string $href): ?string
    {
        if (empty($href)) {
            return null;
        }

        $parts = explode('/', $href);
        return end($parts) ?: null;
    }
}