<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use App\Models\AttributeMapping;
use App\Models\CharacteristicMapping;
use App\Models\PriceTypeMapping;
use App\Models\StandardEntityMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации товаров из главного в дочерние аккаунты
 */
class ProductSyncService
{
    protected MoySkladService $moySkladService;
    protected CustomEntitySyncService $customEntitySyncService;
    protected ProductFilterService $productFilterService;
    protected StandardEntitySyncService $standardEntitySync;

    public function __construct(
        MoySkladService $moySkladService,
        CustomEntitySyncService $customEntitySyncService,
        ProductFilterService $productFilterService,
        StandardEntitySyncService $standardEntitySync
    ) {
        $this->moySkladService = $moySkladService;
        $this->customEntitySyncService = $customEntitySyncService;
        $this->productFilterService = $productFilterService;
        $this->standardEntitySync = $standardEntitySync;
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
                ->get("entity/product/{$productId}", ['expand' => 'attributes,productFolder,uom,country']);

            $product = $productResult['data'];

            // Проверить фильтры
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
                return $this->updateProduct($childAccount, $mainAccountId, $childAccountId, $product, $mapping, $settings);
            } else {
                // Создаем новый товар
                return $this->createProduct($childAccount, $mainAccountId, $childAccountId, $product, $settings);
            }

        } catch (\Exception $e) {
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

        // Синхронизировать доп.поля
        if (isset($product['attributes']) && $settings->auto_create_attributes) {
            $productData['attributes'] = $this->syncAttributes(
                $mainAccountId,
                $childAccountId,
                'product',
                $product['attributes']
            );
        }

        // Синхронизировать группу товара (если включено)
        if ($settings->create_product_folders && isset($product['productFolder'])) {
            $folderHref = $product['productFolder']['meta']['href'] ?? null;
            if ($folderHref) {
                $folderId = $this->extractEntityId($folderHref);
                if ($folderId) {
                    $childFolderId = $this->syncProductFolder($mainAccountId, $childAccountId, $folderId);
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

        // Синхронизировать цены
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

        // Доп.поля
        if (isset($product['attributes']) && $settings->auto_create_attributes) {
            $productData['attributes'] = $this->syncAttributes(
                $mainAccountId,
                $childAccountId,
                'product',
                $product['attributes']
            );
        }

        // Синхронизировать группу товара (если включено)
        if ($settings->create_product_folders && isset($product['productFolder'])) {
            $folderHref = $product['productFolder']['meta']['href'] ?? null;
            if ($folderHref) {
                $folderId = $this->extractEntityId($folderHref);
                if ($folderId) {
                    $childFolderId = $this->syncProductFolder($mainAccountId, $childAccountId, $folderId);
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

        // Цены
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
     * Синхронизировать доп.поля
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
            // Проверить маппинг атрибута
            $attributeName = $attribute['name'] ?? null;
            $attributeType = $attribute['type'] ?? null;
            $attributeId = $attribute['id'] ?? null;

            if (!$attributeName || !$attributeType || !$attributeId) {
                continue;
            }

            // Если используется фильтр - проверить разрешен ли этот атрибут
            if ($useFilter && !in_array($attributeId, $attributeSyncList)) {
                continue; // Атрибут не в списке разрешенных - пропустить
            }

            $attributeMapping = AttributeMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('entity_type', $entityType)
                ->where('attribute_name', $attributeName)
                ->where('attribute_type', $attributeType)
                ->first();

            if (!$attributeMapping) {
                // Создать атрибут в дочернем аккаунте
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

            // Подготовить значение
            $value = $attribute['value'] ?? null;

            // Если тип customentity - синхронизировать элемент справочника
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

            // Для customentity нужно синхронизировать сам справочник
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

            // Сохранить маппинг
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

            Log::info('Attribute created in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'attribute_name' => $attribute['name'],
                'attribute_type' => $attribute['type']
            ]);

            return $mapping;

        } catch (\Exception $e) {
            Log::error('Failed to create attribute in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'attribute' => $attribute,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Синхронизировать цены
     */
    protected function syncPrices(
        string $mainAccountId,
        string $childAccountId,
        array $product,
        SyncSetting $settings
    ): array {
        $salePrices = [];
        $buyPrice = null;

        // Получить цены из главного товара
        $mainSalePrices = $product['salePrices'] ?? [];
        $mainBuyPrice = $product['buyPrice'] ?? null;

        // Если настроены маппинги цен, использовать их
        $priceMappings = $settings->price_mappings;
        $useMappings = !empty($priceMappings) && is_array($priceMappings);

        // Обработка закупочной цены
        if ($mainBuyPrice && $useMappings) {
            foreach ($priceMappings as $mapping) {
                $mainPriceTypeId = $mapping['main_price_type_id'] ?? null;
                $childPriceTypeId = $mapping['child_price_type_id'] ?? null;

                // buyPrice → buyPrice
                if ($mainPriceTypeId === 'buyPrice' && $childPriceTypeId === 'buyPrice') {
                    $buyPrice = $mainBuyPrice;

                    // Синхронизировать валюту в buyPrice
                    if (isset($buyPrice['currency']['meta']['href'])) {
                        $parentCurrencyId = $this->extractEntityId($buyPrice['currency']['meta']['href']);
                        if ($parentCurrencyId) {
                            $childCurrencyId = $this->standardEntitySync->syncCurrency(
                                $mainAccountId,
                                $childAccountId,
                                $parentCurrencyId
                            );
                            if ($childCurrencyId) {
                                $buyPrice['currency'] = [
                                    'meta' => [
                                        'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                        'type' => 'currency',
                                        'mediaType' => 'application/json'
                                    ]
                                ];
                            } else {
                                // Если валюта не найдена - удалить ссылку, МойСклад использует дефолтную
                                Log::warning('Failed to sync currency for buyPrice, using default', [
                                    'main_account_id' => $mainAccountId,
                                    'child_account_id' => $childAccountId,
                                    'parent_currency_id' => $parentCurrencyId
                                ]);
                                unset($buyPrice['currency']);
                            }
                        }
                    }
                }
                // buyPrice → salePrice
                elseif ($mainPriceTypeId === 'buyPrice' && $childPriceTypeId && $childPriceTypeId !== 'buyPrice') {
                    $priceData = [
                        'value' => $mainBuyPrice['value'] ?? 0,
                        'priceType' => [
                            'meta' => [
                                'href' => config('moysklad.api_url') . "/context/companysettings/pricetype/{$childPriceTypeId}",
                                'type' => 'pricetype',
                                'mediaType' => 'application/json'
                            ]
                        ]
                    ];

                    // Синхронизировать валюту если указана
                    if (isset($mainBuyPrice['currency']['meta']['href'])) {
                        $parentCurrencyId = $this->extractEntityId($mainBuyPrice['currency']['meta']['href']);
                        if ($parentCurrencyId) {
                            $childCurrencyId = $this->standardEntitySync->syncCurrency(
                                $mainAccountId,
                                $childAccountId,
                                $parentCurrencyId
                            );
                            if ($childCurrencyId) {
                                $priceData['currency'] = [
                                    'meta' => [
                                        'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                        'type' => 'currency',
                                        'mediaType' => 'application/json'
                                    ]
                                ];
                            }
                        }
                    }

                    $salePrices[] = $priceData;
                }
            }
        } elseif ($mainBuyPrice && !$useMappings) {
            // Если маппинги не используются - копировать buyPrice с синхронизацией валюты
            $buyPrice = $mainBuyPrice;

            // Синхронизировать валюту в buyPrice
            if (isset($buyPrice['currency']['meta']['href'])) {
                $parentCurrencyId = $this->extractEntityId($buyPrice['currency']['meta']['href']);
                if ($parentCurrencyId) {
                    $childCurrencyId = $this->standardEntitySync->syncCurrency(
                        $mainAccountId,
                        $childAccountId,
                        $parentCurrencyId
                    );
                    if ($childCurrencyId) {
                        $buyPrice['currency'] = [
                            'meta' => [
                                'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                'type' => 'currency',
                                'mediaType' => 'application/json'
                            ]
                        ];
                    } else {
                        // Если валюта не найдена - удалить ссылку, МойСклад использует дефолтную
                        Log::warning('Failed to sync currency for buyPrice (no mappings), using default', [
                            'main_account_id' => $mainAccountId,
                            'child_account_id' => $childAccountId,
                            'parent_currency_id' => $parentCurrencyId
                        ]);
                        unset($buyPrice['currency']);
                    }
                }
            }
        }

        // Обработка продажных цен
        foreach ($mainSalePrices as $priceInfo) {
            $priceTypeHref = $priceInfo['priceType']['meta']['href'] ?? null;

            if (!$priceTypeHref) {
                continue;
            }

            // Извлечь ID типа цены из href
            $mainPriceTypeId = $this->extractEntityId($priceTypeHref);

            if (!$mainPriceTypeId) {
                continue;
            }

            // Если используются маппинги - проверить разрешен ли этот тип цены
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

                // Если тип цены не в маппинге - пропустить
                if (!$allowed) {
                    continue;
                }
            }

            // Если маппинг указан явно - использовать его
            if ($childPriceTypeId) {
                // salePrice → buyPrice
                if ($childPriceTypeId === 'buyPrice') {
                    $buyPrice = [
                        'value' => $priceInfo['value'] ?? 0
                    ];

                    // Синхронизировать валюту (приоритет: из priceInfo, потом из mainBuyPrice)
                    $currencySource = $priceInfo['currency'] ?? $mainBuyPrice['currency'] ?? null;
                    if ($currencySource && isset($currencySource['meta']['href'])) {
                        $parentCurrencyId = $this->extractEntityId($currencySource['meta']['href']);
                        if ($parentCurrencyId) {
                            $childCurrencyId = $this->standardEntitySync->syncCurrency(
                                $mainAccountId,
                                $childAccountId,
                                $parentCurrencyId
                            );
                            if ($childCurrencyId) {
                                $buyPrice['currency'] = [
                                    'meta' => [
                                        'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                        'type' => 'currency',
                                        'mediaType' => 'application/json'
                                    ]
                                ];
                            }
                        }
                    }
                } else {
                    // salePrice → salePrice
                    $priceData = [
                        'value' => $priceInfo['value'] ?? 0,
                        'priceType' => [
                            'meta' => [
                                'href' => config('moysklad.api_url') . "/context/companysettings/pricetype/{$childPriceTypeId}",
                                'type' => 'pricetype',
                                'mediaType' => 'application/json'
                            ]
                        ]
                    ];

                    // Синхронизировать валюту если указана
                    if (isset($priceInfo['currency']['meta']['href'])) {
                        $parentCurrencyId = $this->extractEntityId($priceInfo['currency']['meta']['href']);
                        if ($parentCurrencyId) {
                            $childCurrencyId = $this->standardEntitySync->syncCurrency(
                                $mainAccountId,
                                $childAccountId,
                                $parentCurrencyId
                            );
                            if ($childCurrencyId) {
                                $priceData['currency'] = [
                                    'meta' => [
                                        'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                        'type' => 'currency',
                                        'mediaType' => 'application/json'
                                    ]
                                ];
                            }
                        }
                    }

                    $salePrices[] = $priceData;
                }
            } else {
                // Иначе найти или создать тип цены по имени (старая логика)
                $priceTypeName = $priceInfo['priceType']['name'] ?? null;

                if (!$priceTypeName) {
                    continue;
                }

                $priceTypeMapping = $this->getOrCreatePriceType($mainAccountId, $childAccountId, $priceTypeName);

                if ($priceTypeMapping) {
                    $priceData = [
                        'value' => $priceInfo['value'] ?? 0,
                        'priceType' => [
                            'meta' => [
                                'href' => config('moysklad.api_url') . "/context/companysettings/pricetype/{$priceTypeMapping->child_price_type_id}",
                                'type' => 'pricetype',
                                'mediaType' => 'application/json'
                            ]
                        ]
                    ];

                    // Синхронизировать валюту если указана
                    if (isset($priceInfo['currency']['meta']['href'])) {
                        $parentCurrencyId = $this->extractEntityId($priceInfo['currency']['meta']['href']);
                        if ($parentCurrencyId) {
                            $childCurrencyId = $this->standardEntitySync->syncCurrency(
                                $mainAccountId,
                                $childAccountId,
                                $parentCurrencyId
                            );
                            if ($childCurrencyId) {
                                $priceData['currency'] = [
                                    'meta' => [
                                        'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                        'type' => 'currency',
                                        'mediaType' => 'application/json'
                                    ]
                                ];
                            }
                        }
                    }

                    $salePrices[] = $priceData;
                }
            }
        }

        $result = ['salePrices' => $salePrices];
        if ($buyPrice) {
            $result['buyPrice'] = $buyPrice;
        }

        return $result;
    }

    /**
     * Получить или создать тип цены
     */
    protected function getOrCreatePriceType(string $mainAccountId, string $childAccountId, string $priceTypeName): ?PriceTypeMapping
    {
        // Проверить маппинг
        $mapping = PriceTypeMapping::where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $childAccountId)
            ->where('price_type_name', $priceTypeName)
            ->first();

        if ($mapping) {
            return $mapping;
        }

        // Создать тип цены в дочернем (если включено auto_create_price_types)
        $settings = SyncSetting::where('account_id', $childAccountId)->first();

        if (!$settings || !$settings->auto_create_price_types) {
            return null;
        }

        try {
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            // Получить типы цен из главного
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

            // Создать в дочернем
            $childPriceTypesResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->post('context/companysettings/pricetype', [
                    'name' => $priceTypeName
                ]);

            $childPriceType = $childPriceTypesResult['data'];

            // Сохранить маппинг
            $mapping = PriceTypeMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'parent_price_type_id' => $mainPriceType['id'],
                'child_price_type_id' => $childPriceType['id'],
                'price_type_name' => $priceTypeName,
                'auto_created' => true,
            ]);

            Log::info('Price type created in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'price_type_name' => $priceTypeName
            ]);

            return $mapping;

        } catch (\Exception $e) {
            Log::error('Failed to create price type in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'price_type_name' => $priceTypeName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Проверить проходит ли товар фильтры
     */
    protected function passesFilters(array $product, SyncSetting $settings, string $mainAccountId): bool
    {
        // Если фильтры отключены - пропускаем все товары
        if (!$settings->product_filters_enabled) {
            return true;
        }

        // Получить конфигурацию фильтров
        $filters = $settings->product_filters;

        // Если фильтры не заданы - пропускаем все товары
        if (!$filters) {
            return true;
        }

        // Если это строка JSON - декодировать
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
        }

        // Применить фильтры
        return $this->productFilterService->passes($product, $filters);
    }

    /**
     * Синхронизировать модификацию (variant) из главного в дочерний аккаунт
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $variantId UUID модификации в главном аккаунте
     * @return array|null Созданная/обновленная модификация в дочернем аккаунте
     */
    public function syncVariant(string $mainAccountId, string $childAccountId, string $variantId): ?array
    {
        try {
            // Получить настройки синхронизации
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || !$settings->sync_products) {
                Log::debug('Variant sync is disabled', ['child_account_id' => $childAccountId]);
                return null;
            }

            // Получить модификацию из главного аккаунта
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $variantResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->get("entity/variant/{$variantId}", ['expand' => 'product,characteristics']);

            $variant = $variantResult['data'];

            // Проверить есть ли товар-родитель в дочернем аккаунте
            $productId = $this->extractEntityId($variant['product']['meta']['href'] ?? '');
            if (!$productId) {
                Log::warning('Cannot extract product ID from variant', ['variant_id' => $variantId]);
                return null;
            }

            $productMapping = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_entity_id', $productId)
                ->where('entity_type', 'product')
                ->first();

            if (!$productMapping) {
                Log::warning('Parent product not found in child account, syncing product first', [
                    'product_id' => $productId,
                    'variant_id' => $variantId
                ]);
                // Сначала синхронизировать товар-родитель
                $this->syncProduct($mainAccountId, $childAccountId, $productId);

                // Повторно получить маппинг
                $productMapping = EntityMapping::where('parent_account_id', $mainAccountId)
                    ->where('child_account_id', $childAccountId)
                    ->where('parent_entity_id', $productId)
                    ->where('entity_type', 'product')
                    ->first();

                if (!$productMapping) {
                    return null;
                }
            }

            // Проверить маппинг модификации
            $variantMapping = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_entity_id', $variantId)
                ->where('entity_type', 'variant')
                ->first();

            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            if ($variantMapping) {
                // Модификация уже существует, обновляем
                return $this->updateVariant($childAccount, $mainAccountId, $childAccountId, $variant, $productMapping, $variantMapping, $settings);
            } else {
                // Создаем новую модификацию
                return $this->createVariant($childAccount, $mainAccountId, $childAccountId, $variant, $productMapping, $settings);
            }

        } catch (\Exception $e) {
            Log::error('Failed to sync variant', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'variant_id' => $variantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Создать модификацию в дочернем аккаунте
     */
    protected function createVariant(
        Account $childAccount,
        string $mainAccountId,
        string $childAccountId,
        array $variant,
        EntityMapping $productMapping,
        SyncSetting $settings
    ): array {
        $variantData = [
            'name' => $variant['name'],
            'code' => $variant['code'] ?? null,
            'externalCode' => $variant['externalCode'] ?? null,
            'product' => [
                'meta' => [
                    'href' => config('moysklad.api_url') . "/entity/product/{$productMapping->child_entity_id}",
                    'type' => 'product',
                    'mediaType' => 'application/json'
                ]
            ],
        ];

        // Добавить штрихкоды
        if (isset($variant['barcodes'])) {
            $variantData['barcodes'] = $variant['barcodes'];
        }

        // Синхронизировать характеристики
        if (isset($variant['characteristics'])) {
            $variantData['characteristics'] = $this->syncCharacteristics(
                $mainAccountId,
                $childAccountId,
                $variant['characteristics']
            );
        }

        // Синхронизировать цены
        $prices = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $variant,
            $settings
        );
        $variantData['salePrices'] = $prices['salePrices'];
        if (isset($prices['buyPrice'])) {
            $variantData['buyPrice'] = $prices['buyPrice'];
        }

        // Создать модификацию
        $newVariantResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->post('entity/variant', $variantData);

        $newVariant = $newVariantResult['data'];

        // Сохранить маппинг
        EntityMapping::create([
            'parent_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'entity_type' => 'variant',
            'parent_entity_id' => $variant['id'],
            'child_entity_id' => $newVariant['id'],
            'sync_direction' => 'main_to_child',
        ]);

        Log::info('Variant created in child account', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_variant_id' => $variant['id'],
            'child_variant_id' => $newVariant['id']
        ]);

        return $newVariant;
    }

    /**
     * Обновить модификацию в дочернем аккаунте
     */
    protected function updateVariant(
        Account $childAccount,
        string $mainAccountId,
        string $childAccountId,
        array $variant,
        EntityMapping $productMapping,
        EntityMapping $variantMapping,
        SyncSetting $settings
    ): array {
        $variantData = [
            'name' => $variant['name'],
            'code' => $variant['code'] ?? null,
            'externalCode' => $variant['externalCode'] ?? null,
        ];

        // Штрихкоды
        if (isset($variant['barcodes'])) {
            $variantData['barcodes'] = $variant['barcodes'];
        }

        // Характеристики
        if (isset($variant['characteristics'])) {
            $variantData['characteristics'] = $this->syncCharacteristics(
                $mainAccountId,
                $childAccountId,
                $variant['characteristics']
            );
        }

        // Цены
        $prices = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $variant,
            $settings
        );
        $variantData['salePrices'] = $prices['salePrices'];
        if (isset($prices['buyPrice'])) {
            $variantData['buyPrice'] = $prices['buyPrice'];
        }

        // Обновить модификацию
        $updatedVariantResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->put("entity/variant/{$variantMapping->child_entity_id}", $variantData);

        Log::info('Variant updated in child account', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_variant_id' => $variant['id'],
            'child_variant_id' => $variantMapping->child_entity_id
        ]);

        return $updatedVariantResult['data'];
    }

    /**
     * Синхронизировать характеристики модификации
     */
    protected function syncCharacteristics(
        string $mainAccountId,
        string $childAccountId,
        array $characteristics
    ): array {
        $syncedCharacteristics = [];

        foreach ($characteristics as $characteristic) {
            $charName = $characteristic['name'] ?? null;
            $charValue = $characteristic['value'] ?? null;

            if (!$charName) {
                continue;
            }

            // Проверить маппинг характеристики
            $charMapping = CharacteristicMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('characteristic_name', $charName)
                ->first();

            if (!$charMapping) {
                // Создать характеристику в дочернем (через метаданные)
                $charMapping = $this->createCharacteristicInChild(
                    $mainAccountId,
                    $childAccountId,
                    $characteristic
                );
            }

            if ($charMapping) {
                $syncedCharacteristics[] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/variant/metadata/characteristics/{$charMapping->child_characteristic_id}",
                        'type' => 'attributemetadata',
                        'mediaType' => 'application/json'
                    ],
                    'value' => $charValue
                ];
            }
        }

        return $syncedCharacteristics;
    }

    /**
     * Создать характеристику в дочернем аккаунте
     */
    protected function createCharacteristicInChild(
        string $mainAccountId,
        string $childAccountId,
        array $characteristic
    ): ?CharacteristicMapping {
        try {
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            $charData = [
                'name' => $characteristic['name'],
                'type' => $characteristic['type'] ?? 'string',
                'required' => $characteristic['required'] ?? false,
            ];

            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->post('entity/variant/metadata/characteristics', $charData);

            $newChar = $result['data'];

            // Сохранить маппинг
            $mapping = CharacteristicMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'parent_characteristic_id' => $characteristic['id'],
                'child_characteristic_id' => $newChar['id'],
                'characteristic_name' => $characteristic['name'],
                'auto_created' => true,
            ]);

            Log::info('Characteristic created in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'characteristic_name' => $characteristic['name']
            ]);

            return $mapping;

        } catch (\Exception $e) {
            Log::error('Failed to create characteristic in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'characteristic' => $characteristic,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Синхронизировать комплект (bundle) из главного в дочерний аккаунт
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $bundleId UUID комплекта в главном аккаунте
     * @return array|null Созданный/обновленный комплект в дочернем аккаунте
     */
    public function syncBundle(string $mainAccountId, string $childAccountId, string $bundleId): ?array
    {
        try {
            // Получить настройки синхронизации
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || !$settings->sync_products) {
                Log::debug('Bundle sync is disabled', ['child_account_id' => $childAccountId]);
                return null;
            }

            // Получить комплект из главного аккаунта
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $bundleResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->get("entity/bundle/{$bundleId}", ['expand' => 'components.assortment']);

            $bundle = $bundleResult['data'];

            // Проверить фильтры
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

            if ($mapping) {
                // Комплект уже существует, обновляем
                return $this->updateBundle($childAccount, $mainAccountId, $childAccountId, $bundle, $mapping, $settings);
            } else {
                // Создаем новый комплект
                return $this->createBundle($childAccount, $mainAccountId, $childAccountId, $bundle, $settings);
            }

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

        // Синхронизировать компоненты комплекта
        if (isset($bundle['components'])) {
            $bundleData['components'] = $this->syncBundleComponents(
                $mainAccountId,
                $childAccountId,
                $bundle['components']
            );
        }

        // Создать комплект
        $newBundleResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
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
            'match_field' => $settings->product_match_field ?? 'article',
            'match_value' => $bundle[$settings->product_match_field ?? 'article'] ?? null,
        ]);

        Log::info('Bundle created in child account', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'main_bundle_id' => $bundle['id'],
            'child_bundle_id' => $newBundle['id']
        ]);

        return $newBundle;
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

        // Компоненты комплекта
        if (isset($bundle['components'])) {
            $bundleData['components'] = $this->syncBundleComponents(
                $mainAccountId,
                $childAccountId,
                $bundle['components']
            );
        }

        // Обновить комплект
        $updatedBundleResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
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
                    $this->syncProduct($mainAccountId, $childAccountId, $entityId);
                } elseif ($entityType === 'variant') {
                    $this->syncVariant($mainAccountId, $childAccountId, $entityId);
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
     * Синхронизировать группу товаров (productFolder)
     * Рекурсивно создает всю иерархию групп
     */
    protected function syncProductFolder(string $mainAccountId, string $childAccountId, string $folderId): ?string
    {
        try {
            // Проверить маппинг группы
            $mapping = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_entity_id', $folderId)
                ->where('entity_type', 'productfolder')
                ->first();

            if ($mapping) {
                return $mapping->child_entity_id;
            }

            // Получить группу из главного аккаунта
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $folderResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->get("entity/productfolder/{$folderId}");

            $folder = $folderResult['data'];

            // Подготовить данные для создания
            $folderData = [
                'name' => $folder['name'],
                'externalCode' => $folder['externalCode'] ?? null,
            ];

            // Если у группы есть родитель - синхронизировать его сначала (рекурсия)
            if (isset($folder['productFolder'])) {
                $parentFolderHref = $folder['productFolder']['meta']['href'] ?? null;
                if ($parentFolderHref) {
                    $parentFolderId = $this->extractEntityId($parentFolderHref);
                    if ($parentFolderId) {
                        $childParentFolderId = $this->syncProductFolder($mainAccountId, $childAccountId, $parentFolderId);
                        if ($childParentFolderId) {
                            $folderData['productFolder'] = [
                                'meta' => [
                                    'href' => config('moysklad.api_url') . "/entity/productfolder/{$childParentFolderId}",
                                    'type' => 'productfolder',
                                    'mediaType' => 'application/json'
                                ]
                            ];
                        }
                    }
                }
            }

            // Создать группу в дочернем аккаунте
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
            $newFolderResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->post('entity/productfolder', $folderData);

            $newFolder = $newFolderResult['data'];

            // Сохранить маппинг
            EntityMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'productfolder',
                'parent_entity_id' => $folderId,
                'child_entity_id' => $newFolder['id'],
                'sync_direction' => 'main_to_child',
            ]);

            Log::info('Product folder created in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'main_folder_id' => $folderId,
                'child_folder_id' => $newFolder['id'],
                'folder_name' => $folder['name']
            ]);

            return $newFolder['id'];

        } catch (\Exception $e) {
            Log::error('Failed to sync product folder', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'folder_id' => $folderId,
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
     * Архивировать товар в дочерних аккаунтах (при удалении или архивации в главном)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $productId UUID товара в главном аккаунте
     * @return int Количество архивированных товаров
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
                        ->put("entity/product/{$mapping->child_entity_id}", [
                            'archived' => true
                        ]);

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
     * Архивировать модификацию в дочерних аккаунтах (при удалении или архивации в главном)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $variantId UUID модификации в главном аккаунте
     * @return int Количество архивированных модификаций
     */
    public function archiveVariant(string $mainAccountId, string $variantId): int
    {
        try {
            // Найти все маппинги этой модификации
            $mappings = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('parent_entity_id', $variantId)
                ->where('entity_type', 'variant')
                ->where('sync_direction', 'main_to_child')
                ->get();

            if ($mappings->isEmpty()) {
                Log::debug('No mappings found for variant archive', [
                    'main_account_id' => $mainAccountId,
                    'variant_id' => $variantId
                ]);
                return 0;
            }

            $archivedCount = 0;

            foreach ($mappings as $mapping) {
                try {
                    $childAccount = Account::where('account_id', $mapping->child_account_id)->first();

                    if (!$childAccount) {
                        Log::warning('Child account not found for variant archive', [
                            'child_account_id' => $mapping->child_account_id
                        ]);
                        continue;
                    }

                    // Архивировать модификацию в дочернем аккаунте
                    $this->moySkladService
                        ->setAccessToken($childAccount->access_token)
                        ->put("entity/variant/{$mapping->child_entity_id}", [
                            'archived' => true
                        ]);

                    $archivedCount++;

                    Log::info('Variant archived in child account', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $mapping->child_account_id,
                        'main_variant_id' => $variantId,
                        'child_variant_id' => $mapping->child_entity_id
                    ]);

                } catch (\Exception $e) {
                    Log::error('Failed to archive variant in child account', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $mapping->child_account_id,
                        'variant_id' => $variantId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $archivedCount;

        } catch (\Exception $e) {
            Log::error('Failed to archive variant', [
                'main_account_id' => $mainAccountId,
                'variant_id' => $variantId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Архивировать комплект в дочерних аккаунтах (при удалении или архивации в главном)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $bundleId UUID комплекта в главном аккаунте
     * @return int Количество архивированных комплектов
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
}
