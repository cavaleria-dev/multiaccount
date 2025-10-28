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

            // МойСклад API: для product/service/bundle используем единый endpoint
            $metadataEntityType = in_array($entityType, ['product', 'service', 'bundle'])
                ? 'product'
                : $entityType;

            $syncedAttributes[] = [
                'meta' => [
                    'href' => config('moysklad.api_url') . "/entity/{$metadataEntityType}/metadata/attributes/{$targetAttributeId}",
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

            // Проверить, существует ли атрибут в целевом аккаунте
            $existingAttributes = $this->loadAttributesMetadata($targetAccountId, $entityType);

            Log::info('Checking if attribute exists in target account', [
                'source_account_id' => $sourceAccountId,
                'target_account_id' => $targetAccountId,
                'entity_type' => $entityType,
                'looking_for_name' => $attribute['name'],
                'looking_for_type' => $attribute['type'],
                'has_customEntityMeta' => isset($attribute['customEntityMeta']),
                'total_existing_attributes' => count($existingAttributes),
                'existing_attribute_names' => array_map(fn($a) => [
                    'name' => $a['name'] ?? null,
                    'type' => $a['type'] ?? null,
                    'id' => $a['id'] ?? null
                ], $existingAttributes)
            ]);

            $existingAttribute = $this->findAttributeByNameAndType(
                $existingAttributes,
                $attribute['name'],
                $attribute['type'],
                $attribute['customEntityMeta'] ?? null
            );

            if ($existingAttribute) {
                Log::info('Attribute found in target account', [
                    'found_attribute_id' => $existingAttribute['id'] ?? null,
                    'found_attribute_name' => $existingAttribute['name'] ?? null,
                    'found_attribute_type' => $existingAttribute['type'] ?? null
                ]);
                // Атрибут уже существует - создать только маппинг
                Log::info('Attribute already exists in target account, creating mapping only', [
                    'source_account_id' => $sourceAccountId,
                    'target_account_id' => $targetAccountId,
                    'entity_type' => $entityType,
                    'attribute_name' => $attribute['name'],
                    'attribute_type' => $attribute['type'],
                    'existing_attribute_id' => $existingAttribute['id'],
                    'direction' => $direction
                ]);

                // Определить ID атрибутов для маппинга
                if ($direction === 'main_to_child') {
                    $parentAttributeId = $attribute['id'];
                    $childAttributeId = $existingAttribute['id'];
                } else {
                    $parentAttributeId = $existingAttribute['id'];
                    $childAttributeId = $attribute['id'];
                }

                // Создать маппинг
                $mapping = AttributeMapping::create([
                    'parent_account_id' => $parentAccountId,
                    'child_account_id' => $childAccountId,
                    'entity_type' => $entityType,
                    'parent_attribute_id' => $parentAttributeId,
                    'child_attribute_id' => $childAttributeId,
                    'attribute_name' => $attribute['name'],
                    'attribute_type' => $attribute['type'],
                    'is_synced' => true,
                    'auto_created' => false, // Не автоматически создан
                ]);

                return $mapping;
            }

            // Атрибут НЕ найден - создать новый
            Log::warning('Attribute NOT found in target account - will attempt to create', [
                'source_account_id' => $sourceAccountId,
                'target_account_id' => $targetAccountId,
                'attribute_name' => $attribute['name'],
                'attribute_type' => $attribute['type'],
                'existing_attributes_count' => count($existingAttributes)
            ]);

            Log::info('Creating new attribute in target account', [
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

            // МойСклад API: для product/service/bundle используем единый endpoint
            $metadataEntityType = in_array($entityType, ['product', 'service', 'bundle'])
                ? 'product'
                : $entityType;

            // Создать атрибут в целевом аккаунте
            $result = $this->moySkladService
                ->setAccessToken($targetAccount->access_token)
                ->setLogContext(
                    accountId: $targetAccountId,
                    direction: $direction,
                    relatedAccountId: $sourceAccountId,
                    entityType: 'attribute',
                    entityId: null
                )
                ->post("entity/{$metadataEntityType}/metadata/attributes", $attributeData);

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
            $errorMessage = $e->getMessage();

            // Специальная обработка ошибки 412 (уникальность атрибута)
            if (strpos($errorMessage, '412') !== false || strpos($errorMessage, '3006') !== false) {
                Log::error('Attribute already exists in МойСклад (HTTP 412 - uniqueness violation)', [
                    'source_account_id' => $sourceAccountId,
                    'target_account_id' => $targetAccountId,
                    'entity_type' => $entityType,
                    'attribute_name' => $attribute['name'],
                    'attribute_type' => $attribute['type'],
                    'error' => $errorMessage,
                    'hint' => 'Attribute was not found in metadata but exists in МойСклад API. Check: 1) Cache invalidation, 2) API delay, 3) Search logic in findAttributeByNameAndType()',
                    'existing_attributes_searched' => count($existingAttributes ?? [])
                ]);
            } else {
                Log::error('Failed to create attribute in target account', [
                    'source_account_id' => $sourceAccountId,
                    'target_account_id' => $targetAccountId,
                    'entity_type' => $entityType,
                    'attribute' => $attribute,
                    'direction' => $direction,
                    'error' => $errorMessage
                ]);
            }

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
                ->setLogContext(
                    accountId: $accountId,
                    direction: 'internal',
                    relatedAccountId: null,
                    entityType: 'attribute_metadata',
                    entityId: null
                )
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
    public function loadCustomEntityMetadataById(string $accountId, string $customEntityId): ?array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $response = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->setLogContext(
                    accountId: $accountId,
                    direction: 'internal',
                    relatedAccountId: null,
                    entityType: 'customentity_metadata',
                    entityId: $customEntityId
                )
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

        // Удалить query string если есть (?expand=..., ?filter=..., etc)
        $href = strtok($href, '?');

        $parts = explode('/', $href);
        return end($parts) ?: null;
    }

    /**
     * Найти атрибут по имени и типу в списке атрибутов
     *
     * @param array $attributes Массив атрибутов из метаданных
     * @param string $name Название атрибута
     * @param string $type Тип атрибута
     * @param array|null $customEntityMeta Метаданные справочника (для customentity)
     * @return array|null Найденный атрибут или null
     */
    public function findAttributeByNameAndType(
        array $attributes,
        string $name,
        string $type,
        ?array $customEntityMeta = null
    ): ?array {
        // Нормализовать имя (убрать пробелы по краям)
        $name = trim($name);

        foreach ($attributes as $attr) {
            $attrName = trim($attr['name'] ?? '');
            $attrType = $attr['type'] ?? null;

            Log::debug('Comparing attributes in findAttributeByNameAndType', [
                'looking_for' => ['name' => $name, 'type' => $type],
                'comparing_with' => [
                    'name' => $attrName,
                    'type' => $attrType,
                    'id' => $attr['id'] ?? null
                ],
                'name_match' => $attrName === $name,
                'type_match' => $attrType === $type
            ]);

            // Проверить имя и тип
            if ($attrName === $name && $attrType === $type) {

                // Для НЕ-customentity: сразу вернуть атрибут (совпадения имени и типа достаточно)
                if ($type !== 'customentity') {
                    Log::debug('Attribute found (non-customentity)', [
                        'attribute_name' => $attrName,
                        'attribute_type' => $attrType,
                        'attribute_id' => $attr['id'] ?? null
                    ]);
                    return $attr;
                }

                // Для customentity: дополнительно проверить название справочника
                if ($customEntityMeta) {
                    $sourceCustomEntityName = $customEntityMeta['name'] ?? null;
                    $targetCustomEntityName = $attr['customEntityMeta']['name'] ?? null;

                    if ($sourceCustomEntityName && $targetCustomEntityName) {
                        if ($sourceCustomEntityName === $targetCustomEntityName) {
                            Log::debug('Attribute found (customentity with matching custom entity)', [
                                'attribute_name' => $attrName,
                                'custom_entity_name' => $sourceCustomEntityName,
                                'attribute_id' => $attr['id'] ?? null
                            ]);
                            return $attr; // Полное совпадение (name + type + customEntity)
                        }
                        // Разные справочники - продолжить поиск
                        Log::debug('Custom entity names do not match, continuing search', [
                            'source_custom_entity' => $sourceCustomEntityName,
                            'target_custom_entity' => $targetCustomEntityName
                        ]);
                        continue;
                    }
                }

                // Для customentity без customEntityMeta или если названия справочников не указаны
                // Вернуть атрибут по совпадению имени и типа
                Log::debug('Attribute found (customentity without meta check)', [
                    'attribute_name' => $attrName,
                    'attribute_id' => $attr['id'] ?? null
                ]);
                return $attr;
            }
        }

        Log::debug('Attribute not found in target account', [
            'looking_for_name' => $name,
            'looking_for_type' => $type
        ]);

        return null;
    }
}
