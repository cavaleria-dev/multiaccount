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
    protected ?VariantSyncService $variantSyncService = null;
    protected ?BundleSyncService $bundleSyncService = null;

    public function __construct(
        MoySkladService $moySkladService,
        CustomEntitySyncService $customEntitySyncService,
        ProductFilterService $productFilterService,
        StandardEntitySyncService $standardEntitySync,
        ProductFolderSyncService $productFolderSyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->customEntitySyncService = $customEntitySyncService;
        $this->productFilterService = $productFilterService;
        $this->standardEntitySync = $standardEntitySync;
        $this->productFolderSyncService = $productFolderSyncService;
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
            if (isset($product['attributes']) && is_array($product['attributes'])) {
                $attributesMetadata = $this->loadAttributesMetadata($mainAccountId);

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

        // Синхронизировать доп.поля (используя трейт SyncHelpers)
        if (isset($product['attributes'])) {
            $productData['attributes'] = $this->syncAttributes(
                $mainAccountId,
                $childAccountId,
                'product',
                $product['attributes']
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

        // Доп.поля (используя трейт SyncHelpers)
        if (isset($product['attributes'])) {
            $productData['attributes'] = $this->syncAttributes(
                $mainAccountId,
                $childAccountId,
                'product',
                $product['attributes']
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
