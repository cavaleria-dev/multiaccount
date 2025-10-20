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
            'expand' => 'attributes,productFolder,uom,country,packs.uom,salePrices',
            'batch_entity_type' => 'batch_products',
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
            'filter_metadata_type' => 'service',
            'supports_filters' => true,
            'use_assortment_for_filters' => true,  // Использовать /entity/assortment для фильтрации
            'assortment_type' => 'service',        // Тип для параметра type=
            'match_field_setting' => 'service_match_field',
            'default_match_field' => 'code',
            'has_match_field_check' => true,  // Услуги требуют проверку match_field
        ],

        'bundle' => [
            'endpoint' => '/entity/bundle',
            'expand' => 'attributes,productFolder,components.product,components.variant',
            'batch_entity_type' => 'batch_bundles',
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
            'expand' => 'attributes,product,characteristics',
            'batch_entity_type' => 'batch_variants',
            'filter_metadata_type' => 'product',  // Variants используют product metadata
            'supports_filters' => false,  // Variants не фильтруются напрямую (группируются по product)
            'use_assortment_for_filters' => false,  // Variants не используют assortment
            'group_by' => 'product_id',  // Группировка по родительскому товару
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
}
