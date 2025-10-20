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
                ->get("entity/variant/{$variantId}", ['expand' => 'product,characteristics,packs.uom']);

            $variant = $variantResult['data'];

            // Проверить, что поле сопоставления заполнено
            $matchField = $settings->product_match_field ?? 'article';
            if (in_array($matchField, ['article', 'code', 'externalCode']) && empty($variant[$matchField])) {
                Log::warning('Variant skipped: match field is empty', [
                    'variant_id' => $variantId,
                    'child_account_id' => $childAccountId,
                    'match_field' => $matchField,
                    'variant_name' => $variant['name'] ?? 'unknown'
                ]);
                return null;
            }

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

        // Синхронизировать цены (используя трейт SyncHelpers)
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

        // Цены (используя трейт SyncHelpers)
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
}
