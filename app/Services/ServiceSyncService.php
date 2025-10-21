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
    protected ProductSyncService $productSyncService;
    protected ProductFilterService $productFilterService;

    public function __construct(
        MoySkladService $moySkladService,
        CustomEntitySyncService $customEntitySyncService,
        StandardEntitySyncService $standardEntitySync,
        AttributeSyncService $attributeSyncService,
        ProductSyncService $productSyncService,
        ProductFilterService $productFilterService
    ) {
        $this->moySkladService = $moySkladService;
        $this->customEntitySyncService = $customEntitySyncService;
        $this->standardEntitySync = $standardEntitySync;
        $this->attributeSyncService = $attributeSyncService;
        $this->productSyncService = $productSyncService;
        $this->productFilterService = $productFilterService;
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
            $matchField = $settings->service_match_field ?? 'code';
            if ($matchField === 'name') {
                // name - обязательное поле, всегда заполнено
                if (empty($service['name'])) {
                    Log::warning('Service has empty name (required field!)', [
                        'service_id' => $serviceId,
                        'child_account_id' => $childAccountId
                    ]);
                    return null;
                }
            } else {
                // code, externalCode, barcode (no article field for services!)
                if (empty($service[$matchField])) {
                    Log::warning('Service skipped: match field is empty', [
                        'service_id' => $serviceId,
                        'child_account_id' => $childAccountId,
                        'match_field' => $matchField,
                        'service_name' => $service['name'] ?? 'unknown'
                    ]);
                    return null;
                }
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

            // Проверить фильтры (используя трейт SyncHelpers)
            if (!$this->passesFilters($service, $settings, $mainAccountId)) {
                Log::debug('Service does not pass filters', [
                    'service_id' => $serviceId,
                    'child_account_id' => $childAccountId
                ]);
                return null;
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

        // Добавить НДС и налогообложение
        $serviceData = $this->productSyncService->addVatAndTaxFields($serviceData, $service, $settings);

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
            'match_field' => $settings->service_match_field ?? 'code',
            'match_value' => $service[$settings->service_match_field ?? 'code'] ?? null,
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

        // Добавить НДС и налогообложение
        $serviceData = $this->productSyncService->addVatAndTaxFields($serviceData, $service, $settings);

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
     * Подготовить услугу для batch создания/обновления
     *
     * Использует предварительно закешированные mappings для зависимостей (БЕЗ GET запросов).
     * Вызывается из ProcessSyncQueueJob::processBatchServiceSync() после пре-кеширования.
     *
     * @param array $service Услуга из main аккаунта (с expand)
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param SyncSetting $settings Настройки синхронизации
     * @return array|null Подготовленная услуга для batch POST или null если skip
     */
    public function prepareServiceForBatch(
        array $service,
        string $mainAccountId,
        string $childAccountId,
        SyncSetting $settings
    ): ?array {
        // 1. Проверить фильтры
        if (!$this->passesFilters($service, $settings, $mainAccountId)) {
            Log::debug('Service filtered out in batch', ['service_id' => $service['id']]);
            return null;
        }

        // 2. Проверить, что поле сопоставления заполнено
        $matchField = $settings->service_match_field ?? 'code';
        if ($matchField === 'name') {
            if (empty($service['name'])) {
                Log::warning('Service has empty name (required field!)', [
                    'service_id' => $service['id']
                ]);
                return null;
            }
        } else {
            // code, externalCode, barcode (no article field for services!)
            if (empty($service[$matchField])) {
                Log::debug('Service skipped in batch: match field is empty', [
                    'service_id' => $service['id'],
                    'match_field' => $matchField,
                    'service_name' => $service['name'] ?? 'unknown'
                ]);
                return null;
            }
        }

        // 3. Проверить mapping (create or update?)
        $mapping = EntityMapping::where([
            'parent_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'entity_type' => 'service',
            'parent_entity_id' => $service['id']
        ])->first();

        // 4. Build base service data
        $serviceData = [
            'name' => $service['name'],
            'code' => $service['code'] ?? null,
            'externalCode' => $service['externalCode'] ?? null,
            'description' => $service['description'] ?? null,
        ];

        // 5. Если обновление - добавить meta
        if ($mapping) {
            $serviceData['meta'] = [
                'href' => config('moysklad.api_url') . "/entity/service/{$mapping->child_entity_id}",
                'type' => 'service',
                'mediaType' => 'application/json'
            ];
        }

        // 6. Sync Attributes (using cached mappings from DB)
        if (isset($service['attributes']) && !empty($service['attributes'])) {
            $syncedAttributes = [];

            foreach ($service['attributes'] as $attribute) {
                $attributeId = $attribute['id'] ?? null;
                $attributeName = $attribute['name'] ?? null;

                if (!$attributeId || !$attributeName) {
                    continue;
                }

                // Найти CACHED маппинг
                $mapping = \App\Models\AttributeMapping::where([
                    'parent_account_id' => $mainAccountId,
                    'child_account_id' => $childAccountId,
                    'entity_type' => 'service',
                    'parent_attribute_id' => $attributeId
                ])->first();

                if (!$mapping) {
                    continue;
                }

                // Подготовить значение
                $value = $attribute['value'] ?? null;

                // Для customentity - синхронизировать значение (элемент справочника)
                if ($attribute['type'] === 'customentity' && $value) {
                    $value = $this->customEntitySyncService->syncAttributeValue(
                        $mainAccountId,
                        $childAccountId,
                        $value
                    );
                }

                $syncedAttributes[] = [
                    'meta' => [
                        // МойСклад API: для service используем endpoint product/metadata/attributes
                        'href' => config('moysklad.api_url') . "/entity/product/metadata/attributes/{$mapping->child_attribute_id}",
                        'type' => 'attributemetadata',
                        'mediaType' => 'application/json'
                    ],
                    'value' => $value
                ];
            }

            if (!empty($syncedAttributes)) {
                $serviceData['attributes'] = $syncedAttributes;
            }
        }

        // 7. Sync Prices (using existing trait method)
        $prices = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $service,
            $settings
        );

        if (!empty($prices['salePrices'])) {
            $serviceData['salePrices'] = $prices['salePrices'];
        }

        if (isset($prices['buyPrice'])) {
            $serviceData['buyPrice'] = $prices['buyPrice'];
        }

        // 8. Add VAT and tax fields (using ProductSyncService method)
        $serviceData = $this->productSyncService->addVatAndTaxFields($serviceData, $service, $settings);

        // 9. Store original ID for mapping after batch POST
        $serviceData['_original_id'] = $service['id'];
        $serviceData['_is_update'] = $mapping ? true : false;
        $serviceData['_child_entity_id'] = $mapping ? $mapping->child_entity_id : null;

        return $serviceData;
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
