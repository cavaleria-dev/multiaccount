<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use App\Services\Traits\SyncHelpers;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации услуг из главного в дочерние аккаунты
 *
 * Использует трейт SyncHelpers для общих методов (loadAttributesMetadata, syncAttributes, syncPrices)
 */
class ServiceSyncService
{
    use SyncHelpers;

    protected MoySkladService $moySkladService;
    protected CustomEntitySyncService $customEntitySyncService;
    protected StandardEntitySyncService $standardEntitySync;
    protected AttributeSyncService $attributeSyncService;

    public function __construct(
        MoySkladService $moySkladService,
        CustomEntitySyncService $customEntitySyncService,
        StandardEntitySyncService $standardEntitySync,
        AttributeSyncService $attributeSyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->customEntitySyncService = $customEntitySyncService;
        $this->standardEntitySync = $standardEntitySync;
        $this->attributeSyncService = $attributeSyncService;
    }

    /**
     * Синхронизировать услугу из главного в дочерний аккаунт
     */
    public function syncService(string $mainAccountId, string $childAccountId, string $serviceId): ?array
    {
        try {
            // Получить настройки синхронизации
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || !$settings->sync_services) {
                Log::debug('Service sync is disabled', ['child_account_id' => $childAccountId]);
                return null;
            }

            // Получить услугу из главного аккаунта
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $serviceResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->get("entity/service/{$serviceId}", ['expand' => 'attributes']);

            $service = $serviceResult['data'];

            // Проверить, что поле сопоставления заполнено
            $matchField = $settings->product_match_field ?? 'code';
            if (empty($service[$matchField])) {
                Log::warning('Service skipped: match field is empty', [
                    'service_id' => $serviceId,
                    'child_account_id' => $childAccountId,
                    'match_field' => $matchField,
                    'service_name' => $service['name'] ?? 'unknown'
                ]);
                return null;
            }

            // Смержить метаданные атрибутов с значениями (для customEntityMeta)
            // Используем AttributeSyncService для загрузки метаданных
            if (isset($service['attributes']) && is_array($service['attributes'])) {
                $attributesMetadata = $this->attributeSyncService->loadAttributesMetadata($mainAccountId, 'service');

                foreach ($service['attributes'] as &$attr) {
                    $attrId = $attr['id'] ?? null;
                    if ($attrId && isset($attributesMetadata[$attrId])) {
                        // Добавить customEntityMeta из метаданных (если есть)
                        if (isset($attributesMetadata[$attrId]['customEntityMeta'])) {
                            $attr['customEntityMeta'] = $attributesMetadata[$attrId]['customEntityMeta'];

                            Log::debug('Merged customEntityMeta for attribute', [
                                'attribute_id' => $attrId,
                                'attribute_name' => $attr['name'] ?? 'unknown',
                                'custom_entity_href' => $attributesMetadata[$attrId]['customEntityMeta']['href'] ?? 'unknown'
                            ]);
                        }
                    }
                }
                unset($attr); // Освободить ссылку
            }

            // Проверить маппинг
            $mapping = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_entity_id', $serviceId)
                ->where('entity_type', 'service')
                ->where('sync_direction', 'main_to_child')
                ->first();

            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            if ($mapping) {
                // Услуга уже существует, обновляем
                return $this->updateService($childAccount, $mainAccountId, $childAccountId, $service, $mapping, $settings);
            } else {
                // Создаем новую услугу
                return $this->createService($childAccount, $mainAccountId, $childAccountId, $service, $settings);
            }

        } catch (\Exception $e) {
            Log::error('Failed to sync service', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'service_id' => $serviceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Создать услугу в дочернем аккаунте
     */
    protected function createService(
        Account $childAccount,
        string $mainAccountId,
        string $childAccountId,
        array $service,
        SyncSetting $settings
    ): array {
        $serviceData = [
            'name' => $service['name'],
            'code' => $service['code'] ?? null,
            'externalCode' => $service['externalCode'] ?? null,
            'description' => $service['description'] ?? null,
        ];

        // Синхронизировать доп.поля (используя AttributeSyncService)
        if (isset($service['attributes'])) {
            $serviceData['attributes'] = $this->attributeSyncService->syncAttributes(
                sourceAccountId: $mainAccountId,
                targetAccountId: $childAccountId,
                settingsAccountId: $childAccountId,
                entityType: 'service',
                attributes: $service['attributes'],
                direction: 'main_to_child'
            );
        }

        // Синхронизировать цены
        $prices = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $service,
            $settings
        );
        $serviceData['salePrices'] = $prices['salePrices'];
        if (isset($prices['buyPrice'])) {
            $serviceData['buyPrice'] = $prices['buyPrice'];
        }

        // Создать услугу
        $newServiceResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->post('entity/service', $serviceData);

        $newService = $newServiceResult['data'];

        // Сохранить маппинг
        EntityMapping::create([
            'parent_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'entity_type' => 'service',
            'parent_entity_id' => $service['id'],
            'child_entity_id' => $newService['id'],
            'sync_direction' => 'main_to_child',
            'match_field' => $settings->product_match_field ?? 'code',
            'match_value' => $service[$settings->product_match_field ?? 'code'] ?? null,
        ]);

        Log::info('Service created in child account', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_service_id' => $service['id'],
            'child_service_id' => $newService['id']
        ]);

        return $newService;
    }

    /**
     * Обновить услугу в дочернем аккаунте
     */
    protected function updateService(
        Account $childAccount,
        string $mainAccountId,
        string $childAccountId,
        array $service,
        EntityMapping $mapping,
        SyncSetting $settings
    ): array {
        $serviceData = [
            'name' => $service['name'],
            'code' => $service['code'] ?? null,
            'externalCode' => $service['externalCode'] ?? null,
            'description' => $service['description'] ?? null,
        ];

        // Доп.поля (используя AttributeSyncService)
        if (isset($service['attributes'])) {
            $serviceData['attributes'] = $this->attributeSyncService->syncAttributes(
                sourceAccountId: $mainAccountId,
                targetAccountId: $childAccountId,
                settingsAccountId: $childAccountId,
                entityType: 'service',
                attributes: $service['attributes'],
                direction: 'main_to_child'
            );
        }

        // Цены
        $prices = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $service,
            $settings
        );
        $serviceData['salePrices'] = $prices['salePrices'];
        if (isset($prices['buyPrice'])) {
            $serviceData['buyPrice'] = $prices['buyPrice'];
        }

        // Обновить услугу
        $updatedServiceResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->put("entity/service/{$mapping->child_entity_id}", $serviceData);

        Log::info('Service updated in child account', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_service_id' => $service['id'],
            'child_service_id' => $mapping->child_entity_id
        ]);

        return $updatedServiceResult['data'];
    }

    /**
     * Синхронизировать цены включая закупочную
     */
    protected function syncPrices(
        string $mainAccountId,
        string $childAccountId,
        array $service,
        SyncSetting $settings
    ): array {
        $salePrices = [];
        $buyPrice = null;
        $mainSalePrices = $service['salePrices'] ?? [];
        $mainBuyPrice = $service['buyPrice'] ?? null;

        $priceMappings = $settings->price_mappings;
        $useMappings = !empty($priceMappings) && is_array($priceMappings);

        // Обработать закупочную цену (buyPrice)
        if ($mainBuyPrice && isset($mainBuyPrice['value'])) {
            $buyPriceMapped = false;

            if ($useMappings) {
                foreach ($priceMappings as $mapping) {
                    if (($mapping['main_price_type_id'] ?? null) === 'buyPrice') {
                        $childPriceTypeId = $mapping['child_price_type_id'] ?? null;

                        // buyPrice → buyPrice
                        if ($childPriceTypeId === 'buyPrice') {
                            $buyPrice = [
                                'value' => $mainBuyPrice['value'],
                                'currency' => [
                                    'meta' => [
                                        'href' => $mainBuyPrice['currency']['meta']['href'] ?? config('moysklad.api_url') . '/entity/currency/00000000-0000-0000-0000-000000000001',
                                        'type' => 'currency',
                                        'mediaType' => 'application/json'
                                    ]
                                ]
                            ];
                            $buyPriceMapped = true;
                        }
                        // buyPrice → salePrice (в определенный тип цены)
                        else {
                            $salePrices[] = [
                                'value' => $mainBuyPrice['value'],
                                'priceType' => [
                                    'meta' => [
                                        'href' => config('moysklad.api_url') . "/context/companysettings/pricetype/{$childPriceTypeId}",
                                        'type' => 'pricetype',
                                        'mediaType' => 'application/json'
                                    ]
                                ]
                            ];
                            $buyPriceMapped = true;
                        }
                        break;
                    }
                }
            }

            // Если маппинг не используется или buyPrice не замаплен - копировать как есть
            if (!$buyPriceMapped) {
                $buyPrice = [
                    'value' => $mainBuyPrice['value'],
                    'currency' => [
                        'meta' => [
                            'href' => $mainBuyPrice['currency']['meta']['href'] ?? config('moysklad.api_url') . '/entity/currency/00000000-0000-0000-0000-000000000001',
                            'type' => 'currency',
                            'mediaType' => 'application/json'
                        ]
                    ]
                ];
            }
        }

        // Обработать типы цен (salePrices)
        foreach ($mainSalePrices as $priceInfo) {
            $priceTypeHref = $priceInfo['priceType']['meta']['href'] ?? null;

            if (!$priceTypeHref) {
                continue;
            }

            $mainPriceTypeId = $this->extractEntityId($priceTypeHref);

            if (!$mainPriceTypeId) {
                continue;
            }

            $childPriceTypeId = null;

            if ($useMappings) {
                $allowed = false;
                foreach ($priceMappings as $mapping) {
                    if (($mapping['main_price_type_id'] ?? null) === $mainPriceTypeId) {
                        $childPriceTypeId = $mapping['child_price_type_id'] ?? null;
                        $allowed = true;
                        break;
                    }
                }

                if (!$allowed) {
                    continue;
                }

                // salePrice → buyPrice
                if ($childPriceTypeId === 'buyPrice') {
                    $buyPrice = [
                        'value' => $priceInfo['value'] ?? 0,
                        'currency' => [
                            'meta' => [
                                'href' => config('moysklad.api_url') . '/entity/currency/00000000-0000-0000-0000-000000000001',
                                'type' => 'currency',
                                'mediaType' => 'application/json'
                            ]
                        ]
                    ];
                    continue;
                }
            }

            if ($childPriceTypeId) {
                $salePrices[] = [
                    'value' => $priceInfo['value'] ?? 0,
                    'priceType' => [
                        'meta' => [
                            'href' => config('moysklad.api_url') . "/context/companysettings/pricetype/{$childPriceTypeId}",
                            'type' => 'pricetype',
                            'mediaType' => 'application/json'
                        ]
                    ]
                ];
            } else {
                $priceTypeName = $priceInfo['priceType']['name'] ?? null;

                if (!$priceTypeName) {
                    continue;
                }

                $priceTypeMapping = $this->getOrCreatePriceType($mainAccountId, $childAccountId, $priceTypeName);

                if ($priceTypeMapping) {
                    $salePrices[] = [
                        'value' => $priceInfo['value'] ?? 0,
                        'priceType' => [
                            'meta' => [
                                'href' => config('moysklad.api_url') . "/context/companysettings/pricetype/{$priceTypeMapping->child_price_type_id}",
                                'type' => 'pricetype',
                                'mediaType' => 'application/json'
                            ]
                        ]
                    ];
                }
            }
        }

        $result = ['salePrices' => $salePrices];
        if ($buyPrice !== null) {
            $result['buyPrice'] = $buyPrice;
        }

        return $result;
    }

    /**
     * Получить или создать тип цены
     */
    protected function getOrCreatePriceType(string $mainAccountId, string $childAccountId, string $priceTypeName): ?PriceTypeMapping
    {
        $mapping = PriceTypeMapping::where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $childAccountId)
            ->where('price_type_name', $priceTypeName)
            ->first();

        if ($mapping) {
            return $mapping;
        }

        $settings = SyncSetting::where('account_id', $childAccountId)->first();

        if (!$settings || !$settings->auto_create_price_types) {
            return null;
        }

        try {
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();

            $mainPriceTypesResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->get('context/companysettings');

            $mainPriceTypes = $mainPriceTypesResult['data']['priceTypes'] ?? [];
            $mainPriceType = null;

            foreach ($mainPriceTypes as $pt) {
                if ($pt['name'] === $priceTypeName) {
                    $mainPriceType = $pt;
                    break;
                }
            }

            if (!$mainPriceType) {
                return null;
            }

            $childPriceTypesResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->post('context/companysettings/pricetype', ['name' => $priceTypeName]);

            $childPriceType = $childPriceTypesResult['data'];

            $mapping = PriceTypeMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'parent_price_type_id' => $mainPriceType['id'],
                'child_price_type_id' => $childPriceType['id'],
                'price_type_name' => $priceTypeName,
                'auto_created' => true,
            ]);

            Log::info('Price type created in child account for service', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'price_type_name' => $priceTypeName
            ]);

            return $mapping;

        } catch (\Exception $e) {
            Log::error('Failed to create price type in child account for service', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'price_type_name' => $priceTypeName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Извлечь ID сущности из href
     */
    protected function extractEntityId(string $href): ?string
    {
        if (empty($href)) {
            return null;
        }

        $parts = explode('/', $href);
        return end($parts) ?: null;
    }

    /**
     * Архивировать услугу в дочерних аккаунтах
     */
    public function archiveService(string $mainAccountId, string $serviceId): int
    {
        try {
            $mappings = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('parent_entity_id', $serviceId)
                ->where('entity_type', 'service')
                ->where('sync_direction', 'main_to_child')
                ->get();

            if ($mappings->isEmpty()) {
                Log::debug('No mappings found for service archive', [
                    'main_account_id' => $mainAccountId,
                    'service_id' => $serviceId
                ]);
                return 0;
            }

            $archivedCount = 0;

            foreach ($mappings as $mapping) {
                try {
                    $childAccount = Account::where('account_id', $mapping->child_account_id)->first();

                    if (!$childAccount) {
                        Log::warning('Child account not found for service archive', [
                            'child_account_id' => $mapping->child_account_id
                        ]);
                        continue;
                    }

                    $this->moySkladService
                        ->setAccessToken($childAccount->access_token)
                        ->put("entity/service/{$mapping->child_entity_id}", [
                            'archived' => true
                        ]);

                    $archivedCount++;

                    Log::info('Service archived in child account', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $mapping->child_account_id,
                        'main_service_id' => $serviceId,
                        'child_service_id' => $mapping->child_entity_id
                    ]);

                } catch (\Exception $e) {
                    Log::error('Failed to archive service in child account', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $mapping->child_account_id,
                        'service_id' => $serviceId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $archivedCount;

        } catch (\Exception $e) {
            Log::error('Failed to archive service', [
                'main_account_id' => $mainAccountId,
                'service_id' => $serviceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

}
