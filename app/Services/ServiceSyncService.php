<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use App\Models\AttributeMapping;
use App\Models\PriceTypeMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации услуг из главного в дочерние аккаунты
 */
class ServiceSyncService
{
    protected MoySkladService $moySkladService;
    protected CustomEntitySyncService $customEntitySyncService;

    public function __construct(
        MoySkladService $moySkladService,
        CustomEntitySyncService $customEntitySyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->customEntitySyncService = $customEntitySyncService;
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
            if (isset($service['attributes']) && is_array($service['attributes'])) {
                $attributesMetadata = $this->loadAttributesMetadata($mainAccountId);

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

        // Синхронизировать доп.поля
        if (isset($service['attributes'])) {
            $serviceData['attributes'] = $this->syncAttributes(
                $mainAccountId,
                $childAccountId,
                'service',
                $service['attributes']
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

        // Доп.поля
        if (isset($service['attributes'])) {
            $serviceData['attributes'] = $this->syncAttributes(
                $mainAccountId,
                $childAccountId,
                'service',
                $service['attributes']
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
     * Синхронизировать доп.поля (копия из ProductSyncService)
     */
    protected function syncAttributes(
        string $mainAccountId,
        string $childAccountId,
        string $entityType,
        array $attributes
    ): array {
        $syncedAttributes = [];

        // Получить настройки для фильтрации атрибутов
        $settings = SyncSetting::where('account_id', $childAccountId)->first();
        $attributeSyncList = $settings && $settings->attribute_sync_list ? $settings->attribute_sync_list : null;
        $useFilter = !empty($attributeSyncList) && is_array($attributeSyncList);

        foreach ($attributes as $attribute) {
            $attributeName = $attribute['name'] ?? null;
            $attributeType = $attribute['type'] ?? null;
            $attributeId = $attribute['id'] ?? null;

            if (!$attributeName || !$attributeType || !$attributeId) {
                continue;
            }

            // Если используется фильтр - проверить разрешен ли этот атрибут
            if ($useFilter && !in_array($attributeId, $attributeSyncList)) {
                continue;
            }

            $attributeMapping = AttributeMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('entity_type', $entityType)
                ->where('attribute_name', $attributeName)
                ->where('attribute_type', $attributeType)
                ->first();

            if (!$attributeMapping) {
                $attributeMapping = $this->createAttributeInChild(
                    $mainAccountId,
                    $childAccountId,
                    $entityType,
                    $attribute
                );
            }

            if (!$attributeMapping) {
                continue;
            }

            $value = $attribute['value'] ?? null;

            if ($attributeType === 'customentity' && $value) {
                $value = $this->customEntitySyncService->syncAttributeValue(
                    $mainAccountId,
                    $childAccountId,
                    $value
                );
            }

            $syncedAttributes[] = [
                'meta' => [
                    'href' => config('moysklad.api_url') . "/entity/{$entityType}/metadata/attributes/{$attributeMapping->child_attribute_id}",
                    'type' => 'attributemetadata',
                    'mediaType' => 'application/json'
                ],
                'value' => $value
            ];
        }

        return $syncedAttributes;
    }

    /**
     * Создать атрибут в дочернем аккаунте
     */
    protected function createAttributeInChild(
        string $mainAccountId,
        string $childAccountId,
        string $entityType,
        array $attribute
    ): ?AttributeMapping {
        try {
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            $attributeData = [
                'name' => $attribute['name'],
                'type' => $attribute['type'],
                'required' => $attribute['required'] ?? false,
            ];

            if ($attribute['type'] === 'customentity' && isset($attribute['customEntityMeta'])) {
                $customEntityName = $attribute['customEntityMeta']['name'] ?? null;
                if ($customEntityName) {
                    $syncedEntity = $this->customEntitySyncService->syncCustomEntity(
                        $mainAccountId,
                        $childAccountId,
                        $customEntityName
                    );
                    $attributeData['customEntityMeta'] = [
                        'href' => config('moysklad.api_url') . "/entity/customentity/{$syncedEntity['child_id']}",
                        'type' => 'customentity',
                        'mediaType' => 'application/json'
                    ];
                }
            }

            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->post("entity/{$entityType}/metadata/attributes", $attributeData);

            $newAttribute = $result['data'];

            $mapping = AttributeMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'parent_attribute_id' => $attribute['id'],
                'child_attribute_id' => $newAttribute['id'],
                'attribute_name' => $attribute['name'],
                'attribute_type' => $attribute['type'],
                'is_synced' => true,
                'auto_created' => true,
            ]);

            Log::info('Attribute created in child account for service', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'attribute_name' => $attribute['name']
            ]);

            return $mapping;

        } catch (\Exception $e) {
            Log::error('Failed to create attribute in child account for service', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'attribute' => $attribute,
                'error' => $e->getMessage()
            ]);
            return null;
        }
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

    /**
     * Кеш метаданных атрибутов (для избежания повторных API запросов)
     */
    protected array $attributesMetadataCache = [];

    /**
     * Загрузить метаданные атрибутов из главного аккаунта
     *
     * Метаданные содержат customEntityMeta для атрибутов типа customentity
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @return array Массив метаданных, индексированный по ID атрибута
     */
    protected function loadAttributesMetadata(string $mainAccountId): array
    {
        // Проверить кеш
        if (isset($this->attributesMetadataCache[$mainAccountId])) {
            return $this->attributesMetadataCache[$mainAccountId];
        }

        $mainAccount = Account::where('account_id', $mainAccountId)->first();
        if (!$mainAccount) {
            Log::warning('Main account not found for attributes metadata', [
                'main_account_id' => $mainAccountId
            ]);
            return [];
        }

        try {
            // Получить метаданные атрибутов (общие для товаров, услуг, комплектов)
            $response = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->get('entity/product/metadata/attributes');

            $metadata = [];
            foreach ($response['data']['rows'] ?? [] as $attr) {
                if (isset($attr['id'])) {
                    $metadata[$attr['id']] = $attr; // Индексировать по ID для O(1) поиска
                }
            }

            $this->attributesMetadataCache[$mainAccountId] = $metadata;

            Log::debug('Attributes metadata loaded and cached', [
                'main_account_id' => $mainAccountId,
                'count' => count($metadata)
            ]);

            return $metadata;

        } catch (\Exception $e) {
            Log::error('Failed to load attributes metadata', [
                'main_account_id' => $mainAccountId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
