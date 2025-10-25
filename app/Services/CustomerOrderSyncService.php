<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации заказов покупателя (customerorder → customerorder)
 */
class CustomerOrderSyncService
{
    protected MoySkladService $moySkladService;
    protected CounterpartySyncService $counterpartySyncService;

    public function __construct(
        MoySkladService $moySkladService,
        CounterpartySyncService $counterpartySyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->counterpartySyncService = $counterpartySyncService;
    }

    /**
     * Синхронизировать заказ покупателя из дочернего в главный аккаунт
     *
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $customerOrderId UUID заказа покупателя в дочернем аккаунте
     * @return array|null Созданный заказ в главном аккаунте или null
     */
    public function syncCustomerOrder(string $childAccountId, string $customerOrderId): ?array
    {
        try {
            // Получить дочерний аккаунт и его настройки
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            // Проверить включена ли синхронизация заказов покупателя
            if (!$settings || !$settings->sync_customer_orders) {
                Log::debug('Customer order sync is disabled', [
                    'child_account_id' => $childAccountId
                ]);
                return null;
            }

            // Получить родительский аккаунт
            $parentAccount = $this->getParentAccount($childAccountId);
            if (!$parentAccount) {
                throw new \Exception('Parent account not found');
            }

            // Проверить, не синхронизирован ли уже этот заказ
            $existingMapping = EntityMapping::where('child_account_id', $childAccountId)
                ->where('child_entity_id', $customerOrderId)
                ->where('entity_type', 'customerorder')
                ->where('sync_direction', 'child_to_main')
                ->first();

            if ($existingMapping) {
                Log::info('Customer order already synced', [
                    'child_account_id' => $childAccountId,
                    'order_id' => $customerOrderId,
                    'parent_order_id' => $existingMapping->parent_entity_id
                ]);
                return null;
            }

            // Получить заказ из дочернего аккаунта
            $orderResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->get("entity/customerorder/{$customerOrderId}");

            $order = $orderResult['data'];

            // Проверить что заказ проведен (applicable = true)
            if (!isset($order['applicable']) || $order['applicable'] !== true) {
                Log::debug('Customer order is not applicable, skipping', [
                    'child_account_id' => $childAccountId,
                    'order_id' => $customerOrderId
                ]);
                return null;
            }

            // Синхронизировать контрагента
            $agentMeta = null;
            if (isset($order['agent']) && isset($order['agent']['meta'])) {
                $agentId = $this->extractIdFromMeta($order['agent']['meta']);
                $syncedAgent = $this->counterpartySyncService->syncCounterparty(
                    $parentAccount->account_id,
                    $childAccountId,
                    $agentId
                );
                $agentMeta = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/counterparty/{$syncedAgent['id']}",
                        'type' => 'counterparty',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Подготовить данные для создания заказа в главном аккаунте
            $newOrderData = [
                'name' => $order['name'] ?? null,
                'description' => $this->buildDescription($order, $childAccount),
                'moment' => $order['moment'] ?? now()->toIso8601String(),
                'applicable' => true,
            ];

            // Установить организацию
            if ($settings->target_organization_id) {
                $newOrderData['organization'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/organization/{$settings->target_organization_id}",
                        'type' => 'organization',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Установить контрагента
            if ($agentMeta) {
                $newOrderData['agent'] = $agentMeta;
            }

            // Установить статус
            if ($settings->customer_order_state_id) {
                $newOrderData['state'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/customerorder/metadata/states/{$settings->customer_order_state_id}",
                        'type' => 'state',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Установить ответственного сотрудника
            if ($settings->responsible_employee_id) {
                $newOrderData['owner'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/employee/{$settings->responsible_employee_id}",
                        'type' => 'employee',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Установить канал продаж
            if ($settings->customer_order_sales_channel_id) {
                $newOrderData['salesChannel'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/saleschannel/{$settings->customer_order_sales_channel_id}",
                        'type' => 'saleschannel',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Синхронизировать позиции заказа
            $newOrderData['positions'] = $this->syncPositions(
                $parentAccount->account_id,
                $childAccountId,
                $order['positions'] ?? []
            );

            // Создать заказ в главном аккаунте
            $newOrderResult = $this->moySkladService
                ->setAccessToken($parentAccount->access_token)
                ->post('entity/customerorder', $newOrderData);

            $newOrder = $newOrderResult['data'];

            // Сохранить маппинг (atomic operation to prevent race conditions)
            EntityMapping::firstOrCreate(
                [
                    'parent_account_id' => $parentAccount->account_id,
                    'child_account_id' => $childAccountId,
                    'entity_type' => 'customerorder',
                    'child_entity_id' => $customerOrderId,
                    'sync_direction' => 'child_to_main',
                ],
                [
                    'parent_entity_id' => $newOrder['id'],
                    'source_document_type' => 'customerorder',
                    'metadata' => [
                        'synced_at' => now()->toIso8601String(),
                    ]
                ]
            );

            Log::info('Customer order synced successfully', [
                'child_account_id' => $childAccountId,
                'parent_account_id' => $parentAccount->account_id,
                'child_order_id' => $customerOrderId,
                'parent_order_id' => $newOrder['id']
            ]);

            return $newOrder;

        } catch (\Exception $e) {
            Log::error('Failed to sync customer order', [
                'child_account_id' => $childAccountId,
                'order_id' => $customerOrderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Получить родительский аккаунт для дочернего
     */
    protected function getParentAccount(string $childAccountId): ?Account
    {
        $childAccountLink = \DB::table('child_accounts')
            ->where('child_account_id', $childAccountId)
            ->first();

        if (!$childAccountLink) {
            return null;
        }

        return Account::where('account_id', $childAccountLink->parent_account_id)->first();
    }

    /**
     * Извлечь ID из meta URL
     */
    protected function extractIdFromMeta(array $meta): string
    {
        $href = $meta['href'] ?? '';
        $parts = explode('/', $href);
        return end($parts);
    }

    /**
     * Построить описание для заказа
     */
    protected function buildDescription(array $order, Account $childAccount): string
    {
        $description = "Заказ из франшизы: {$childAccount->account_name}\n";
        $description .= "Исходный номер заказа: {$order['name']}\n";

        if (isset($order['description']) && $order['description']) {
            $description .= "\nОписание: {$order['description']}";
        }

        return $description;
    }

    /**
     * Синхронизировать позиции заказа
     */
    protected function syncPositions(string $parentAccountId, string $childAccountId, array $positions): array
    {
        $syncedPositions = [];

        // Получить позиции если передан meta
        if (isset($positions['meta'])) {
            // Это meta, нужно загрузить позиции
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
            $positionsResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->get($this->extractPathFromHref($positions['meta']['href']));

            $positions = $positionsResult['data']['rows'] ?? [];
        }

        foreach ($positions as $position) {
            // Найти товар в главном аккаунте
            $assortmentId = $this->extractIdFromMeta($position['assortment']['meta'] ?? []);

            $productMapping = EntityMapping::where('parent_account_id', $parentAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('child_entity_id', $assortmentId)
                ->whereIn('entity_type', ['product', 'variant', 'bundle'])
                ->where('sync_direction', 'main_to_child')
                ->first();

            if (!$productMapping) {
                Log::warning('Product not found in parent account, skipping position', [
                    'child_product_id' => $assortmentId
                ]);
                continue;
            }

            $syncedPositions[] = [
                'quantity' => $position['quantity'] ?? 1,
                'price' => $position['price'] ?? 0,
                'assortment' => [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/{$productMapping->entity_type}/{$productMapping->parent_entity_id}",
                        'type' => $productMapping->entity_type,
                        'mediaType' => 'application/json'
                    ]
                ]
            ];
        }

        return $syncedPositions;
    }

    /**
     * Извлечь путь из href
     */
    protected function extractPathFromHref(string $href): string
    {
        $apiUrl = config('moysklad.api_url');
        return str_replace($apiUrl . '/', '', $href);
    }
}
