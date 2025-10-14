<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use App\Models\AttributeMapping;
use App\Models\CharacteristicMapping;
use App\Models\PriceTypeMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации товаров из главного в дочерние аккаунты
 */
class ProductSyncService
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

            // Получить товар из главного аккаунта
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $productResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->get("entity/product/{$productId}", ['expand' => 'attributes']);

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

        // Синхронизировать цены
        $productData['salePrices'] = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $product,
            $settings
        );

        // Создать товар
        $newProductResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->post('entity/product', $productData);

        $newProduct = $newProductResult['data'];

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

        // Цены
        $productData['salePrices'] = $this->syncPrices(
            $mainAccountId,
            $childAccountId,
            $product,
            $settings
        );

        // Обновить товар
        $updatedProductResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->put("entity/product/{$mapping->child_entity_id}", $productData);

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

        foreach ($attributes as $attribute) {
            // Проверить маппинг атрибута
            $attributeName = $attribute['name'] ?? null;
            $attributeType = $attribute['type'] ?? null;

            if (!$attributeName || !$attributeType) {
                continue;
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

        // Получить цены из главного товара
        $mainSalePrices = $product['salePrices'] ?? [];

        foreach ($mainSalePrices as $priceInfo) {
            $priceTypeName = $priceInfo['priceType'] ?? null;

            if (!$priceTypeName) {
                continue;
            }

            // Проверить нужно ли синхронизировать этот тип цены
            if ($settings->sale_price_type_id && $priceTypeName !== $settings->sale_price_type_id) {
                continue;
            }

            // Найти или создать тип цены в дочернем
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

        return $salePrices;
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
                ->get('context/companysettings/pricetype');

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
        // Если фильтры не настроены - пропускаем все товары
        if (!$settings->product_filter_type) {
            return true;
        }

        // TODO: Реализовать фильтрацию по доп.полям и группам товаров
        // Это будет в следующих итерациях

        return true;
    }
}
