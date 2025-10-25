<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use App\Models\StandardEntityMapping;
use App\Models\AttributeMapping;
use App\Models\CustomEntityMapping;
use App\Models\CustomEntityElementMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для пре-кеширования зависимостей перед batch синхронизацией
 *
 * Загружает все необходимые справочники и создаёт маппинги ОДИН РАЗ,
 * чтобы batch sync мог использовать cached данные без GET запросов
 *
 * Кеширует:
 * - UOM (единицы измерения)
 * - Country (страны)
 * - ProductFolder (группы товаров)
 * - Attributes (доп.поля)
 * - CustomEntity (справочники)
 * - CustomEntity Elements (элементы справочников)
 */
class DependencyCacheService
{
    protected MoySkladService $moySkladService;
    protected StandardEntitySyncService $standardEntitySync;
    protected ProductFolderSyncService $productFolderSync;
    protected AttributeSyncService $attributeSyncService;
    protected CustomEntityService $customEntityService;
    protected CustomEntitySyncService $customEntitySyncService;

    public function __construct(
        MoySkladService $moySkladService,
        StandardEntitySyncService $standardEntitySync,
        ProductFolderSyncService $productFolderSync,
        AttributeSyncService $attributeSyncService,
        CustomEntityService $customEntityService,
        CustomEntitySyncService $customEntitySyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->standardEntitySync = $standardEntitySync;
        $this->productFolderSync = $productFolderSync;
        $this->attributeSyncService = $attributeSyncService;
        $this->customEntityService = $customEntityService;
        $this->customEntitySyncService = $customEntitySyncService;
    }

    /**
     * Пре-кеш всех зависимостей для batch синхронизации
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param SyncSetting $settings Настройки синхронизации для проверки включенных типов
     */
    public function cacheAll(string $mainAccountId, string $childAccountId, SyncSetting $settings): void
    {
        Log::info('Starting dependency pre-cache', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId
        ]);

        $startTime = microtime(true);

        try {
            // 1. Стандартные сущности (UOM, Country)
            $this->cacheStandardEntities($mainAccountId, $childAccountId);

            // 2. Папки товаров - УДАЛЕНО
            // Группы теперь синхронизируются в BatchEntityLoader::loadAndCreateAssortmentBatchTasks()
            // после фильтрации (только нужные группы)
            // $this->cacheProductFolders($mainAccountId, $childAccountId);

            // 3. Атрибуты для товаров (включая справочники)
            $this->cacheAttributes($mainAccountId, $childAccountId, 'product');

            // 4. Атрибуты для услуг (если включены)
            if ($settings->sync_services ?? false) {
                $this->cacheAttributes($mainAccountId, $childAccountId, 'service');
            }

            // 5. Атрибуты для комплектов (если включены)
            if ($settings->sync_bundles) {
                $this->cacheAttributes($mainAccountId, $childAccountId, 'bundle');
            }

            // 6. Элементы справочников (ВАЖНО: после cacheAttributes!)
            $this->cacheCustomEntityElements($mainAccountId, $childAccountId);

            $duration = round((microtime(true) - $startTime) * 1000); // ms

            Log::info('Dependency pre-cache completed', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'duration_ms' => $duration
            ]);

        } catch (\Exception $e) {
            Log::error('Dependency pre-cache failed', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Пре-кеш стандартных сущностей (UOM, Country)
     *
     * Загружает все UOM и Country из main и child аккаунтов,
     * создаёт маппинги в БД по code/isoCode
     */
    protected function cacheStandardEntities(string $mainAccountId, string $childAccountId): void
    {
        Log::info('Caching standard entities (UOM, Country)');

        $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
        $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

        // Cache UOM
        $this->cacheUom($mainAccount, $childAccount, $mainAccountId, $childAccountId);

        // Cache Country
        $this->cacheCountry($mainAccount, $childAccount, $mainAccountId, $childAccountId);

        Log::info('Standard entities cached');
    }

    /**
     * Пре-кеш UOM (единицы измерения)
     */
    protected function cacheUom(
        Account $mainAccount,
        Account $childAccount,
        string $mainAccountId,
        string $childAccountId
    ): void {
        // Загрузить все UOM из main
        $mainResponse = $this->moySkladService
            ->setAccessToken($mainAccount->access_token)
            ->setLogContext(
                accountId: $mainAccountId,
                direction: 'internal',
                relatedAccountId: null,
                entityType: 'uom',
                entityId: null
            )
            ->get('/entity/uom', ['limit' => 1000]);

        $mainUoms = $mainResponse['data']['rows'] ?? [];

        // Загрузить все UOM из child
        $childResponse = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->setLogContext(
                accountId: $childAccountId,
                direction: 'internal',
                relatedAccountId: null,
                entityType: 'uom',
                entityId: null
            )
            ->get('/entity/uom', ['limit' => 1000]);

        $childUoms = $childResponse['data']['rows'] ?? [];

        Log::info('Loaded UOM', [
            'main_count' => count($mainUoms),
            'child_count' => count($childUoms)
        ]);

        // Индексировать child UOM по code
        $childUomsByCode = [];
        foreach ($childUoms as $uom) {
            if (isset($uom['code'])) {
                $childUomsByCode[$uom['code']] = $uom;
            }
        }

        // Создать маппинги
        $created = 0;
        foreach ($mainUoms as $mainUom) {
            $code = $mainUom['code'] ?? null;
            if (!$code) {
                continue;
            }

            // Проверить существующий маппинг
            $exists = StandardEntityMapping::where([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'uom',
                'code' => $code
            ])->exists();

            if ($exists) {
                continue;
            }

            // Найти соответствующий UOM в child
            $childUom = $childUomsByCode[$code] ?? null;
            if (!$childUom) {
                Log::warning('UOM not found in child', ['code' => $code, 'name' => $mainUom['name']]);
                continue;
            }

            // Создать маппинг
            StandardEntityMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'uom',
                'parent_entity_id' => $mainUom['id'],
                'child_entity_id' => $childUom['id'],
                'code' => $code,
                'name' => $mainUom['name']
            ]);

            $created++;
        }

        Log::info('UOM mappings created', ['count' => $created]);
    }

    /**
     * Пре-кеш Country (страны)
     */
    protected function cacheCountry(
        Account $mainAccount,
        Account $childAccount,
        string $mainAccountId,
        string $childAccountId
    ): void {
        // Загрузить все Country из main
        $mainResponse = $this->moySkladService
            ->setAccessToken($mainAccount->access_token)
            ->setLogContext(
                accountId: $mainAccountId,
                direction: 'internal',
                relatedAccountId: null,
                entityType: 'country',
                entityId: null
            )
            ->get('/entity/country', ['limit' => 1000]);

        $mainCountries = $mainResponse['data']['rows'] ?? [];

        // Загрузить все Country из child
        $childResponse = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->setLogContext(
                accountId: $childAccountId,
                direction: 'internal',
                relatedAccountId: null,
                entityType: 'country',
                entityId: null
            )
            ->get('/entity/country', ['limit' => 1000]);

        $childCountries = $childResponse['data']['rows'] ?? [];

        Log::info('Loaded Country', [
            'main_count' => count($mainCountries),
            'child_count' => count($childCountries)
        ]);

        // Индексировать child Country по code
        $childCountriesByCode = [];
        foreach ($childCountries as $country) {
            if (isset($country['code'])) {
                $childCountriesByCode[$country['code']] = $country;
            }
        }

        // Создать маппинги
        $created = 0;
        foreach ($mainCountries as $mainCountry) {
            $code = $mainCountry['code'] ?? null;
            if (!$code) {
                continue;
            }

            // Проверить существующий маппинг
            $exists = StandardEntityMapping::where([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'country',
                'code' => $code
            ])->exists();

            if ($exists) {
                continue;
            }

            // Найти соответствующую Country в child
            $childCountry = $childCountriesByCode[$code] ?? null;
            if (!$childCountry) {
                Log::warning('Country not found in child', ['code' => $code, 'name' => $mainCountry['name']]);
                continue;
            }

            // Создать маппинг
            StandardEntityMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'country',
                'parent_entity_id' => $mainCountry['id'],
                'child_entity_id' => $childCountry['id'],
                'code' => $code,
                'name' => $mainCountry['name']
            ]);

            $created++;
        }

        Log::info('Country mappings created', ['count' => $created]);
    }

    /**
     * Пре-кеш папок товаров (рекурсивная синхронизация)
     */
    protected function cacheProductFolders(string $mainAccountId, string $childAccountId): void
    {
        Log::info('Caching product folders');

        $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();

        // Загрузить все папки из main
        $response = $this->moySkladService
            ->setAccessToken($mainAccount->access_token)
            ->setLogContext(
                accountId: $mainAccountId,
                direction: 'main_to_child',
                relatedAccountId: $childAccountId,
                entityType: 'productfolder',
                entityId: null
            )
            ->get('/entity/productfolder', ['limit' => 1000]);

        $folders = $response['data']['rows'] ?? [];

        Log::info('Loaded product folders', ['count' => count($folders)]);

        // Синхронизировать каждую папку (рекурсивно)
        $synced = 0;
        foreach ($folders as $folder) {
            try {
                $this->productFolderSync->syncProductFolder(
                    $mainAccountId,
                    $childAccountId,
                    $folder['id']
                );
                $synced++;
            } catch (\Exception $e) {
                Log::warning('Failed to sync product folder', [
                    'folder_id' => $folder['id'],
                    'folder_name' => $folder['name'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('Product folders cached', ['synced' => $synced]);
    }

    /**
     * Пре-кеш атрибутов (доп.полей) включая справочники
     *
     * Загружает метаданные атрибутов из main и child,
     * создаёт недостающие атрибуты в child,
     * для customentity атрибутов синхронизирует справочники
     */
    protected function cacheAttributes(
        string $mainAccountId,
        string $childAccountId,
        string $entityType
    ): void {
        Log::info('Caching attributes', ['entity_type' => $entityType]);

        // Получить настройки (фильтр атрибутов)
        $settings = SyncSetting::where('account_id', $childAccountId)->first();
        $attributeSyncList = $settings && $settings->attribute_sync_list ? $settings->attribute_sync_list : [];

        if (empty($attributeSyncList) || !is_array($attributeSyncList)) {
            Log::info('Attribute sync disabled or empty list');
            return;
        }

        $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
        $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

        // МойСклад API: для product/service/bundle используем единый endpoint
        $metadataEntityType = in_array($entityType, ['product', 'service', 'bundle'])
            ? 'product'
            : $entityType;

        // Загрузить метаданные атрибутов из MAIN
        $mainResponse = $this->moySkladService
            ->setAccessToken($mainAccount->access_token)
            ->setLogContext(
                accountId: $mainAccountId,
                direction: 'main_to_child',
                relatedAccountId: $childAccountId,
                entityType: $entityType . '_metadata',
                entityId: null
            )
            ->get("/entity/{$metadataEntityType}/metadata/attributes", ['limit' => 1000]);

        $mainAttributes = $mainResponse['data']['rows'] ?? [];

        // Фильтр: только разрешенные атрибуты
        $mainAttributes = array_filter($mainAttributes, function($attr) use ($attributeSyncList) {
            return in_array($attr['id'] ?? null, $attributeSyncList);
        });

        // Загрузить метаданные атрибутов из CHILD
        $childResponse = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->setLogContext(
                accountId: $childAccountId,
                direction: 'main_to_child',
                relatedAccountId: $mainAccountId,
                entityType: $entityType . '_metadata',
                entityId: null
            )
            ->get("/entity/{$metadataEntityType}/metadata/attributes", ['limit' => 1000]);

        $childAttributes = $childResponse['data']['rows'] ?? [];

        // Индексировать по имени
        $childAttributesByName = [];
        foreach ($childAttributes as $attr) {
            $childAttributesByName[$attr['name']] = $attr;
        }

        Log::info('Loaded attributes', [
            'main_total' => count($mainAttributes),
            'child_total' => count($childAttributes)
        ]);

        // Для каждого main атрибута: найти или создать в child
        $created = 0;
        foreach ($mainAttributes as $mainAttr) {
            $attrName = $mainAttr['name'];
            $attrType = $mainAttr['type'];

            // Проверить существующий маппинг
            $existingMapping = AttributeMapping::where([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'attribute_name' => $attrName
            ])->first();

            if ($existingMapping) {
                continue;
            }

            // Найти в child по имени
            $childAttr = $childAttributesByName[$attrName] ?? null;

            if (!$childAttr) {
                // СОЗДАТЬ атрибут в child
                Log::info('Creating attribute in child', [
                    'name' => $attrName,
                    'type' => $attrType
                ]);

                $attributeData = [
                    'name' => $attrName,
                    'type' => $attrType,
                    'required' => $mainAttr['required'] ?? false,
                ];

                // Если customentity - синхронизировать справочник
                if ($attrType === 'customentity') {
                    $customEntityName = $mainAttr['customEntityMeta']['name'] ?? null;

                    if (!$customEntityName && isset($mainAttr['customEntityMeta']['href'])) {
                        $customEntityId = $this->extractEntityId($mainAttr['customEntityMeta']['href']);
                        if ($customEntityId) {
                            $metadata = $this->attributeSyncService->loadCustomEntityMetadataById($mainAccountId, $customEntityId);
                            $customEntityName = $metadata['name'] ?? null;
                        }
                    }

                    if ($customEntityName) {
                        // Синхронизировать справочник
                        $syncedEntity = $this->customEntitySyncService->syncCustomEntity(
                            $mainAccountId,
                            $childAccountId,
                            $customEntityName
                        );

                        $attributeData['customEntityMeta'] = [
                            'href' => config('moysklad.api_url') . "/context/companysettings/metadata/customEntities/{$syncedEntity['child_id']}",
                            'type' => 'customentitymetadata',
                            'mediaType' => 'application/json'
                        ];
                    } else {
                        Log::warning('Cannot extract customEntityName, skipping attribute', [
                            'attribute_name' => $attrName
                        ]);
                        continue;
                    }
                }

                // POST создать атрибут
                try {
                    $result = $this->moySkladService
                        ->setAccessToken($childAccount->access_token)
                        ->setLogContext(
                            accountId: $childAccountId,
                            direction: 'main_to_child',
                            relatedAccountId: $mainAccountId,
                            entityType: $entityType . '_metadata',
                            entityId: null
                        )
                        ->post("/entity/{$metadataEntityType}/metadata/attributes", $attributeData);

                    $childAttr = $result['data'];
                } catch (\Exception $e) {
                    Log::error('Failed to create attribute in child', [
                        'attribute_name' => $attrName,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }

            // Сохранить маппинг
            AttributeMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'parent_attribute_id' => $mainAttr['id'],
                'child_attribute_id' => $childAttr['id'],
                'attribute_name' => $attrName,
                'attribute_type' => $attrType,
                'is_synced' => true,
                'auto_created' => true,
            ]);

            $created++;
        }

        Log::info('Attributes cached', [
            'entity_type' => $entityType,
            'created' => $created
        ]);
    }

    /**
     * Пре-кеш элементов пользовательских справочников
     *
     * Загружает ВСЕ элементы всех customentity справочников,
     * создаёт недостающие элементы в child,
     * сохраняет маппинги
     */
    protected function cacheCustomEntityElements(string $mainAccountId, string $childAccountId): void
    {
        Log::info('Caching custom entity elements');

        // Получить все маппинги справочников
        $entityMappings = CustomEntityMapping::where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $childAccountId)
            ->get();

        if ($entityMappings->isEmpty()) {
            Log::info('No custom entities to cache');
            return;
        }

        Log::info('Found custom entities to cache', ['count' => $entityMappings->count()]);

        // Для каждого справочника
        foreach ($entityMappings as $entityMapping) {
            $parentCustomEntityId = $entityMapping->parent_custom_entity_id;
            $childCustomEntityId = $entityMapping->child_custom_entity_id;

            Log::info('Caching elements for custom entity', [
                'name' => $entityMapping->custom_entity_name,
                'parent_id' => $parentCustomEntityId,
                'child_id' => $childCustomEntityId
            ]);

            try {
                // Загрузить ВСЕ элементы из PARENT
                $parentElements = $this->customEntityService->getCustomEntityElements(
                    $mainAccountId,
                    $parentCustomEntityId
                );

                // Загрузить ВСЕ элементы из CHILD
                $childElements = $this->customEntityService->getCustomEntityElements(
                    $childAccountId,
                    $childCustomEntityId
                );

                // Индексировать по имени
                $childElementsByName = [];
                foreach ($childElements as $element) {
                    $childElementsByName[$element['name']] = $element;
                }

                Log::info('Loaded elements', [
                    'parent_count' => count($parentElements),
                    'child_count' => count($childElements)
                ]);

                // Для каждого parent элемента: найти или создать в child
                $created = 0;
                foreach ($parentElements as $parentElement) {
                    $elementName = $parentElement['name'];
                    $parentElementId = $parentElement['id'];

                    // Проверить существующий маппинг
                    $existingMapping = CustomEntityElementMapping::where([
                        'parent_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'parent_custom_entity_id' => $parentCustomEntityId,
                        'parent_element_id' => $parentElementId
                    ])->exists();

                    if ($existingMapping) {
                        continue;
                    }

                    // Найти в child по имени
                    $childElement = $childElementsByName[$elementName] ?? null;

                    if (!$childElement) {
                        // СОЗДАТЬ элемент в child
                        Log::debug('Creating element in child', [
                            'custom_entity' => $entityMapping->custom_entity_name,
                            'element_name' => $elementName
                        ]);

                        try {
                            $childElement = $this->customEntityService->getOrCreateElement(
                                $childAccountId,
                                $childCustomEntityId,
                                $elementName
                            );
                        } catch (\Exception $e) {
                            Log::error('Failed to create element in child', [
                                'custom_entity' => $entityMapping->custom_entity_name,
                                'element_name' => $elementName,
                                'error' => $e->getMessage()
                            ]);
                            continue;
                        }
                    }

                    // Сохранить маппинг
                    CustomEntityElementMapping::create([
                        'parent_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'parent_custom_entity_id' => $parentCustomEntityId,
                        'child_custom_entity_id' => $childCustomEntityId,
                        'parent_element_id' => $parentElementId,
                        'child_element_id' => $childElement['id'],
                        'element_name' => $elementName,
                        'auto_created' => true,
                    ]);

                    $created++;
                }

                Log::info('Elements cached for custom entity', [
                    'name' => $entityMapping->custom_entity_name,
                    'created' => $created
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to cache elements for custom entity', [
                    'name' => $entityMapping->custom_entity_name,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('Custom entity elements cache completed');
    }

    /**
     * Извлечь ID сущности из href
     */
    protected function extractEntityId(string $href): ?string
    {
        if (empty($href)) {
            return null;
        }

        // Удалить query string если есть (?expand=..., ?filter=..., etc)
        $href = strtok($href, '?');

        $parts = explode('/', $href);
        return end($parts) ?: null;
    }
}
