<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use App\Services\Traits\SyncHelpers;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации комплектов (bundles)
 *
 * Комплекты зависят от товаров и модификаций (компоненты)
 */
class BundleSyncService
{
    use SyncHelpers;

    protected MoySkladService $moySkladService;
    protected CustomEntitySyncService $customEntitySyncService;
    protected StandardEntitySyncService $standardEntitySync;
    protected ProductFilterService $productFilterService;
    protected ProductSyncService $productSyncService;
    protected VariantSyncService $variantSyncService;
    protected AttributeSyncService $attributeSyncService;
    protected ProductFolderSyncService $productFolderSyncService;
    protected EntityMappingService $entityMappingService;

    public function __construct(
        MoySkladService $moySkladService,
        CustomEntitySyncService $customEntitySyncService,
        StandardEntitySyncService $standardEntitySync,
        ProductFilterService $productFilterService,
        ProductSyncService $productSyncService,
        VariantSyncService $variantSyncService,
        AttributeSyncService $attributeSyncService,
        ProductFolderSyncService $productFolderSyncService,
        EntityMappingService $entityMappingService
    ) {
        $this->moySkladService = $moySkladService;
        $this->customEntitySyncService = $customEntitySyncService;
        $this->standardEntitySync = $standardEntitySync;
        $this->productFilterService = $productFilterService;
        $this->productSyncService = $productSyncService;
        $this->variantSyncService = $variantSyncService;
        $this->attributeSyncService = $attributeSyncService;
        $this->productFolderSyncService = $productFolderSyncService;
        $this->entityMappingService = $entityMappingService;
    }

    /**
     * Синхронизировать комплект из главного в дочерний аккаунт
     */
    public function syncBundle(string $mainAccountId, string $childAccountId, string $bundleId): ?array
    {
        try {
            // Получить настройки синхронизации
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || !$settings->sync_bundles) {
                Log::debug('Bundle sync is disabled', ['child_account_id' => $childAccountId]);
                return null;
            }

            // Получить комплект из главного аккаунта
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $bundleResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->setLogContext(
                    accountId: $mainAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $childAccountId,
                    entityType: 'bundle',
                    entityId: $bundleId
                )
                ->get("entity/bundle/{$bundleId}", ['expand' => 'components.assortment,images']);

            $bundle = $bundleResult['data'];

            // Проверить, что поле сопоставления заполнено
            $matchField = $settings->product_match_field ?? 'article';
            if (empty($bundle[$matchField])) {
                Log::warning('Bundle skipped: match field is empty', [
                    'bundle_id' => $bundleId,
                    'child_account_id' => $childAccountId,
                    'match_field' => $matchField,
                    'bundle_name' => $bundle['name'] ?? 'unknown'
                ]);
                return null;
            }

            // Смержить метаданные атрибутов с значениями (для customEntityMeta)
            // Используем AttributeSyncService для загрузки метаданных
            if (isset($bundle['attributes']) && is_array($bundle['attributes'])) {
                $attributesMetadata = $this->attributeSyncService->loadAttributesMetadata($mainAccountId, 'bundle');

                foreach ($bundle['attributes'] as &$attr) {
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
            if (!$this->passesFilters($bundle, $settings, $mainAccountId)) {
                Log::debug('Bundle does not pass filters', [
                    'bundle_id' => $bundleId,
                    'child_account_id' => $childAccountId
                ]);
                return null;
            }

            // Проверить маппинг
            $mapping = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_entity_id', $bundleId)
                ->where('entity_type', 'bundle')
                ->where('sync_direction', 'main_to_child')
                ->first();

            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            $result = null;
            if ($mapping) {
                // Комплект уже существует, обновляем
                $result = $this->updateBundle($childAccount, $mainAccountId, $childAccountId, $bundle, $mapping, $settings);
            } else {
                // Создаем новый комплект
                $result = $this->createBundle($childAccount, $mainAccountId, $childAccountId, $bundle, $settings);
            }

            // Синхронизировать изображения (если включено)
            if ($result && $settings->sync_images && isset($bundle['images']['rows']) && !empty($bundle['images']['rows'])) {
                $this->queueImageSync($mainAccountId, $childAccountId, 'bundle', $bundleId, $result['id'], $bundle['images']['rows'], $settings);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to sync bundle', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'bundle_id' => $bundleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Создать комплект в дочернем аккаунте
     */
    protected function createBundle(
        Account $childAccount,
        string $mainAccountId,
        string $childAccountId,
        array $bundle,
        SyncSetting $settings
    ): array {
        $bundleData = [
            'name' => $bundle['name'],
            'article' => $bundle['article'] ?? null,
            'code' => $bundle['code'] ?? null,
            'externalCode' => $bundle['externalCode'] ?? null,
            'description' => $bundle['description'] ?? null,
        ];

        // Добавить штрихкоды
        if (isset($bundle['barcodes'])) {
            $bundleData['barcodes'] = $bundle['barcodes'];
        }

        // Синхронизировать доп.поля (используя AttributeSyncService)
        if (isset($bundle['attributes'])) {
            $bundleData['attributes'] = $this->attributeSyncService->syncAttributes(
                sourceAccountId: $mainAccountId,
                targetAccountId: $childAccountId,
                settingsAccountId: $childAccountId,
                entityType: 'bundle',
                attributes: $bundle['attributes'],
                direction: 'main_to_child'
            );
        }

        // Синхронизировать группу товара (ProductFolder)
        if ($settings->create_product_folders && isset($bundle['productFolder'])) {
            $folderHref = $bundle['productFolder']['meta']['href'] ?? null;
            if ($folderHref) {
                $folderId = $this->extractEntityId($folderHref);
                if ($folderId) {
                    $childFolderId = $this->productFolderSyncService->syncProductFolder(
                        $mainAccountId,
                        $childAccountId,
                        $folderId
                    );
                    if ($childFolderId) {
                        $bundleData['productFolder'] = [
                            'meta' => [
                                'href' => config('moysklad.api_url') . "/entity/productfolder/{$childFolderId}",
                                'type' => 'productfolder',
                                'mediaType' => 'application/json'
                            ]
                        ];
                    }
                }
            }
        }

        // Синхронизировать UOM (единица измерения)
        if (isset($bundle['uom'])) {
            $parentUomId = $this->extractEntityId($bundle['uom']['meta']['href'] ?? '');
            if ($parentUomId) {
                $childUomId = $this->standardEntitySync->syncUom(
                    $mainAccountId,
                    $childAccountId,
                    $parentUomId
                );
                if ($childUomId) {
                    $bundleData['uom'] = [
                        'meta' => [
                            'href' => config('moysklad.api_url') . "/entity/uom/{$childUomId}",
                            'type' => 'uom',
                            'mediaType' => 'application/json'
                        ]
                    ];
                }
            }
        }

        // Синхронизировать цены (используя трейт SyncHelpers)
        $prices = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $bundle,
            $settings
        );
        $bundleData['salePrices'] = $prices['salePrices'];
        if (isset($prices['buyPrice'])) {
            $bundleData['buyPrice'] = $prices['buyPrice'];
        }

        // Синхронизировать компоненты комплекта
        if (isset($bundle['components'])) {
            $bundleData['components'] = $this->syncBundleComponents(
                $mainAccountId,
                $childAccountId,
                $bundle['components']
            );
        }

        // Добавить НДС и налогообложение
        $bundleData = $this->productSyncService->addVatAndTaxFields($bundleData, $bundle, $settings);

        // Проверить существование комплекта в child по match_field
        $matchField = $settings->product_match_field ?? 'article';
        $matchValue = $bundle[$matchField] ?? null;

        if ($matchValue) {
            $mapping = $this->entityMappingService->findOrCreateBundleMapping(
                $mainAccountId,
                $childAccountId,
                $bundle['id'],
                $matchField,
                $matchValue
            );

            if ($mapping) {
                // Комплект уже существует в child → вызвать updateBundle вместо создания
                Log::info('Bundle already exists in child - updating instead of creating', [
                    'main_account_id' => $mainAccountId,
                    'child_account_id' => $childAccountId,
                    'main_bundle_id' => $bundle['id'],
                    'child_bundle_id' => $mapping->child_entity_id,
                    'match_field' => $matchField,
                    'match_value' => $matchValue
                ]);

                return $this->updateBundle(
                    $childAccount,
                    $mainAccountId,
                    $childAccountId,
                    $bundle,
                    $mapping,
                    $settings
                );
            }
        }

        // Комплект не найден в child → создать новый
        try {
            $newBundleResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $mainAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $childAccountId,
                    entityType: 'bundle',
                    entityId: $bundle['id']
                )
                ->setOperationContext(
                    operationType: 'create',
                    operationResult: 'success'
                )
                ->post('entity/bundle', $bundleData);

            $newBundle = $newBundleResult['data'];

            // Сохранить маппинг
            EntityMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'bundle',
                'parent_entity_id' => $bundle['id'],
                'child_entity_id' => $newBundle['id'],
                'sync_direction' => 'main_to_child',
                'match_field' => $matchField,
                'match_value' => $matchValue,
            ]);

            Log::info('Bundle created in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'main_bundle_id' => $bundle['id'],
                'child_bundle_id' => $newBundle['id']
            ]);

            return $newBundle;

        } catch (\Exception $e) {
            // Детальное логирование HTTP 412 ошибки
            if (strpos($e->getMessage(), '412') !== false) {
                Log::error('Bundle creation failed with HTTP 412 - uniqueness violation', [
                    'main_account_id' => $mainAccountId,
                    'child_account_id' => $childAccountId,
                    'main_bundle_id' => $bundle['id'],
                    'match_field' => $matchField,
                    'match_value' => $matchValue,
                    'bundle_code' => $bundle['code'] ?? null,
                    'bundle_article' => $bundle['article'] ?? null,
                    'bundle_external_code' => $bundle['externalCode'] ?? null,
                    'bundle_name' => $bundle['name'] ?? null,
                    'error' => $e->getMessage()
                ]);
            }
            throw $e;
        }
    }

    /**
     * Обновить комплект в дочернем аккаунте
     */
    protected function updateBundle(
        Account $childAccount,
        string $mainAccountId,
        string $childAccountId,
        array $bundle,
        EntityMapping $mapping,
        SyncSetting $settings
    ): array {
        $bundleData = [
            'name' => $bundle['name'],
            'article' => $bundle['article'] ?? null,
            'code' => $bundle['code'] ?? null,
            'externalCode' => $bundle['externalCode'] ?? null,
            'description' => $bundle['description'] ?? null,
        ];

        // Штрихкоды
        if (isset($bundle['barcodes'])) {
            $bundleData['barcodes'] = $bundle['barcodes'];
        }

        // Доп.поля (используя AttributeSyncService)
        if (isset($bundle['attributes'])) {
            $bundleData['attributes'] = $this->attributeSyncService->syncAttributes(
                sourceAccountId: $mainAccountId,
                targetAccountId: $childAccountId,
                settingsAccountId: $childAccountId,
                entityType: 'bundle',
                attributes: $bundle['attributes'],
                direction: 'main_to_child'
            );
        }

        // Группа товара (ProductFolder)
        if ($settings->create_product_folders && isset($bundle['productFolder'])) {
            $folderHref = $bundle['productFolder']['meta']['href'] ?? null;
            if ($folderHref) {
                $folderId = $this->extractEntityId($folderHref);
                if ($folderId) {
                    $childFolderId = $this->productFolderSyncService->syncProductFolder(
                        $mainAccountId,
                        $childAccountId,
                        $folderId
                    );
                    if ($childFolderId) {
                        $bundleData['productFolder'] = [
                            'meta' => [
                                'href' => config('moysklad.api_url') . "/entity/productfolder/{$childFolderId}",
                                'type' => 'productfolder',
                                'mediaType' => 'application/json'
                            ]
                        ];
                    }
                }
            }
        }

        // UOM (единица измерения)
        if (isset($bundle['uom'])) {
            $parentUomId = $this->extractEntityId($bundle['uom']['meta']['href'] ?? '');
            if ($parentUomId) {
                $childUomId = $this->standardEntitySync->syncUom(
                    $mainAccountId,
                    $childAccountId,
                    $parentUomId
                );
                if ($childUomId) {
                    $bundleData['uom'] = [
                        'meta' => [
                            'href' => config('moysklad.api_url') . "/entity/uom/{$childUomId}",
                            'type' => 'uom',
                            'mediaType' => 'application/json'
                        ]
                    ];
                }
            }
        }

        // Цены (используя трейт SyncHelpers)
        $prices = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $bundle,
            $settings
        );
        $bundleData['salePrices'] = $prices['salePrices'];
        if (isset($prices['buyPrice'])) {
            $bundleData['buyPrice'] = $prices['buyPrice'];
        }

        // Компоненты комплекта
        if (isset($bundle['components'])) {
            $bundleData['components'] = $this->syncBundleComponents(
                $mainAccountId,
                $childAccountId,
                $bundle['components']
            );
        }

        // Добавить НДС и налогообложение
        $bundleData = $this->productSyncService->addVatAndTaxFields($bundleData, $bundle, $settings);

        // Обновить комплект
        $updatedBundleResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->setLogContext(
                accountId: $mainAccountId,
                direction: 'main_to_child',
                relatedAccountId: $childAccountId,
                entityType: 'bundle',
                entityId: $bundle['id']
            )
            ->setOperationContext(
                operationType: 'update',
                operationResult: 'success'
            )
            ->put("entity/bundle/{$mapping->child_entity_id}", $bundleData);

        Log::info('Bundle updated in child account', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_bundle_id' => $bundle['id'],
            'child_bundle_id' => $mapping->child_entity_id
        ]);

        return $updatedBundleResult['data'];
    }

    /**
     * Синхронизировать компоненты комплекта
     */
    protected function syncBundleComponents(
        string $mainAccountId,
        string $childAccountId,
        array $components
    ): array {
        $syncedComponents = [];

        // Проверить структуру: если это объект с rows - взять rows
        if (isset($components['rows']) && is_array($components['rows'])) {
            $components = $components['rows'];
        }

        foreach ($components as $component) {
            $assortment = $component['assortment'] ?? null;
            if (!$assortment) {
                continue;
            }

            $entityType = $assortment['meta']['type'] ?? null;
            $entityHref = $assortment['meta']['href'] ?? null;
            $entityId = $this->extractEntityId($entityHref);

            if (!$entityId || !in_array($entityType, ['product', 'variant'])) {
                continue;
            }

            // Найти маппинг компонента
            $componentMapping = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_entity_id', $entityId)
                ->where('entity_type', $entityType)
                ->first();

            if (!$componentMapping) {
                // Синхронизировать компонент если он еще не синхронизирован
                if ($entityType === 'product') {
                    $this->productSyncService->syncProduct($mainAccountId, $childAccountId, $entityId);
                } elseif ($entityType === 'variant') {
                    $this->variantSyncService->syncVariant($mainAccountId, $childAccountId, $entityId);
                }

                // Повторно получить маппинг
                $componentMapping = EntityMapping::where('parent_account_id', $mainAccountId)
                    ->where('child_account_id', $childAccountId)
                    ->where('parent_entity_id', $entityId)
                    ->where('entity_type', $entityType)
                    ->first();
            }

            if ($componentMapping) {
                $syncedComponents[] = [
                    'assortment' => [
                        'meta' => [
                            'href' => config('moysklad.api_url') . "/entity/{$entityType}/{$componentMapping->child_entity_id}",
                            'type' => $entityType,
                            'mediaType' => 'application/json'
                        ]
                    ],
                    'quantity' => $component['quantity'] ?? 1
                ];
            }
        }

        return $syncedComponents;
    }

    /**
     * Подготовить комплект для batch синхронизации (batch POST)
     *
     * Использует ТОЛЬКО кешированные mappings из БД (без дополнительных GET запросов).
     * Возвращает массив готовый для batch POST в МойСклад.
     *
     * @param array $bundle Комплект из главного аккаунта (с expand)
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param SyncSetting $settings Настройки синхронизации
     * @return array|null Подготовленный комплект для batch POST или null если skip
     */
    public function prepareBundleForBatch(
        array $bundle,
        string $mainAccountId,
        string $childAccountId,
        SyncSetting $settings
    ): ?array {
        // 1. Проверить фильтры (используя трейт SyncHelpers)
        if (!$this->passesFilters($bundle, $settings, $mainAccountId)) {
            Log::debug('Bundle filtered out in batch', ['bundle_id' => $bundle['id']]);
            return null;
        }

        // 2. Проверить, что поле сопоставления заполнено
        $matchField = $settings->product_match_field ?? 'article';
        if ($matchField === 'name') {
            if (empty($bundle['name'])) {
                Log::warning('Bundle has empty name (required field!)', [
                    'bundle_id' => $bundle['id']
                ]);
                return null;
            }
        } else {
            if (empty($bundle[$matchField])) {
                Log::debug('Bundle skipped in batch: match field is empty', [
                    'bundle_id' => $bundle['id'],
                    'match_field' => $matchField,
                    'bundle_name' => $bundle['name'] ?? 'unknown'
                ]);
                return null;
            }
        }

        // 3. Проверить mapping (create or update?)
        $mapping = EntityMapping::where([
            'parent_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'entity_type' => 'bundle',
            'parent_entity_id' => $bundle['id']
        ])->first();

        // 4. Build base bundle data
        $bundleData = [
            'name' => $bundle['name'],
            'code' => $bundle['code'] ?? null,
            'externalCode' => $bundle['externalCode'] ?? null,
            'article' => $bundle['article'] ?? null,
            'description' => $bundle['description'] ?? null,
        ];

        // 5. Если обновление - добавить meta
        if ($mapping) {
            $bundleData['meta'] = [
                'href' => config('moysklad.api_url') . "/entity/bundle/{$mapping->child_entity_id}",
                'type' => 'bundle',
                'mediaType' => 'application/json'
            ];
        }

        // 6. Штрихкоды
        if (isset($bundle['barcodes'])) {
            $bundleData['barcodes'] = $bundle['barcodes'];
        }

        // 7. Синхронизировать доп.поля (используя кешированные mappings)
        if (isset($bundle['attributes'])) {
            $bundleData['attributes'] = $this->attributeSyncService->syncAttributes(
                sourceAccountId: $mainAccountId,
                targetAccountId: $childAccountId,
                settingsAccountId: $childAccountId,
                entityType: 'bundle',
                attributes: $bundle['attributes'],
                direction: 'main_to_child'
            );
        }

        // 8. Синхронизировать группу товара (используя кешированный mapping из DB)
        if ($settings->create_product_folders && isset($bundle['productFolder']['id'])) {
            $folderMapping = EntityMapping::where([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'productfolder',
                'parent_entity_id' => $bundle['productFolder']['id']
            ])->first();

            if ($folderMapping) {
                $bundleData['productFolder'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/productfolder/{$folderMapping->child_entity_id}",
                        'type' => 'productfolder',
                        'mediaType' => 'application/json'
                    ]
                ];
            }
        }

        // 9. Синхронизировать UOM (используя кешированный mapping)
        if (isset($bundle['uom']['id'])) {
            $childUomId = $this->standardEntitySync->getCachedUomMapping(
                $mainAccountId,
                $childAccountId,
                $bundle['uom']['id']
            );

            if ($childUomId) {
                $bundleData['uom'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/uom/{$childUomId}",
                        'type' => 'uom',
                        'mediaType' => 'application/json'
                    ]
                ];
            }
        }

        // 10. Синхронизировать цены (используя трейт SyncHelpers)
        $prices = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $bundle,
            $settings
        );
        $bundleData['salePrices'] = $prices['salePrices'];
        if (isset($prices['buyPrice'])) {
            $bundleData['buyPrice'] = $prices['buyPrice'];
        }

        // 11. Синхронизировать компоненты комплекта (с автосинхронизацией)
        if (isset($bundle['components'])) {
            $syncedComponents = $this->syncBundleComponents(
                $mainAccountId,
                $childAccountId,
                $bundle['components']
            );

            if (empty($syncedComponents)) {
                Log::warning('Bundle has no synced components - skipping', [
                    'bundle_id' => $bundle['id'],
                    'components_count' => count($bundle['components'])
                ]);
                return null; // Комплект без компонентов не имеет смысла
            }

            $bundleData['components'] = $syncedComponents;
        }

        // 12. Добавить НДС и налогообложение
        $bundleData = $this->productSyncService->addVatAndTaxFields($bundleData, $bundle, $settings);

        // 13. Добавить служебные поля для обработки результата batch POST
        $bundleData['_original_id'] = $bundle['id'];
        $bundleData['_is_update'] = $mapping ? true : false;

        // 14. Store images data for batch image sync after entity creation
        $bundleData['_original_images'] = $bundle['images']['rows'] ?? [];

        return $bundleData;
    }

    /**
     * Архивировать комплект в дочерних аккаунтах
     */
    public function archiveBundle(string $mainAccountId, string $bundleId): int
    {
        try {
            // Найти все маппинги этого комплекта
            $mappings = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('parent_entity_id', $bundleId)
                ->where('entity_type', 'bundle')
                ->where('sync_direction', 'main_to_child')
                ->get();

            if ($mappings->isEmpty()) {
                Log::debug('No mappings found for bundle archive', [
                    'main_account_id' => $mainAccountId,
                    'bundle_id' => $bundleId
                ]);
                return 0;
            }

            $archivedCount = 0;

            foreach ($mappings as $mapping) {
                try {
                    $childAccount = Account::where('account_id', $mapping->child_account_id)->first();

                    if (!$childAccount) {
                        Log::warning('Child account not found for bundle archive', [
                            'child_account_id' => $mapping->child_account_id
                        ]);
                        continue;
                    }

                    // Архивировать комплект в дочернем аккаунте
                    $this->moySkladService
                        ->setAccessToken($childAccount->access_token)
                        ->setLogContext(
                            accountId: $mainAccountId,
                            direction: 'main_to_child',
                            relatedAccountId: $mapping->child_account_id,
                            entityType: 'bundle',
                            entityId: $bundleId
                        )
                        ->put("entity/bundle/{$mapping->child_entity_id}", [
                            'archived' => true
                        ]);

                    $archivedCount++;

                    Log::info('Bundle archived in child account', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $mapping->child_account_id,
                        'main_bundle_id' => $bundleId,
                        'child_bundle_id' => $mapping->child_entity_id
                    ]);

                } catch (\Exception $e) {
                    Log::error('Failed to archive bundle in child account', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $mapping->child_account_id,
                        'bundle_id' => $bundleId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $archivedCount;

        } catch (\Exception $e) {
            Log::error('Failed to archive bundle', [
                'main_account_id' => $mainAccountId,
                'bundle_id' => $bundleId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Добавить задачи синхронизации изображений в очередь
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $entityType Тип сущности (product, bundle, variant)
     * @param string $parentEntityId UUID сущности в главном аккаунте
     * @param string $childEntityId UUID сущности в дочернем аккаунте
     * @param array $images Массив изображений из МойСклад API (rows)
     * @param SyncSetting $settings Настройки синхронизации
     * @return int Количество добавленных задач
     */
    protected function queueImageSync(
        string $mainAccountId,
        string $childAccountId,
        string $entityType,
        string $parentEntityId,
        string $childEntityId,
        array $images,
        SyncSetting $settings
    ): int {
        // Получить лимит изображений
        $imageSyncService = app(\App\Services\ImageSyncService::class);
        $limit = $imageSyncService->getImageLimit($settings);

        if ($limit === 0) {
            return 0; // Image sync disabled
        }

        // Ограничить количество изображений
        $imagesToSync = array_slice($images, 0, $limit);
        $queuedCount = 0;

        foreach ($imagesToSync as $index => $image) {
            try {
                $downloadHref = $image['meta']['downloadHref'] ?? null;
                $filename = $image['filename'] ?? "image_{$index}.jpg";

                if (!$downloadHref) {
                    Log::warning('Image missing downloadHref, skipping', [
                        'entity_type' => $entityType,
                        'entity_id' => $parentEntityId,
                        'image_index' => $index
                    ]);
                    continue;
                }

                \App\Models\SyncQueue::create([
                    'account_id' => $childAccountId,
                    'entity_type' => 'image_sync',
                    'entity_id' => $filename,
                    'operation' => 'sync',
                    'priority' => 50, // Medium priority (changed from 80 to 50)
                    'payload' => [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'parent_entity_type' => $entityType,
                        'parent_entity_id' => $parentEntityId,
                        'child_entity_id' => $childEntityId,
                        'image_url' => $downloadHref,
                        'filename' => $filename,
                    ],
                    'max_attempts' => 3,
                ]);

                $queuedCount++;

            } catch (\Exception $e) {
                Log::error('Failed to queue image sync task', [
                    'entity_type' => $entityType,
                    'entity_id' => $parentEntityId,
                    'image_index' => $index,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($queuedCount > 0) {
            Log::info('Queued image sync tasks', [
                'entity_type' => $entityType,
                'entity_id' => $parentEntityId,
                'child_entity_id' => $childEntityId,
                'queued_count' => $queuedCount,
                'total_images' => count($images),
                'limit' => $limit
            ]);
        }

        return $queuedCount;
    }

}
