<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации розничных продаж (retaildemand → customerorder)
 */
class RetailDemandSyncService
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
     * Синхронизировать розничную продажу из дочернего в главный аккаунт
     * Создает заказ покупателя (customerorder) в главном
     *
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $retailDemandId UUID розничной продажи
     * @return array|null Созданный заказ в главном аккаунте или null
     */
    public function syncRetailDemand(string $childAccountId, string $retailDemandId): ?array
    {
        try {
            // Получить дочерний аккаунт и его настройки
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            // Проверить включена ли синхронизация розничных продаж
            if (!$settings || !$settings->sync_retail_demands) {
                Log::debug('Retail demand sync is disabled', [
                    'child_account_id' => $childAccountId
                ]);
                return null;
            }

            // Получить родительский аккаунт
            $parentAccount = $this->getParentAccount($childAccountId);
            if (!$parentAccount) {
                throw new \Exception('Parent account not found');
            }

            // Проверить, не синхронизирована ли уже эта продажа
            $existingMapping = EntityMapping::where('child_account_id', $childAccountId)
                ->where('child_entity_id', $retailDemandId)
                ->where('entity_type', 'customerorder')
                ->where('sync_direction', 'child_to_main')
                ->where('source_document_type', 'retaildemand')
                ->first();

            if ($existingMapping) {
                Log::info('Retail demand already synced', [
                    'child_account_id' => $childAccountId,
                    'retaildemand_id' => $retailDemandId,
                    'parent_order_id' => $existingMapping->parent_entity_id
                ]);
                return null;
            }

            // Получить розничную продажу из дочернего аккаунта
            $demandResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->get("entity/retaildemand/{$retailDemandId}");

            $demand = $demandResult['data'];

            // Проверить что продажа проведена (applicable = true)
            if (!isset($demand['applicable']) || $demand['applicable'] !== true) {
                Log::debug('Retail demand is not applicable, skipping', [
                    'child_account_id' => $childAccountId,
                    'retaildemand_id' => $retailDemandId
                ]);
                return null;
            }

            // Синхронизировать контрагента (если есть)
            $agentMeta = null;
            if (isset($demand['agent']) && isset($demand['agent']['meta'])) {
                $agentId = $this->extractIdFromMeta($demand['agent']['meta']);
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

            // Подготовить данные для создания заказа покупателя в главном аккаунте
            $orderData = [
                'name' => $demand['name'] ?? null,
                'description' => $this->buildDescription($demand, $childAccount),
                'moment' => $demand['moment'] ?? now()->toIso8601String(),
                'applicable' => true,
            ];

            // Установить организацию
            if ($settings->target_organization_id) {
                $orderData['organization'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/organization/{$settings->target_organization_id}",
                        'type' => 'organization',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Установить контрагента
            if ($agentMeta) {
                $orderData['agent'] = $agentMeta;
            }

            // Установить статус (из настроек retail_demand)
            if ($settings->retail_demand_state_id) {
                $orderData['state'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/customerorder/metadata/states/{$settings->retail_demand_state_id}",
                        'type' => 'state',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Установить ответственного сотрудника
            if ($settings->responsible_employee_id) {
                $orderData['owner'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/employee/{$settings->responsible_employee_id}",
                        'type' => 'employee',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Установить канал продаж (отдельный для розничных продаж)
            if ($settings->retail_demand_sales_channel_id) {
                $orderData['salesChannel'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/saleschannel/{$settings->retail_demand_sales_channel_id}",
                        'type' => 'saleschannel',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Синхронизировать позиции
            $orderData['positions'] = $this->syncPositions(
                $parentAccount->account_id,
                $childAccountId,
                $demand['positions'] ?? []
            );

            // Создать заказ покупателя в главном аккаунте
            $newOrderResult = $this->moySkladService
                ->setAccessToken($parentAccount->access_token)
                ->post('entity/customerorder', $orderData);

            $newOrder = $newOrderResult['data'];

            // Сохранить маппинг
            EntityMapping::create([
                'parent_account_id' => $parentAccount->account_id,
                'child_account_id' => $childAccountId,
                'entity_type' => 'customerorder',
                'parent_entity_id' => $newOrder['id'],
                'child_entity_id' => $retailDemandId,
                'sync_direction' => 'child_to_main',
                'source_document_type' => 'retaildemand',
                'metadata' => [
                    'synced_at' => now()->toIso8601String(),
                ]
            ]);

            Log::info('Retail demand synced successfully', [
                'child_account_id' => $childAccountId,
                'parent_account_id' => $parentAccount->account_id,
                'retaildemand_id' => $retailDemandId,
                'parent_order_id' => $newOrder['id']
            ]);

            return $newOrder;

        } catch (\Exception $e) {
            Log::error('Failed to sync retail demand', [
                'child_account_id' => $childAccountId,
                'retaildemand_id' => $retailDemandId,
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
    protected function buildDescription(array $demand, Account $childAccount): string
    {
        $description = "Розничная продажа из франшизы: {$childAccount->account_name}\n";
        $description .= "Исходный номер: {$demand['name']}\n";

        if (isset($demand['description']) && $demand['description']) {
            $description .= "\nОписание: {$demand['description']}";
        }

        // Добавить информацию о розничной смене если есть
        if (isset($demand['retailShift']) && isset($demand['retailShift']['name'])) {
            $description .= "\nРозничная смена: {$demand['retailShift']['name']}";
        }

        return $description;
    }

    /**
     * Синхронизировать позиции
     */
    protected function syncPositions(string $parentAccountId, string $childAccountId, array $positions): array
    {
        $syncedPositions = [];

        // Получить позиции если передан meta
        if (isset($positions['meta'])) {
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
