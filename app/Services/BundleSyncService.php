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

    public function __construct(
        MoySkladService $moySkladService,
        CustomEntitySyncService $customEntitySyncService,
        StandardEntitySyncService $standardEntitySync,
        ProductFilterService $productFilterService,
        ProductSyncService $productSyncService,
        VariantSyncService $variantSyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->customEntitySyncService = $customEntitySyncService;
        $this->standardEntitySync = $standardEntitySync;
        $this->productFilterService = $productFilterService;
        $this->productSyncService = $productSyncService;
        $this->variantSyncService = $variantSyncService;
    }

    /**
     * Синхронизировать комплект из главного в дочерний аккаунт
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
            if (isset($bundle['attributes']) && is_array($bundle['attributes'])) {
                $attributesMetadata = $this->loadAttributesMetadata($mainAccountId);

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
