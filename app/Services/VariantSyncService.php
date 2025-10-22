<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\EntityMapping;
use App\Models\CharacteristicMapping;
use App\Services\Traits\SyncHelpers;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации модификаций (variants)
 *
 * Модификации зависят от родительских товаров (products)
 */
class VariantSyncService
{
    use SyncHelpers;

    protected MoySkladService $moySkladService;
    protected CustomEntitySyncService $customEntitySyncService;
    protected StandardEntitySyncService $standardEntitySync;
    protected ProductSyncService $productSyncService;

    public function __construct(
        MoySkladService $moySkladService,
        CustomEntitySyncService $customEntitySyncService,
        StandardEntitySyncService $standardEntitySync,
        ProductSyncService $productSyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->customEntitySyncService = $customEntitySyncService;
        $this->standardEntitySync = $standardEntitySync;
        $this->productSyncService = $productSyncService;
    }

    /**
     * Синхронизировать модификацию из главного в дочерний аккаунт
     */
    public function syncVariant(string $mainAccountId, string $childAccountId, string $variantId): ?array
    {
        try {
            // Получить настройки синхронизации
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || !$settings->sync_variants) {
                Log::debug('Variant sync is disabled', ['child_account_id' => $childAccountId]);
                return null;
            }

            // Получить модификацию из главного аккаунта
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $variantResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->setLogContext(
                    accountId: $mainAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $childAccountId,
                    entityType: 'variant',
                    entityId: $variantId
                )
                ->get("entity/variant/{$variantId}", ['expand' => 'product.salePrices,characteristics,packs.uom,images']);

            $variant = $variantResult['data'];

            // Variants сопоставляются через родительский товар (product mapping)
            // Проверка match field НЕ нужна, т.к. variants не имеют поля article
            // и уникально идентифицируются через product + characteristics

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
                $this->productSyncService->syncProduct($mainAccountId, $childAccountId, $productId);

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

            $result = null;
            if ($variantMapping) {
                // Модификация уже существует, обновляем
                $result = $this->updateVariant($childAccount, $mainAccountId, $childAccountId, $variant, $productMapping, $variantMapping, $settings);
            } else {
                // Создаем новую модификацию
                $result = $this->createVariant($childAccount, $mainAccountId, $childAccountId, $variant, $productMapping, $settings);
            }

            // Синхронизировать изображения (если включено)
            if ($result && ($settings->sync_images || $settings->sync_images_all) && isset($variant['images']['rows']) && !empty($variant['images']['rows'])) {
                $this->queueImageSync($mainAccountId, $childAccountId, 'variant', $variantId, $result['id'], $variant['images']['rows'], $settings);
            }

            return $result;

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
     * Синхронизировать variant с уже загруженными данными (без GET запроса)
     *
     * Используется для пакетной синхронизации, когда variant уже загружен
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param array $variant Уже загруженные данные variant (с expand)
     * @return array|null Созданный/обновленный variant
     */
    public function syncVariantData(string $mainAccountId, string $childAccountId, array $variant): ?array
    {
        try {
            // Получить настройки синхронизации
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || !$settings->sync_variants) {
                Log::debug('Variant sync is disabled', ['child_account_id' => $childAccountId]);
                return null;
            }

            $variantId = $variant['id'];

            // Variants сопоставляются через родительский товар (product mapping)
            // Проверка match field НЕ нужна

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
                Log::warning('Parent product not found in child account, skipping variant', [
                    'product_id' => $productId,
                    'variant_id' => $variantId
                ]);
                return null;
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
            Log::error('Failed to sync variant data', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'variant_id' => $variant['id'] ?? 'unknown',
                'error' => $e->getMessage()
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
            'description' => $variant['description'] ?? null,
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

        // Проверить, имеет ли variant собственные цены (отличные от parent product)
        $mainProduct = $variant['product'] ?? null;
        $hasCustomPrices = false;

        if ($mainProduct && isset($mainProduct['salePrices'])) {
            $hasCustomPrices = $this->variantHasCustomPrices($variant, $mainProduct);
        } else {
            // Если parent product не expand-нут - безопасный fallback (синхронизируем цены)
            $hasCustomPrices = true;
            Log::warning('Parent product not expanded in variant, assuming custom prices', [
                'child_account_id' => $childAccountId,
                'main_variant_id' => $variant['id']
            ]);
        }

        Log::debug('Variant custom prices check (create)', [
            'child_account_id' => $childAccountId,
            'main_variant_id' => $variant['id'],
            'has_custom_prices' => $hasCustomPrices,
            'variant_prices_count' => count($variant['salePrices'] ?? []),
            'product_prices_count' => count($mainProduct['salePrices'] ?? [])
        ]);

        // Синхронизировать цены ТОЛЬКО если variant имеет собственные цены
        if ($hasCustomPrices) {
            // Цены (используя трейт SyncHelpers)
            $prices = $this->syncPrices(
                $mainAccountId,
                $childAccountId,
                $variant,
                $settings
            );

            Log::debug('Variant prices synced (custom prices detected, create)', [
                'child_account_id' => $childAccountId,
                'main_variant_id' => $variant['id'],
                'main_sale_prices_count' => count($variant['salePrices'] ?? []),
                'synced_sale_prices_count' => count($prices['salePrices']),
                'has_buy_price' => isset($prices['buyPrice']),
                'price_mappings_enabled' => !empty($settings->price_mappings)
            ]);

            $variantData['salePrices'] = $prices['salePrices'];
            if (isset($prices['buyPrice'])) {
                $variantData['buyPrice'] = $prices['buyPrice'];
            }
        } else {
            // Variant наследует цены от product - НЕ отправляем salePrices/buyPrice
            Log::debug('Variant created without custom prices (inherits from product)', [
                'child_account_id' => $childAccountId,
                'main_variant_id' => $variant['id'],
                'variant_prices_match_product' => true
            ]);
            // НЕ добавляем salePrices и buyPrice в $variantData
        }

        // Синхронизировать упаковки (если есть)
        // Для variant используем UOM родительского товара (product.uom)
        if (isset($variant['packs']) && !empty($variant['packs'])) {
            $baseUomId = $this->extractEntityId($variant['product']['uom']['meta']['href'] ?? '');
            $variantData['packs'] = $this->productSyncService->syncPacks(
                $mainAccountId,
                $childAccountId,
                $variant['packs'],
                $baseUomId
            );
        }

        // Добавить дополнительные поля (НДС, физ.характеристики, маркировка и т.д.)
        // Используем метод из ProductSyncService через композицию
        $variantData = $this->productSyncService->addAdditionalFields($variantData, $variant, $settings);

        // Создать модификацию
        $newVariantResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->setLogContext(
                accountId: $childAccountId,
                direction: 'main_to_child',
                relatedAccountId: $mainAccountId,
                entityType: 'variant',
                entityId: $variant['id']
            )
            ->setOperationContext(
                operationType: 'create',
                operationResult: 'success'
            )
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
            'description' => $variant['description'] ?? null,
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

        // Проверить, имеет ли variant собственные цены (отличные от parent product)
        $mainProduct = $variant['product'] ?? null;
        $hasCustomPrices = false;

        if ($mainProduct && isset($mainProduct['salePrices'])) {
            $hasCustomPrices = $this->variantHasCustomPrices($variant, $mainProduct);
        } else {
            // Если parent product не expand-нут - безопасный fallback (синхронизируем цены)
            $hasCustomPrices = true;
            Log::warning('Parent product not expanded in variant, assuming custom prices', [
                'child_account_id' => $childAccountId,
                'main_variant_id' => $variant['id']
            ]);
        }

        Log::debug('Variant custom prices check', [
            'child_account_id' => $childAccountId,
            'main_variant_id' => $variant['id'],
            'child_variant_id' => $variantMapping->child_entity_id,
            'has_custom_prices' => $hasCustomPrices,
            'variant_prices_count' => count($variant['salePrices'] ?? []),
            'product_prices_count' => count($mainProduct['salePrices'] ?? [])
        ]);

        // Синхронизировать цены ТОЛЬКО если variant имеет собственные цены
        if ($hasCustomPrices) {
            // Цены (используя трейт SyncHelpers)
            $prices = $this->syncPrices(
                $mainAccountId,
                $childAccountId,
                $variant,
                $settings
            );

            // Логировать синхронизированные цены для отладки
            Log::debug('Variant prices synced (custom prices detected)', [
                'child_account_id' => $childAccountId,
                'main_variant_id' => $variant['id'],
                'child_variant_id' => $variantMapping->child_entity_id,
                'main_sale_prices_count' => count($variant['salePrices'] ?? []),
                'synced_sale_prices_count' => count($prices['salePrices']),
                'has_buy_price' => isset($prices['buyPrice']),
                'synced_sale_prices' => $prices['salePrices'],
                'price_mappings_enabled' => !empty($settings->price_mappings)
            ]);

            // Если salePrices пустой - НЕ отправляем (МойСклад оставит как есть)
            if (!empty($prices['salePrices'])) {
                $variantData['salePrices'] = $prices['salePrices'];
            }

            // Если buyPrice есть - отправляем
            if (isset($prices['buyPrice'])) {
                $variantData['buyPrice'] = $prices['buyPrice'];
            }
        } else {
            // Variant наследует цены от product - НЕ отправляем salePrices/buyPrice
            Log::debug('Variant prices skipped (inherits from product)', [
                'child_account_id' => $childAccountId,
                'main_variant_id' => $variant['id'],
                'child_variant_id' => $variantMapping->child_entity_id,
                'variant_prices_match_product' => true
            ]);
            // НЕ добавляем salePrices и buyPrice в $variantData
        }

        // Синхронизировать упаковки (если есть)
        // Для variant используем UOM родительского товара (product.uom)
        if (isset($variant['packs']) && !empty($variant['packs'])) {
            $baseUomId = $this->extractEntityId($variant['product']['uom']['meta']['href'] ?? '');
            $variantData['packs'] = $this->productSyncService->syncPacks(
                $mainAccountId,
                $childAccountId,
                $variant['packs'],
                $baseUomId
            );
        }

        // Добавить дополнительные поля (НДС, физ.характеристики, маркировка и т.д.)
        // Используем метод из ProductSyncService через композицию
        $variantData = $this->productSyncService->addAdditionalFields($variantData, $variant, $settings);

        // Обновить модификацию
        $updatedVariantResult = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->setLogContext(
                accountId: $childAccountId,
                direction: 'main_to_child',
                relatedAccountId: $mainAccountId,
                entityType: 'variant',
                entityId: $variant['id']
            )
            ->setOperationContext(
                operationType: 'update',
                operationResult: 'success'
            )
            ->put("entity/variant/{$variantMapping->child_entity_id}", $variantData);

        // Логировать отправленные данные
        Log::debug('Variant update request sent', [
            'child_account_id' => $childAccountId,
            'child_variant_id' => $variantMapping->child_entity_id,
            'request_data' => $variantData,
            'has_sale_prices' => isset($variantData['salePrices']) && !empty($variantData['salePrices']),
            'sale_prices_count' => count($variantData['salePrices'] ?? [])
        ]);

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
                    'id' => $charMapping->child_characteristic_id,
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
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $mainAccountId,
                    entityType: 'characteristic',
                    entityId: null
                )
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
     * Архивировать модификацию в дочерних аккаунтах
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
                        ->setLogContext(
                            accountId: $mainAccountId,
                            direction: 'main_to_child',
                            relatedAccountId: $mapping->child_account_id,
                            entityType: 'variant',
                            entityId: $variantId
                        )
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
     * Проверить, отличаются ли цены variant от parent product
     *
     * @param array $variant Данные variant из main аккаунта
     * @param array $parentProduct Данные parent product из main аккаунта
     * @return bool true если цены отличаются (variant имеет собственные цены)
     */
    protected function variantHasCustomPrices(array $variant, array $parentProduct): bool
    {
        $variantPrices = $variant['salePrices'] ?? [];
        $productPrices = $parentProduct['salePrices'] ?? [];

        // Если количество цен разное - точно отличаются
        if (count($variantPrices) !== count($productPrices)) {
            return true;
        }

        // Если обе пустые - нет собственных цен
        if (empty($variantPrices) && empty($productPrices)) {
            return false;
        }

        // Сравнить каждую цену по priceType ID и value
        foreach ($variantPrices as $variantPrice) {
            $priceTypeId = $this->extractEntityId($variantPrice['priceType']['meta']['href'] ?? '');
            if (!$priceTypeId) {
                continue;
            }

            $variantValue = $variantPrice['value'] ?? 0;

            // Найти соответствующую цену в product
            $matchingProductPrice = null;
            foreach ($productPrices as $productPrice) {
                $productPriceTypeId = $this->extractEntityId($productPrice['priceType']['meta']['href'] ?? '');
                if ($productPriceTypeId === $priceTypeId) {
                    $matchingProductPrice = $productPrice;
                    break;
                }
            }

            // Если не нашли соответствующую цену или значения отличаются
            if (!$matchingProductPrice || ($matchingProductPrice['value'] ?? 0) !== $variantValue) {
                return true; // Цены отличаются
            }
        }

        // Также проверить buyPrice
        $variantBuyPrice = $variant['buyPrice']['value'] ?? null;
        $productBuyPrice = $parentProduct['buyPrice']['value'] ?? null;

        // Если оба null - одинаковые
        if ($variantBuyPrice === null && $productBuyPrice === null) {
            // Продолжаем проверку
        }
        // Если один null, другой нет - отличаются
        elseif ($variantBuyPrice === null || $productBuyPrice === null) {
            return true;
        }
        // Если оба не null - сравниваем значения
        elseif ($variantBuyPrice !== $productBuyPrice) {
            return true;
        }

        // Цены идентичны - variant наследует от product
        return false;
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
                    'status' => 'pending',
                    'attempts' => 0,
                    'scheduled_at' => now(),
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
