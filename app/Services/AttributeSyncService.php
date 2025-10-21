<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\AttributeMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для универсальной синхронизации атрибутов (дополнительных полей)
 *
 * Поддерживает:
 * - Синхронизацию в обоих направлениях (main→child и child→main)
 * - Любые типы сущностей (product, service, customerorder, и т.д.)
 * - Атрибуты типа customentity (справочники)
 * - Фильтрацию по настройкам
 *
 * Используется в:
 * - ProductSyncService
 * - ServiceSyncService
 * - BundleSyncService
 * - CustomerOrderSyncService
 */
class AttributeSyncService
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
     * Синхронизировать атрибуты
     *
     * @param string $sourceAccountId UUID аккаунта-источника (откуда берём атрибуты)
     * @param string $targetAccountId UUID целевого аккаунта (куда синхронизируем)
     * @param string $settingsAccountId UUID аккаунта, из настроек которого берём фильтр
     * @param string $entityType Тип сущности (product, service, customerorder, и т.д.)
     * @param array $attributes Массив атрибутов из МойСклад API
     * @param string $direction Направление: 'main_to_child' или 'child_to_main'
     * @return array Синхронизированные атрибуты для отправки в API
     */
    public function syncAttributes(
        string $sourceAccountId,
        string $targetAccountId,
        string $settingsAccountId,
        string $entityType,
        array $attributes,
        string $direction = 'main_to_child'
    ): array {
        $syncedAttributes = [];

        // Получить настройки для фильтрации атрибутов
        $settings = SyncSetting::where('account_id', $settingsAccountId)->first();
        $attributeSyncList = $settings && $settings->attribute_sync_list ? $settings->attribute_sync_list : null;

        // Если список ПУСТОЙ → НЕ синхронизировать доп.поля вообще
        if (empty($attributeSyncList) || !is_array($attributeSyncList)) {
            Log::debug('Attribute sync disabled: attribute_sync_list is empty', [
                'entity_type' => $entityType,
                'settings_account_id' => $settingsAccountId,
                'direction' => $direction
            ]);
            return [];
        }

        Log::info('Starting attribute sync', [
            'source_account_id' => $sourceAccountId,
            'target_account_id' => $targetAccountId,
            'settings_account_id' => $settingsAccountId,
            'entity_type' => $entityType,
            'total_attributes' => count($attributes),
            'attribute_sync_list' => $attributeSyncList,
            'direction' => $direction
        ]);

        foreach ($attributes as $attribute) {
            // Проверить базовые поля атрибута
            $attributeName = $attribute['name'] ?? null;
            $attributeType = $attribute['type'] ?? null;
            $attributeId = $attribute['id'] ?? null;

            if (!$attributeName || !$attributeType || !$attributeId) {
                continue;
            }

            // Синхронизировать только атрибуты из списка разрешенных
            if (!in_array($attributeId, $attributeSyncList)) {
                Log::debug('Skipping non-selected attribute', [
                    'attribute_name' => $attributeName,
                    'attribute_id' => $attributeId
                ]);
                continue;
            }

            // Найти или создать маппинг атрибута
            $attributeMapping = $this->findOrCreateAttributeMapping(
                $sourceAccountId,
                $targetAccountId,
                $entityType,
                $attribute,
                $direction
            );

            if (!$attributeMapping) {
                continue;
            }

            // Подготовить значение
            $value = $attribute['value'] ?? null;

            // Если тип customentity - синхронизировать элемент справочника
            if ($attributeType === 'customentity' && $value) {
                $value = $this->syncCustomEntityValue(
                    $sourceAccountId,
                    $targetAccountId,
                    $value,
                    $direction
                );
            }

            // Определить ID атрибута в целевом аккаунте
            $targetAttributeId = ($direction === 'main_to_child')
                ? $attributeMapping->child_attribute_id
                : $attributeMapping->parent_attribute_id;

            $syncedAttributes[] = [
                'meta' => [
                    'href' => config('moysklad.api_url') . "/entity/{$entityType}/metadata/attributes/{$targetAttributeId}",
                    'type' => 'attributemetadata',
                    'mediaType' => 'application/json'
                ],
                'value' => $value
            ];
        }

        return $syncedAttributes;
    }

    /**
     * Найти или создать маппинг атрибута
     *
     * @param string $sourceAccountId UUID аккаунта-источника
     * @param string $targetAccountId UUID целевого аккаунта
     * @param string $entityType Тип сущности
     * @param array $attribute Атрибут из API
     * @param string $direction Направление синхронизации
     * @return AttributeMapping|null
     */
    protected function findOrCreateAttributeMapping(
        string $sourceAccountId,
        string $targetAccountId,
        string $entityType,
        array $attribute,
        string $direction
    ): ?AttributeMapping {
        // Определить parent/child в зависимости от direction
        // Таблица attribute_mappings всегда хранит: parent_account_id = main, child_account_id = child
        if ($direction === 'main_to_child') {
            $parentAccountId = $sourceAccountId;  // main
            $childAccountId = $targetAccountId;   // child
        } else {
            $parentAccountId = $targetAccountId;  // main
            $childAccountId = $sourceAccountId;   // child
        }

        $attributeName = $attribute['name'];
        $attributeType = $attribute['type'];

        // Искать существующий маппинг
        $mapping = AttributeMapping::where('parent_account_id', $parentAccountId)
            ->where('child_account_id', $childAccountId)
            ->where('entity_type', $entityType)
            ->where('attribute_name', $attributeName)
            ->where('attribute_type', $attributeType)
            ->first();

        if ($mapping) {
            return $mapping;
        }

        // Создать атрибут в ЦЕЛЕВОМ аккаунте
        return $this->createAttributeInTarget(
            $sourceAccountId,
            $targetAccountId,
            $entityType,
            $attribute,
            $direction
        );
    }

    /**
     * Создать атрибут в целевом аккаунте
     *
     * @param string $sourceAccountId UUID аккаунта-источника
     * @param string $targetAccountId UUID целевого аккаунта
     * @param string $entityType Тип сущности
     * @param array $attribute Атрибут из источника
     * @param string $direction Направление синхронизации
     * @return AttributeMapping|null
     */
    protected function createAttributeInTarget(
        string $sourceAccountId,
        string $targetAccountId,
        string $entityType,
        array $attribute,
        string $direction
    ): ?AttributeMapping {
        try {
            $targetAccount = Account::where('account_id', $targetAccountId)->firstOrFail();

            // Определить parent/child для маппинга
            if ($direction === 'main_to_child') {
                $parentAccountId = $sourceAccountId;
                $childAccountId = $targetAccountId;
            } else {
                $parentAccountId = $targetAccountId;
                $childAccountId = $sourceAccountId;
            }

            Log::info('Creating attribute in target account', [
                'source_account_id' => $sourceAccountId,
                'target_account_id' => $targetAccountId,
                'entity_type' => $entityType,
                'attribute_name' => $attribute['name'],
                'attribute_type' => $attribute['type'],
                'direction' => $direction,
                'has_customEntityMeta' => isset($attribute['customEntityMeta'])
            ]);

            $attributeData = [
                'name' => $attribute['name'],
                'type' => $attribute['type'],
                'required' => $attribute['required'] ?? false,
            ];

            // Для customentity нужно синхронизировать сам справочник
            if ($attribute['type'] === 'customentity') {
                $customEntityName = null;

                // Попытка 1: Извлечь name из customEntityMeta (если уже загружено)
                if (isset($attribute['customEntityMeta']['name'])) {
                    $customEntityName = $attribute['customEntityMeta']['name'];
                }
                // Попытка 2: Загрузить metadata customEntity по href
                elseif (isset($attribute['customEntityMeta']['href'])) {
                    $customEntityId = $this->extractEntityId($attribute['customEntityMeta']['href']);
                    if ($customEntityId) {
                        $metadata = $this->loadCustomEntityMetadataById($sourceAccountId, $customEntityId);
                        $customEntityName = $metadata['name'] ?? null;
                    }
                }

                if (!$customEntityName) {
                    Log::error('Cannot sync customentity attribute: failed to extract custom entity name', [
                        'source_account_id' => $sourceAccountId,
                        'target_account_id' => $targetAccountId,
                        'attribute' => $attribute,
                        'has_href' => isset($attribute['customEntityMeta']['href']),
                        'href' => $attribute['customEntityMeta']['href'] ?? null
                    ]);
                    return null;
                }

                Log::info('Syncing custom entity for attribute', [
                    'source_account_id' => $sourceAccountId,
                    'target_account_id' => $targetAccountId,
                    'custom_entity_name' => $customEntityName,
                    'attribute_name' => $attribute['name'],
                    'direction' => $direction
                ]);

                // Синхронизировать справочник (всегда main→child для справочников)
                $syncedEntity = $this->customEntitySyncService->syncCustomEntity(
                    $parentAccountId,  // main всегда parent для справочников
                    $childAccountId,   // child всегда child для справочников
                    $customEntityName
                );

                // Определить ID справочника в целевом аккаунте
                $targetCustomEntityId = ($direction === 'main_to_child')
                    ? $syncedEntity['child_id']
                    : $syncedEntity['parent_id'];

                // customEntityMeta должен указывать на метаданные справочника в целевом аккаунте
                $attributeData['customEntityMeta'] = [
                    'href' => config('moysklad.api_url') . "/context/companysettings/metadata/customEntities/{$targetCustomEntityId}",
                    'type' => 'customentitymetadata',
                    'mediaType' => 'application/json'
                ];
            }

            // Создать атрибут в целевом аккаунте
            $result = $this->moySkladService
                ->setAccessToken($targetAccount->access_token)
                ->post("entity/{$entityType}/metadata/attributes", $attributeData);

            $newAttribute = $result['data'];

            // Сохранить маппинг
            // parent_attribute_id и child_attribute_id зависят от direction
            if ($direction === 'main_to_child') {
                $parentAttributeId = $attribute['id'];       // ID в main (source)
                $childAttributeId = $newAttribute['id'];     // ID в child (target)
            } else {
                $parentAttributeId = $newAttribute['id'];    // ID в main (target)
                $childAttributeId = $attribute['id'];        // ID в child (source)
            }

            $mapping = AttributeMapping::create([
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'parent_attribute_id' => $parentAttributeId,
                'child_attribute_id' => $childAttributeId,
                'attribute_name' => $attribute['name'],
                'attribute_type' => $attribute['type'],
                'is_synced' => true,
                'auto_created' => true,
            ]);

            Log::info('Attribute created in target account', [
                'source_account_id' => $sourceAccountId,
                'target_account_id' => $targetAccountId,
                'attribute_name' => $attribute['name'],
                'attribute_type' => $attribute['type'],
                'direction' => $direction
            ]);

            return $mapping;

        } catch (\Exception $e) {
            Log::error('Failed to create attribute in target account', [
                'source_account_id' => $sourceAccountId,
                'target_account_id' => $targetAccountId,
                'attribute' => $attribute,
                'direction' => $direction,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Синхронизировать значение атрибута типа customentity
     *
     * @param string $sourceAccountId UUID аккаунта-источника
     * @param string $targetAccountId UUID целевого аккаунта
     * @param array $value Значение атрибута (с meta)
     * @param string $direction Направление синхронизации
     * @return array Синхронизированное значение
     */
    protected function syncCustomEntityValue(
        string $sourceAccountId,
        string $targetAccountId,
        array $value,
        string $direction
    ): array {
        // Определить parent/child для справочников (всегда main→child)
        if ($direction === 'main_to_child') {
            $parentAccountId = $sourceAccountId;
            $childAccountId = $targetAccountId;
        } else {
            $parentAccountId = $targetAccountId;
            $childAccountId = $sourceAccountId;
        }

        // Использовать CustomEntitySyncService для синхронизации значения
        return $this->customEntitySyncService->syncAttributeValue(
            $parentAccountId,
            $childAccountId,
            $value
        );
    }

    /**
     * Загрузить метаданные атрибутов для типа сущности
     *
     * @param string $accountId UUID аккаунта
     * @param string $entityType Тип сущности (product, customerorder, и т.д.)
     * @return array Метаданные атрибутов индексированные по ID
     */
    public function loadAttributesMetadata(string $accountId, string $entityType): array
    {
        // Проверить кеш (static для переиспользования внутри одного запроса)
        static $cache = [];

        // Для product/service/bundle используем единый кеш и endpoint
        // МойСклад API: метаданные атрибутов для товаров/услуг/комплектов хранятся в /entity/product/metadata/attributes
        $metadataEntityType = in_array($entityType, ['product', 'service', 'bundle'])
            ? 'product'
            : $entityType;

        $cacheKey = "{$accountId}:{$metadataEntityType}";

        if (isset($cache[$cacheKey])) {
            Log::debug('Attributes metadata loaded from cache', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'cache_entity_type' => $metadataEntityType
            ]);
            return $cache[$cacheKey];
        }

        $account = Account::where('account_id', $accountId)->first();
        if (!$account) {
            Log::warning('Account not found for attributes metadata', [
                'account_id' => $accountId,
                'entity_type' => $entityType
            ]);
            return [];
        }

        try {
            // МойСклад API: для product/service/bundle всегда используем /entity/product/metadata/attributes
            $response = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get("entity/{$metadataEntityType}/metadata/attributes");

            $metadata = [];
            foreach ($response['data']['rows'] ?? [] as $attr) {
                if (isset($attr['id'])) {
                    $metadata[$attr['id']] = $attr;
                }
            }

            $cache[$cacheKey] = $metadata;

            Log::debug('Attributes metadata loaded and cached', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'metadata_endpoint' => "entity/{$metadataEntityType}/metadata/attributes",
                'count' => count($metadata)
            ]);

            return $metadata;

        } catch (\Exception $e) {
            Log::error('Failed to load attributes metadata', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'metadata_endpoint' => "entity/{$metadataEntityType}/metadata/attributes",
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Загрузить метаданные пользовательского справочника по ID
     *
     * @param string $accountId UUID аккаунта
     * @param string $customEntityId UUID справочника
     * @return array|null Метаданные справочника с полем 'name' или null
     */
    protected function loadCustomEntityMetadataById(string $accountId, string $customEntityId): ?array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $response = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get("context/companysettings/metadata/customEntities/{$customEntityId}");

            $metadata = $response['data'] ?? null;

            if ($metadata && isset($metadata['name'])) {
                Log::info('Loaded customEntity metadata by ID', [
                    'custom_entity_id' => $customEntityId,
                    'custom_entity_name' => $metadata['name']
                ]);
            }

            return $metadata;

        } catch (\Exception $e) {
            Log::error('Failed to load customEntity metadata by ID', [
                'account_id' => $accountId,
                'custom_entity_id' => $customEntityId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Извлечь ID сущности из href
     *
     * @param string $href URL с ID в конце
     * @return string|null ID сущности
     */
    protected function extractEntityId(string $href): ?string
    {
        if (empty($href)) {
            return null;
        }

        $parts = explode('/', $href);
        return end($parts) ?: null;
    }
}
