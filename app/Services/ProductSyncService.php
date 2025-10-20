<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use App\Services\Traits\SyncHelpers;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации товаров (products) из главного в дочерние аккаунты
 *
 * Использует трейт SyncHelpers для общих методов
 * Делегирует синхронизацию variant/bundle/productFolder специализированным сервисам
 */
class ProductSyncService
{
    use SyncHelpers;

    protected MoySkladService $moySkladService;
    protected CustomEntitySyncService $customEntitySyncService;
    protected ProductFilterService $productFilterService;
    protected StandardEntitySyncService $standardEntitySync;
    protected ProductFolderSyncService $productFolderSyncService;
    protected AttributeSyncService $attributeSyncService;
    protected ?VariantSyncService $variantSyncService = null;
    protected ?BundleSyncService $bundleSyncService = null;

    public function __construct(
        MoySkladService $moySkladService,
        CustomEntitySyncService $customEntitySyncService,
        ProductFilterService $productFilterService,
        StandardEntitySyncService $standardEntitySync,
        ProductFolderSyncService $productFolderSyncService,
        AttributeSyncService $attributeSyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->customEntitySyncService = $customEntitySyncService;
        $this->productFilterService = $productFilterService;
        $this->standardEntitySync = $standardEntitySync;
        $this->productFolderSyncService = $productFolderSyncService;
        $this->attributeSyncService = $attributeSyncService;
    }

    /**
     * Setter injection для VariantSyncService (избегаем циклическую зависимость)
     */
    public function setVariantSyncService(VariantSyncService $variantSyncService): void
    {
        $this->variantSyncService = $variantSyncService;
    }

    /**
     * Setter injection для BundleSyncService (избегаем циклическую зависимость)
     */
    public function setBundleSyncService(BundleSyncService $bundleSyncService): void
    {
        $this->bundleSyncService = $bundleSyncService;
    }

    /**
     * Синхронизировать товар из главного в дочерний аккаунт
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $productId UUID товара в главном аккаунте
     * @return array|null Созданный/обновленный товар в дочернем аккаунте
     */
    public function syncProduct(string $mainAccountId, string $childAccountId, string $productId): ?array
    {
        try {
            // Получить настройки синхронизации
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || !$settings->sync_products) {
                Log::debug('Product sync is disabled', ['child_account_id' => $childAccountId]);
                return null;
            }

            // Получить товар из главного аккаунта (с expand для standard entities)
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $productResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->setLogContext(
                    accountId: $mainAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $childAccountId,
                    entityType: 'product',
                    entityId: $productId
                )
                ->get("entity/product/{$productId}", ['expand' => 'attributes,productFolder,uom,country']);

            $product = $productResult['data'];

            // Проверить, что поле сопоставления заполнено
            $matchField = $settings->product_match_field ?? 'article';
            if (empty($product[$matchField])) {
                Log::warning('Product skipped: match field is empty', [
                    'product_id' => $productId,
                    'child_account_id' => $childAccountId,
                    'match_field' => $matchField,
                    'product_name' => $product['name'] ?? 'unknown'
                ]);
                return null;
            }

            // Смержить метаданные атрибутов с значениями (для customEntityMeta)
            // Используем AttributeSyncService для загрузки метаданных
            if (isset($product['attributes']) && is_array($product['attributes'])) {
                $attributesMetadata = $this->attributeSyncService->loadAttributesMetadata($mainAccountId, 'product');

                foreach ($product['attributes'] as &$attr) {
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
            if (!$this->passesFilters($product, $settings, $mainAccountId)) {
                Log::debug('Product does not pass filters', [
                    'product_id' => $productId,
                    'child_account_id' => $childAccountId
                ]);
                return null;
            }

            // Проверить маппинг
            $mapping = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_entity_id', $productId)
                ->where('entity_type', 'product')
                ->where('sync_direction', 'main_to_child')
                ->first();

            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            if ($mapping) {
                // Товар уже существует, обновляем
                $result = $this->updateProduct($childAccount, $mainAccountId, $childAccountId, $product, $mapping, $settings);
            } else {
                // Создаем новый товар
                $result = $this->createProduct($childAccount, $mainAccountId, $childAccountId, $product, $settings);
            }

            // Очистить контекст логирования после успешной синхронизации
            $this->moySkladService->clearLogContext();

            return $result;

        } catch (\Exception $e) {
            // Очистить контекст логирования при ошибке
            $this->moySkladService->clearLogContext();

            Log::error('Failed to sync product', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Создать товар в дочернем аккаунте
     */
    protected function createProduct(
        Account $childAccount,
        string $mainAccountId,
        string $childAccountId,
        array $product,
        SyncSetting $settings
    ): array {
        $productData = [
            'name' => $product['name'],
            'article' => $product['article'] ?? null,
            'code' => $product['code'] ?? null,
            'externalCode' => $product['externalCode'] ?? null,
            'description' => $product['description'] ?? null,
        ];

        // Добавить штрихкоды
        if (isset($product['barcodes'])) {
            $productData['barcodes'] = $product['barcodes'];
        }

        // Синхронизировать доп.поля (используя AttributeSyncService)
        if (isset($product['attributes'])) {
            $productData['attributes'] = $this->attributeSyncService->syncAttributes(
                sourceAccountId: $mainAccountId,
                targetAccountId: $childAccountId,
                settingsAccountId: $childAccountId,
                entityType: 'product',
                attributes: $product['attributes'],
                direction: 'main_to_child'
            );
        }

        // Синхронизировать группу товара (используя ProductFolderSyncService)
        if ($settings->create_product_folders && isset($product['productFolder'])) {
            $folderHref = $product['productFolder']['meta']['href'] ?? null;
            if ($folderHref) {
                $folderId = $this->extractEntityId($folderHref);
                if ($folderId) {
                    $childFolderId = $this->productFolderSyncService->syncProductFolder($mainAccountId, $childAccountId, $folderId);
                    if ($childFolderId) {
                        $productData['productFolder'] = [
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

        // Синхронизировать цены (используя трейт SyncHelpers)
        $prices = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $product,
            $settings
        );
        $productData['salePrices'] = $prices['salePrices'];
        if (isset($prices['buyPrice'])) {
            $productData['buyPrice'] = $prices['buyPrice'];
        }

        // Синхронизировать стандартные справочники (uom, country)
        // UOM (единица измерения)
        if (isset($product['uom'])) {
            $parentUomId = $this->extractEntityId($product['uom']['meta']['href'] ?? '');
            if ($parentUomId) {
                $childUomId = $this->standardEntitySync->syncUom(
                    $mainAccountId,
                    $childAccountId,
                    $parentUomId
                );
                if ($childUomId) {
                    $productData['uom'] = [
                        'meta' => [
                            'href' => config('moysklad.api_url') . "/entity/uom/{$childUomId}",
                            'type' => 'uom',
                            'mediaType' => 'application/json'
                        ]
                    ];
                }
            }
        }

        // Country (страна)
        if (isset($product['country'])) {
            $parentCountryId = $this->extractEntityId($product['country']['meta']['href'] ?? '');
            if ($parentCountryId) {
                $childCountryId = $this->standardEntitySync->syncCountry(
                    $mainAccountId,
                    $childAccountId,
                    $parentCountryId
                );
                if ($childCountryId) {
                    $productData['country'] = [
                        'meta' => [
                            'href' => config('moysklad.api_url') . "/entity/country/{$childCountryId}",
                            'type' => 'country',
                            'mediaType' => 'application/json'
                        ]
                    ];
                }
            }
        }

        // VAT (ставка НДС) - просто сохранить маппинг для отслеживания
        if (isset($product['vat'])) {
            $this->standardEntitySync->syncVat($mainAccountId, $childAccountId, $product['vat']);
        }

        // Добавить дополнительные поля (НДС, физ.характеристики, маркировка и т.д.)
        $productData = $this->addAdditionalFields($productData, $product, $settings);

        // Создать товар
        Log::channel('sync')->info('Creating product in child account - REQUEST', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_product_id' => $product['id'],
            'product_data' => json_encode($productData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ]);

        $newProductResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->post('entity/product', $productData);

        $newProduct = $newProductResult['data'];

        Log::channel('sync')->info('Creating product in child account - RESPONSE', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_product_id' => $product['id'],
            'child_product_id' => $newProduct['id'] ?? null,
            'response_data' => json_encode($newProduct, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ]);

        // Сохранить маппинг
        EntityMapping::create([
            'parent_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'entity_type' => 'product',
            'parent_entity_id' => $product['id'],
            'child_entity_id' => $newProduct['id'],
            'sync_direction' => 'main_to_child',
            'match_field' => $settings->product_match_field ?? 'article',
            'match_value' => $product[$settings->product_match_field ?? 'article'] ?? null,
        ]);

        Log::info('Product created in child account', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_product_id' => $product['id'],
            'child_product_id' => $newProduct['id']
        ]);

        return $newProduct;
    }

    /**
     * Обновить товар в дочернем аккаунте
     */
    protected function updateProduct(
        Account $childAccount,
        string $mainAccountId,
        string $childAccountId,
        array $product,
        EntityMapping $mapping,
        SyncSetting $settings
    ): array {
        $productData = [
            'name' => $product['name'],
            'article' => $product['article'] ?? null,
            'code' => $product['code'] ?? null,
            'externalCode' => $product['externalCode'] ?? null,
            'description' => $product['description'] ?? null,
        ];

        // Штрихкоды
        if (isset($product['barcodes'])) {
            $productData['barcodes'] = $product['barcodes'];
        }

        // Доп.поля (используя AttributeSyncService)
        if (isset($product['attributes'])) {
            $productData['attributes'] = $this->attributeSyncService->syncAttributes(
                sourceAccountId: $mainAccountId,
                targetAccountId: $childAccountId,
                settingsAccountId: $childAccountId,
                entityType: 'product',
                attributes: $product['attributes'],
                direction: 'main_to_child'
            );
        }

        // Синхронизировать группу товара (используя ProductFolderSyncService)
        if ($settings->create_product_folders && isset($product['productFolder'])) {
            $folderHref = $product['productFolder']['meta']['href'] ?? null;
            if ($folderHref) {
                $folderId = $this->extractEntityId($folderHref);
                if ($folderId) {
                    $childFolderId = $this->productFolderSyncService->syncProductFolder($mainAccountId, $childAccountId, $folderId);
                    if ($childFolderId) {
                        $productData['productFolder'] = [
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

        // Цены (используя трейт SyncHelpers)
        $prices = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $product,
            $settings
        );
        $productData['salePrices'] = $prices['salePrices'];
        if (isset($prices['buyPrice'])) {
            $productData['buyPrice'] = $prices['buyPrice'];
        }

        // Синхронизировать стандартные справочники (uom, country)
        // UOM (единица измерения)
        if (isset($product['uom'])) {
            $parentUomId = $this->extractEntityId($product['uom']['meta']['href'] ?? '');
            if ($parentUomId) {
                $childUomId = $this->standardEntitySync->syncUom(
                    $mainAccountId,
                    $childAccountId,
                    $parentUomId
                );
                if ($childUomId) {
                    $productData['uom'] = [
                        'meta' => [
                            'href' => config('moysklad.api_url') . "/entity/uom/{$childUomId}",
                            'type' => 'uom',
                            'mediaType' => 'application/json'
                        ]
                    ];
                }
            }
        }

        // Country (страна)
        if (isset($product['country'])) {
            $parentCountryId = $this->extractEntityId($product['country']['meta']['href'] ?? '');
            if ($parentCountryId) {
                $childCountryId = $this->standardEntitySync->syncCountry(
                    $mainAccountId,
                    $childAccountId,
                    $parentCountryId
                );
                if ($childCountryId) {
                    $productData['country'] = [
                        'meta' => [
                            'href' => config('moysklad.api_url') . "/entity/country/{$childCountryId}",
                            'type' => 'country',
                            'mediaType' => 'application/json'
                        ]
                    ];
                }
            }
        }

        // VAT (ставка НДС) - просто сохранить маппинг для отслеживания
        if (isset($product['vat'])) {
            $this->standardEntitySync->syncVat($mainAccountId, $childAccountId, $product['vat']);
        }

        // Добавить дополнительные поля (НДС, физ.характеристики, маркировка и т.д.)
        $productData = $this->addAdditionalFields($productData, $product, $settings);

        // Обновить товар
        Log::channel('sync')->info('Updating product in child account - REQUEST', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_product_id' => $product['id'],
            'child_product_id' => $mapping->child_entity_id,
            'product_data' => json_encode($productData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ]);

        $updatedProductResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->put("entity/product/{$mapping->child_entity_id}", $productData);

        Log::channel('sync')->info('Updating product in child account - RESPONSE', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_product_id' => $product['id'],
            'child_product_id' => $mapping->child_entity_id,
            'response_data' => json_encode($updatedProductResult['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ]);

        Log::info('Product updated in child account', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_product_id' => $product['id'],
            'child_product_id' => $mapping->child_entity_id
        ]);

        return $updatedProductResult['data'];
    }

    /**
     * Архивировать товар в дочерних аккаунтах
     */
    public function archiveProduct(string $mainAccountId, string $productId): int
    {
        try {
            // Найти все маппинги этого товара
            $mappings = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('parent_entity_id', $productId)
                ->where('entity_type', 'product')
                ->where('sync_direction', 'main_to_child')
                ->get();

            if ($mappings->isEmpty()) {
                Log::debug('No mappings found for product archive', [
                    'main_account_id' => $mainAccountId,
                    'product_id' => $productId
                ]);
                return 0;
            }

            $archivedCount = 0;

            foreach ($mappings as $mapping) {
                try {
                    $childAccount = Account::where('account_id', $mapping->child_account_id)->first();

                    if (!$childAccount) {
                        Log::warning('Child account not found for product archive', [
                            'child_account_id' => $mapping->child_account_id
                        ]);
                        continue;
                    }

                    // Архивировать товар в дочернем аккаунте
                    $this->moySkladService
                        ->setAccessToken($childAccount->access_token)
                        ->setLogContext(
                            accountId: $mainAccountId,
                            direction: 'main_to_child',
                            relatedAccountId: $mapping->child_account_id,
                            entityType: 'product',
                            entityId: $productId
                        )
                        ->put("entity/product/{$mapping->child_entity_id}", [
                            'archived' => true
                        ]);

                    // Очистить контекст после операции
                    $this->moySkladService->clearLogContext();

                    $archivedCount++;

                    Log::info('Product archived in child account', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $mapping->child_account_id,
                        'main_product_id' => $productId,
                        'child_product_id' => $mapping->child_entity_id
                    ]);

                } catch (\Exception $e) {
                    Log::error('Failed to archive product in child account', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $mapping->child_account_id,
                        'product_id' => $productId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $archivedCount;

        } catch (\Exception $e) {
            Log::error('Failed to archive product', [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Добавить только НДС и налогообложение (для bundles и services)
     *
     * Упрощенная версия addAdditionalFields() без физ.характеристик и маркировки
     * Публичный метод, используется в BundleSyncService и ServiceSyncService
     *
     * @param array $data Данные для API (bundle/service)
     * @param array $source Источник данных из main аккаунта
     * @param SyncSetting $settings Настройки синхронизации
     * @return array Данные с добавленными полями НДС
     */
    public function addVatAndTaxFields(array $data, array $source, SyncSetting $settings): array
    {
        // НДС и налогообложение (с учетом настроек)
        if ($settings->sync_vat && $settings->vat_sync_mode === 'from_main') {
            if (isset($source['vat'])) {
                $data['vat'] = $source['vat'];
            }
            if (isset($source['vatEnabled'])) {
                $data['vatEnabled'] = $source['vatEnabled'];
            }
            if (isset($source['useParentVat'])) {
                $data['useParentVat'] = $source['useParentVat'];
            }
        }

        // Система налогообложения
        if (isset($source['taxSystem'])) {
            $data['taxSystem'] = $source['taxSystem'];
        }

        // Признак предмета расчета
        if (isset($source['paymentItemType'])) {
            $data['paymentItemType'] = $source['paymentItemType'];
        }

        return $data;
    }

    /**
     * Добавить дополнительные поля для синхронизации
     *
     * Добавляет НДС, физические характеристики, маркировку и т.д.
     * Публичный метод, используется также в VariantSyncService
     *
     * @param array $productData Данные для API (будут изменены)
     * @param array $source Источник данных (product/variant из main аккаунта)
     * @param SyncSetting $settings Настройки синхронизации
     * @return array Данные с добавленными полями
     */
    public function addAdditionalFields(array $productData, array $source, SyncSetting $settings): array
    {
        // НДС и налогообложение (с учетом настроек)
        if ($settings->sync_vat && $settings->vat_sync_mode === 'from_main') {
            if (isset($source['vat'])) {
                $productData['vat'] = $source['vat'];
            }
            if (isset($source['vatEnabled'])) {
                $productData['vatEnabled'] = $source['vatEnabled'];
            }
            if (isset($source['useParentVat'])) {
                $productData['useParentVat'] = $source['useParentVat'];
            }
        }

        // Система налогообложения
        if (isset($source['taxSystem'])) {
            $productData['taxSystem'] = $source['taxSystem'];
        }

        // Физические характеристики
        if (isset($source['weight'])) {
            $productData['weight'] = $source['weight'];
        }
        if (isset($source['volume'])) {
            $productData['volume'] = $source['volume'];
        }

        // Особенности учета
        if (isset($source['weighed'])) {
            $productData['weighed'] = $source['weighed'];
        }
        if (isset($source['onTap'])) {
            $productData['onTap'] = $source['onTap'];
        }

        // Маркировка
        if (isset($source['trackingType'])) {
            $productData['trackingType'] = $source['trackingType'];
        }
        if (isset($source['ppeType'])) {
            $productData['ppeType'] = $source['ppeType'];
        }
        if (isset($source['partialDisposal'])) {
            $productData['partialDisposal'] = $source['partialDisposal'];
        }
        if (isset($source['tnved'])) {
            $productData['tnved'] = $source['tnved'];
        }

        // Признак предмета расчета
        if (isset($source['paymentItemType'])) {
            $productData['paymentItemType'] = $source['paymentItemType'];
        }

        // Алкогольная продукция
        if (isset($source['alcoholic'])) {
            $productData['alcoholic'] = $source['alcoholic'];
        }

        // Узбекистан: маркировка
        if (isset($source['mod__marking__uz'])) {
            $productData['mod__marking__uz'] = $source['mod__marking__uz'];
        }

        // Узбекистан: ТАСНИФ
        if (isset($source['mod__tasnif__uz'])) {
            $productData['mod__tasnif__uz'] = $source['mod__tasnif__uz'];
        }

        // Валидация несовместимых признаков
        return $this->validateProductFlags($productData);
    }

    /**
     * Валидировать и очистить несовместимые признаки товара
     *
     * МойСклад API имеет ограничения на сочетания признаков:
     * - weighed не сочетается с: onTap, isSerialTrackable, ppeType, alcoholic
     * - onTap не сочетается с: weighed, isSerialTrackable, ppeType
     * - isSerialTrackable не сочетается с: weighed, alcoholic, ppeType, trackingType, onTap
     * - ppeType не сочетается с: weighed, isSerialTrackable, alcoholic, trackingType, onTap
     * - alcoholic не сочетается с: weighed, isSerialTrackable, ppeType
     *
     * @param array $productData Данные товара для отправки в API
     * @return array Очищенные данные товара
     */
    protected function validateProductFlags(array $productData): array
    {
        // weighed не сочетается с: onTap, isSerialTrackable, ppeType, alcoholic
        // Маркировка весовых товаров только для MILK
        if (!empty($productData['weighed'])) {
            unset($productData['onTap']);
            unset($productData['isSerialTrackable']);
            unset($productData['ppeType']);
            unset($productData['alcoholic']);

            if (isset($productData['trackingType']) && $productData['trackingType'] !== 'MILK') {
                unset($productData['trackingType']);
            }

            Log::debug('Product is weighed, removed incompatible flags', [
                'removed' => ['onTap', 'isSerialTrackable', 'ppeType', 'alcoholic']
            ]);
        }

        // onTap не сочетается с: weighed, isSerialTrackable, ppeType
        // Маркировка разливных товаров только для MILK, PERFUMERY
        if (!empty($productData['onTap'])) {
            unset($productData['weighed']);
            unset($productData['isSerialTrackable']);
            unset($productData['ppeType']);

            if (isset($productData['trackingType']) &&
                !in_array($productData['trackingType'], ['MILK', 'PERFUMERY'])) {
                unset($productData['trackingType']);
            }

            Log::debug('Product is onTap, removed incompatible flags', [
                'removed' => ['weighed', 'isSerialTrackable', 'ppeType']
            ]);
        }

        // isSerialTrackable не сочетается с: weighed, alcoholic, ppeType, trackingType, onTap
        if (!empty($productData['isSerialTrackable'])) {
            unset($productData['weighed']);
            unset($productData['alcoholic']);
            unset($productData['ppeType']);
            unset($productData['trackingType']);
            unset($productData['onTap']);

            Log::debug('Product is isSerialTrackable, removed incompatible flags', [
                'removed' => ['weighed', 'alcoholic', 'ppeType', 'trackingType', 'onTap']
            ]);
        }

        // ppeType не сочетается с: weighed, isSerialTrackable, alcoholic, trackingType, onTap
        if (!empty($productData['ppeType'])) {
            unset($productData['weighed']);
            unset($productData['isSerialTrackable']);
            unset($productData['alcoholic']);
            unset($productData['trackingType']);
            unset($productData['onTap']);

            Log::debug('Product has ppeType, removed incompatible flags', [
                'removed' => ['weighed', 'isSerialTrackable', 'alcoholic', 'trackingType', 'onTap']
            ]);
        }

        // alcoholic не сочетается с: weighed, isSerialTrackable, ppeType
        // Если trackingType не BEER_ALCOHOL или NOT_TRACKED - удалить alcoholic
        if (!empty($productData['alcoholic'])) {
            unset($productData['weighed']);
            unset($productData['isSerialTrackable']);
            unset($productData['ppeType']);

            if (isset($productData['trackingType']) &&
                !in_array($productData['trackingType'], ['BEER_ALCOHOL', 'NOT_TRACKED'])) {
                unset($productData['alcoholic']);
                Log::debug('Removed alcoholic due to incompatible trackingType', [
                    'tracking_type' => $productData['trackingType']
                ]);
            } else {
                Log::debug('Product is alcoholic, removed incompatible flags', [
                    'removed' => ['weighed', 'isSerialTrackable', 'ppeType']
                ]);
            }
        }

        return $productData;
    }

    /**
     * ДЕЛЕГИРУЮЩИЕ МЕТОДЫ для обратной совместимости
     * Эти методы делегируют вызовы к специализированным сервисам
     */

    /**
     * Делегировать синхронизацию модификации к VariantSyncService
     */
    public function syncVariant(string $mainAccountId, string $childAccountId, string $variantId): ?array
    {
        if (!$this->variantSyncService) {
            Log::warning('VariantSyncService not injected, skipping variant sync', [
                'variant_id' => $variantId
            ]);
            return null;
        }

        return $this->variantSyncService->syncVariant($mainAccountId, $childAccountId, $variantId);
    }

    /**
     * Делегировать архивацию модификации к VariantSyncService
     */
    public function archiveVariant(string $mainAccountId, string $variantId): int
    {
        if (!$this->variantSyncService) {
            Log::warning('VariantSyncService not injected, skipping variant archive', [
                'variant_id' => $variantId
            ]);
            return 0;
        }

        return $this->variantSyncService->archiveVariant($mainAccountId, $variantId);
    }

    /**
     * Делегировать синхронизацию комплекта к BundleSyncService
     */
    public function syncBundle(string $mainAccountId, string $childAccountId, string $bundleId): ?array
    {
        if (!$this->bundleSyncService) {
            Log::warning('BundleSyncService not injected, skipping bundle sync', [
                'bundle_id' => $bundleId
            ]);
            return null;
        }

        return $this->bundleSyncService->syncBundle($mainAccountId, $childAccountId, $bundleId);
    }

    /**
     * Делегировать архивацию комплекта к BundleSyncService
     */
    public function archiveBundle(string $mainAccountId, string $bundleId): int
    {
        if (!$this->bundleSyncService) {
            Log::warning('BundleSyncService not injected, skipping bundle archive', [
                'bundle_id' => $bundleId
            ]);
            return 0;
        }

        return $this->bundleSyncService->archiveBundle($mainAccountId, $bundleId);
    }
}
