<?php

namespace App\Services;

/**
 * Конфигурация для различных типов сущностей МойСклад
 *
 * Централизованное хранение параметров загрузки и синхронизации для:
 * - product (товары)
 * - service (услуги)
 * - bundle (комплекты)
 * - variant (модификации)
 */
class EntityConfig
{
    /**
     * Конфигурации для каждого типа сущности
     */
    protected static array $configs = [
        'product' => [
            'endpoint' => '/entity/product',
            'expand' => 'attributes,productFolder,uom,country,packs.uom,salePrices,images',
            'batch_entity_type' => 'batch_products',
            'batch_priority' => 10,  // Highest priority - синхронизируются первыми
            'filter_metadata_type' => 'product',
            'supports_filters' => true,
            'use_assortment_for_filters' => true,  // Использовать /entity/assortment для фильтрации
            'assortment_type' => 'product',        // Тип для параметра type=
            'match_field_setting' => 'product_match_field',
            'default_match_field' => 'code',
            'has_match_field_check' => false,
        ],

        'service' => [
            'endpoint' => '/entity/service',
            'expand' => 'attributes,uom,salePrices',
            'batch_entity_type' => 'batch_services',
            'batch_priority' => 8,  // High priority
            'filter_metadata_type' => 'product',  // Service использует product metadata (общие атрибуты)
            'supports_filters' => true,
            'use_assortment_for_filters' => true,  // Использовать /entity/assortment для фильтрации
            'assortment_type' => 'service',        // Тип для параметра type=
            'match_field_setting' => 'service_match_field',
            'default_match_field' => 'code',
            'has_match_field_check' => true,  // Услуги требуют проверку match_field
        ],

        'bundle' => [
            'endpoint' => '/entity/bundle',
            'expand' => 'attributes,productFolder,components.product,components.variant,images',
            'batch_entity_type' => 'batch_bundles',
            'batch_priority' => 6,  // Medium priority
            'filter_metadata_type' => 'product',  // Bundles используют product metadata
            'supports_filters' => true,
            'use_assortment_for_filters' => true,  // Использовать /entity/assortment для фильтрации
            'assortment_type' => 'bundle',         // Тип для параметра type=
            'match_field_setting' => 'product_match_field',
            'default_match_field' => 'code',
            'has_match_field_check' => false,
        ],

        'variant' => [
            'endpoint' => '/entity/variant',
            'expand' => 'attributes,product,characteristics,packs.uom,salePrices,images',  // Добавлены packs.uom, salePrices
            'batch_entity_type' => 'batch_variants',
            'batch_priority' => 4,  // Lowest priority - синхронизируются последними (после products)
            'filter_metadata_type' => 'product',  // Variants используют product metadata
            'supports_filters' => true,  // Variants теперь фильтруются через assortment
            'use_assortment_for_filters' => true,  // Variants используют assortment
            'assortment_type' => 'variant',  // Тип для параметра type=
            'match_field_setting' => 'product_match_field',
            'default_match_field' => 'code',
            'has_match_field_check' => false,
        ],
    ];

    /**
     * Получить конфигурацию для типа сущности
     *
     * @param string $entityType Тип сущности (product, service, bundle, variant)
     * @return array Конфигурация
     * @throws \Exception Если тип не поддерживается
     */
    public static function get(string $entityType): array
    {
        if (!isset(self::$configs[$entityType])) {
            throw new \Exception("Unknown entity type: {$entityType}. Supported types: " . implode(', ', array_keys(self::$configs)));
        }

        return self::$configs[$entityType];
    }

    /**
     * Проверить поддерживается ли тип сущности
     *
     * @param string $entityType Тип сущности
     * @return bool
     */
    public static function isSupported(string $entityType): bool
    {
        return isset(self::$configs[$entityType]);
    }

    /**
     * Получить все поддерживаемые типы сущностей
     *
     * @return array Массив типов
     */
    public static function getSupportedTypes(): array
    {
        return array_keys(self::$configs);
    }

    /**
     * Получить endpoint для типа сущности
     *
     * @param string $entityType Тип сущности
     * @return string
     */
    public static function getEndpoint(string $entityType): string
    {
        return self::get($entityType)['endpoint'];
    }

    /**
     * Получить expand параметр для типа сущности
     *
     * @param string $entityType Тип сущности
     * @return string
     */
    public static function getExpand(string $entityType): string
    {
        return self::get($entityType)['expand'];
    }

    /**
     * Получить тип batch задачи для сущности
     *
     * @param string $entityType Тип сущности
     * @return string
     */
    public static function getBatchEntityType(string $entityType): string
    {
        return self::get($entityType)['batch_entity_type'];
    }

    /**
     * Проверить поддерживает ли сущность фильтры
     *
     * @param string $entityType Тип сущности
     * @return bool
     */
    public static function supportsFilters(string $entityType): bool
    {
        return self::get($entityType)['supports_filters'] ?? false;
    }

    /**
     * Получить тип metadata для фильтров
     *
     * @param string $entityType Тип сущности
     * @return string
     */
    public static function getFilterMetadataType(string $entityType): string
    {
        return self::get($entityType)['filter_metadata_type'] ?? $entityType;
    }

    /**
     * Проверить требуется ли проверка match_field
     *
     * @param string $entityType Тип сущности
     * @return bool
     */
    public static function hasMatchFieldCheck(string $entityType): bool
    {
        return self::get($entityType)['has_match_field_check'] ?? false;
    }

    /**
     * Получить настройку match_field из sync_settings
     *
     * @param string $entityType Тип сущности
     * @return string
     */
    public static function getMatchFieldSetting(string $entityType): string
    {
        return self::get($entityType)['match_field_setting'] ?? 'product_match_field';
    }

    /**
     * Получить значение match_field по умолчанию
     *
     * @param string $entityType Тип сущности
     * @return string
     */
    public static function getDefaultMatchField(string $entityType): string
    {
        return self::get($entityType)['default_match_field'] ?? 'code';
    }

    /**
     * Проверить использует ли сущность assortment для фильтрации
     *
     * @param string $entityType Тип сущности
     * @return bool
     */
    public static function useAssortmentForFilters(string $entityType): bool
    {
        return self::get($entityType)['use_assortment_for_filters'] ?? false;
    }

    /**
     * Получить значение параметра type для assortment endpoint
     *
     * @param string $entityType Тип сущности
     * @return string
     */
    public static function getAssortmentType(string $entityType): string
    {
        return self::get($entityType)['assortment_type'] ?? $entityType;
    }

    /**
     * Построить унифицированную строку expand для нескольких типов сущностей
     *
     * Объединяет expand параметры из всех указанных типов, удаляя дубликаты.
     * Используется для загрузки через /entity/assortment с несколькими типами.
     *
     * @param array $entityTypes Массив типов сущностей ['product', 'service', 'bundle']
     * @return string Объединенная строка expand через запятую
     */
    public static function buildUnifiedExpand(array $entityTypes): string
    {
        $expandFields = [];

        foreach ($entityTypes as $entityType) {
            if (!self::isSupported($entityType)) {
                continue;
            }

            $expand = self::get($entityType)['expand'] ?? '';
            if (empty($expand)) {
                continue;
            }

            // Разбить expand на отдельные поля
            $fields = array_map('trim', explode(',', $expand));
            $expandFields = array_merge($expandFields, $fields);
        }

        // Удалить дубликаты и пустые значения
        $expandFields = array_filter(array_unique($expandFields));

        // Вернуть объединенную строку
        return implode(',', $expandFields);
    }
}
