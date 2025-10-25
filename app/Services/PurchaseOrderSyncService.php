<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации заказов поставщику (purchaseorder → customerorder)
 *
 * Логика: Когда франшиза создает заказ поставщику (у главного офиса),
 * этот заказ синхронизируется в главный аккаунт как заказ покупателя (customerorder)
 */
class PurchaseOrderSyncService
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
     * Синхронизировать заказ поставщику из дочернего в главный аккаунт
     *
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $purchaseOrderId UUID заказа поставщику в дочернем аккаунте
     * @return array|null Созданный/обновленный заказ в главном аккаунте или null
     */
    public function syncPurchaseOrder(string $childAccountId, string $purchaseOrderId): ?array
    {
        try {
            // Получить дочерний аккаунт и его настройки
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            // Проверить включена ли синхронизация заказов поставщику
            if (!$settings || !$settings->sync_purchase_orders) {
                Log::debug('Purchase order sync is disabled', [
                    'child_account_id' => $childAccountId
                ]);
                return null;
            }

            // Получить родительский аккаунт
            $parentAccount = $this->getParentAccount($childAccountId);
            if (!$parentAccount) {
                throw new \Exception('Parent account not found');
            }

            // Получить заказ поставщику из дочернего аккаунта
            $orderResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->get("entity/purchaseorder/{$purchaseOrderId}");

            $order = $orderResult['data'];

            // Проверить что поставщик = главный офис (supplier_counterparty_id)
            if (!$this->isOrderToMainOffice($order, $parentAccount, $settings)) {
                Log::debug('Purchase order is not to main office, skipping', [
                    'child_account_id' => $childAccountId,
                    'purchase_order_id' => $purchaseOrderId
                ]);
                return null;
            }

            // Проверить, не синхронизирован ли уже этот заказ
            $existingMapping = EntityMapping::where('child_account_id', $childAccountId)
                ->where('child_entity_id', $purchaseOrderId)
                ->where('entity_type', 'customerorder')
                ->where('sync_direction', 'child_to_main')
                ->where('source_document_type', 'purchaseorder')
                ->first();

            // Если заказ уже синхронизирован
            if ($existingMapping) {
                // Проверить статус applicable
                $isApplicable = $order['applicable'] ?? false;

                if (!$isApplicable) {
                    // Снять флаг "проведено" в главном аккаунте
                    return $this->unmarkApplicable($parentAccount, $existingMapping->parent_entity_id);
                }

                Log::debug('Purchase order already synced and applicable', [
                    'child_account_id' => $childAccountId,
                    'purchase_order_id' => $purchaseOrderId,
                    'parent_order_id' => $existingMapping->parent_entity_id
                ]);

                return null;
            }

            // Проверить что заказ проведен (applicable = true)
            if (!isset($order['applicable']) || $order['applicable'] !== true) {
                Log::debug('Purchase order is not applicable, skipping sync', [
                    'child_account_id' => $childAccountId,
                    'purchase_order_id' => $purchaseOrderId
                ]);
                return null;
            }

            // Подготовить данные для создания заказа покупателя в главном аккаунте
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

            // Установить контрагента (франшиза)
            // Используем counterparty_id из accounts - это франшиза как контрагент в главном
            if ($parentAccount->counterparty_id) {
                $newOrderData['agent'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/counterparty/{$parentAccount->counterparty_id}",
                        'type' => 'counterparty',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Установить статус
            if ($settings->purchase_order_state_id) {
                $newOrderData['state'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/customerorder/metadata/states/{$settings->purchase_order_state_id}",
                        'type' => 'state',
                        'mediaType' => 'application/json'
                    ]
                ];
            } elseif ($settings->customer_order_state_id) {
                // Fallback на общий статус заказов покупателя
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

            // Установить канал продаж (отдельный для заказов поставщику)
            if ($settings->purchase_order_sales_channel_id) {
                $newOrderData['salesChannel'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/saleschannel/{$settings->purchase_order_sales_channel_id}",
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

            // Если нет позиций - не создавать заказ
            if (empty($newOrderData['positions'])) {
                Log::warning('Purchase order has no valid positions, skipping', [
                    'child_account_id' => $childAccountId,
                    'purchase_order_id' => $purchaseOrderId
                ]);
                return null;
            }

            // Создать заказ покупателя в главном аккаунте
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
                    'child_entity_id' => $purchaseOrderId,
                    'sync_direction' => 'child_to_main',
                ],
                [
                    'parent_entity_id' => $newOrder['id'],
                    'source_document_type' => 'purchaseorder',
                    'metadata' => [
                        'synced_at' => now()->toIso8601String(),
                    ]
                ]
            );

            Log::info('Purchase order synced successfully', [
                'child_account_id' => $childAccountId,
                'parent_account_id' => $parentAccount->account_id,
                'child_purchase_order_id' => $purchaseOrderId,
                'parent_customer_order_id' => $newOrder['id']
            ]);

            return $newOrder;

        } catch (\Exception $e) {
            Log::error('Failed to sync purchase order', [
                'child_account_id' => $childAccountId,
                'purchase_order_id' => $purchaseOrderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Снять флаг "проведено" у заказа покупателя в главном аккаунте
     *
     * @param Account $parentAccount
     * @param string $customerOrderId UUID заказа покупателя в главном аккаунте
     * @return array|null Обновленный заказ или null
     */
    protected function unmarkApplicable(Account $parentAccount, string $customerOrderId): ?array
    {
        try {
            $updateResult = $this->moySkladService
                ->setAccessToken($parentAccount->access_token)
                ->put("entity/customerorder/{$customerOrderId}", [
                    'applicable' => false
                ]);

            Log::info('Purchase order marked as not applicable in parent', [
                'parent_account_id' => $parentAccount->account_id,
                'customer_order_id' => $customerOrderId
            ]);

            return $updateResult['data'];

        } catch (\Exception $e) {
            Log::error('Failed to unmark purchase order as applicable', [
                'parent_account_id' => $parentAccount->account_id,
                'customer_order_id' => $customerOrderId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Проверить что заказ адресован главному офису
     */
    protected function isOrderToMainOffice(array $order, Account $parentAccount, SyncSetting $settings): bool
    {
        // Извлечь ID поставщика из заказа
        $supplierHref = $order['agent']['meta']['href'] ?? null;
        if (!$supplierHref) {
            return false;
        }

        $supplierId = $this->extractIdFromMeta(['href' => $supplierHref]);

        // Проверить что поставщик = supplier_counterparty_id из настроек
        // (это контрагент главного офиса в дочернем аккаунте)
        return $supplierId === $settings->supplier_counterparty_id;
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
        $description = "Заказ поставщику из франшизы: {$childAccount->account_name}\n";
        $description .= "Исходный номер заказа: {$order['name']}\n";
        $description .= "Тип документа: Заказ поставщику (purchaseorder)\n";

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
            // Найти товар в главном аккаунте через маппинг
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
