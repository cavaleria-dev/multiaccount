<?php

namespace App\Services\Webhook;

use App\Models\AttributeMapping;
use App\Models\PriceTypeMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * FieldClassifierService
 *
 * Classifies updatedFields from МойСклад webhook into categories:
 * - Standard API fields (buyPrice, productFolder, name, etc.)
 * - Custom attributes (by name: "Франшиза москва", etc.)
 * - Custom price types (by name: "Тест цены", etc.)
 *
 * Key insight from real webhooks:
 * - Standard fields arrive by API name: buyPrice, salePrices, productFolder
 * - Custom attributes arrive by human name: "Франшиза москва"
 * - Custom price types arrive by human name: "Тест цены"
 */
class FieldClassifierService
{
    /**
     * Standard fields by entity type
     *
     * Categories:
     * - base: Basic fields (name, description, article, code)
     * - prices: Price-related fields
     * - complex_deps: Fields requiring dependency sync (productFolder, uom, country)
     * - simple: Other simple fields
     */
    const ENTITY_FIELDS = [
        'product' => [
            'base' => ['name', 'description', 'article', 'code', 'externalCode'],
            'prices' => ['buyPrice', 'salePrices', 'minPrice'],
            'complex_deps' => ['productFolder', 'uom', 'country', 'packs'],
            'simple' => [
                'barcodes', 'archived', 'vat', 'weight', 'volume',
                'onTap', 'weighed', 'trackingType', 'tnved', 'isSerialTrackable',
                'things', 'paymentItemType', 'discountProhibited', 'supplier',
            ],
        ],
        'service' => [
            'base' => ['name', 'description', 'article', 'code', 'externalCode'],
            'prices' => ['buyPrice', 'salePrices', 'minPrice'],
            'complex_deps' => ['productFolder'],
            'simple' => [
                'barcodes', 'archived', 'vat', 'paymentItemType',
                'discountProhibited', 'supplier',
            ],
        ],
        'variant' => [
            'base' => ['name', 'code', 'externalCode'],
            'prices' => ['buyPrice', 'salePrices', 'minPrice'],
            'complex_deps' => ['characteristics'], // Характеристики модификаций
            'simple' => [
                'barcodes', 'archived', 'packs', 'things',
                'discountProhibited',
            ],
        ],
        'bundle' => [
            'base' => ['name', 'description', 'article', 'code', 'externalCode'],
            'prices' => ['buyPrice', 'salePrices', 'minPrice'],
            'complex_deps' => ['productFolder', 'components'], // Компоненты комплекта
            'simple' => [
                'barcodes', 'archived', 'vat', 'weight', 'volume',
                'paymentItemType', 'discountProhibited',
            ],
        ],
    ];

    /**
     * Classify updatedFields into categories
     *
     * @param string $entityType Entity type (product, service, variant, bundle)
     * @param array $updatedFields Fields from МойСклад webhook
     * @param string $mainAccountId Main account UUID (for attribute/price lookup)
     * @return array Classification result
     */
    public function classify(string $entityType, array $updatedFields, string $mainAccountId): array
    {
        // Get known standard fields for this entity type
        $entityConfig = self::ENTITY_FIELDS[$entityType] ?? [];
        $allStandardFields = [];
        foreach ($entityConfig as $category => $fields) {
            $allStandardFields = array_merge($allStandardFields, $fields);
        }

        // Classify each field
        $standard = [];
        $customAttributes = [];
        $customPriceTypes = [];

        foreach ($updatedFields as $field) {
            if (in_array($field, $allStandardFields)) {
                // Standard API field
                $standard[] = $field;
            } else {
                // Custom field - determine if attribute or price type
                if ($this->isCustomAttribute($field, $mainAccountId, $entityType)) {
                    $customAttributes[] = $field;
                } else {
                    // Assume it's a custom price type
                    $customPriceTypes[] = $field;
                }
            }
        }

        // Determine flags
        $complexDepFields = $entityConfig['complex_deps'] ?? [];
        $priceFields = $entityConfig['prices'] ?? [];
        $baseFields = $entityConfig['base'] ?? [];

        $result = [
            'entity_type' => $entityType,
            'standard' => $standard,
            'custom_attributes' => $customAttributes,
            'custom_price_types' => $customPriceTypes,
            'has_complex_deps' => !empty(array_intersect($standard, $complexDepFields)),
            'has_prices' => !empty(array_intersect($standard, $priceFields)) || !empty($customPriceTypes),
            'has_base_fields' => !empty(array_intersect($standard, $baseFields)),
        ];

        Log::debug('Fields classified', [
            'entity_type' => $entityType,
            'input_fields' => $updatedFields,
            'classification' => $result,
        ]);

        return $result;
    }

    /**
     * Check if field name is a custom attribute
     *
     * Searches in AttributeMapping table by attribute_name
     *
     * @param string $fieldName Field name (e.g., "Франшиза москва")
     * @param string $mainAccountId Main account UUID
     * @param string $entityType Entity type
     * @return bool
     */
    protected function isCustomAttribute(string $fieldName, string $mainAccountId, string $entityType): bool
    {
        // Cache key for performance
        $cacheKey = "field_classifier:attr:{$mainAccountId}:{$entityType}:{$fieldName}";

        return Cache::remember($cacheKey, 600, function () use ($fieldName, $mainAccountId, $entityType) {
            // Check if this name exists in attribute_mappings for this account and entity type
            return AttributeMapping::where('parent_account_id', $mainAccountId)
                ->where('entity_type', $entityType)
                ->where('attribute_name', $fieldName)
                ->exists();
        });
    }

    /**
     * Check if field name is a custom price type
     *
     * Searches in PriceTypeMapping table by price_type_name
     *
     * Note: Currently not used - we assume all non-standard, non-attribute fields are price types.
     * This method is here for potential future use.
     *
     * @param string $fieldName Field name (e.g., "Тест цены")
     * @param string $mainAccountId Main account UUID
     * @return bool
     */
    protected function isCustomPriceType(string $fieldName, string $mainAccountId): bool
    {
        $cacheKey = "field_classifier:price:{$mainAccountId}:{$fieldName}";

        return Cache::remember($cacheKey, 600, function () use ($fieldName, $mainAccountId) {
            return PriceTypeMapping::where('parent_account_id', $mainAccountId)
                ->where('price_type_name', $fieldName)
                ->exists();
        });
    }

    /**
     * Get all standard fields for an entity type
     *
     * @param string $entityType Entity type
     * @return array All standard fields (flat array)
     */
    public function getStandardFields(string $entityType): array
    {
        $config = self::ENTITY_FIELDS[$entityType] ?? [];
        $allFields = [];

        foreach ($config as $category => $fields) {
            $allFields = array_merge($allFields, $fields);
        }

        return $allFields;
    }

    /**
     * Get complex dependency fields for an entity type
     *
     * @param string $entityType Entity type
     * @return array Complex dependency fields
     */
    public function getComplexDependencyFields(string $entityType): array
    {
        return self::ENTITY_FIELDS[$entityType]['complex_deps'] ?? [];
    }

    /**
     * Get price fields for an entity type
     *
     * @param string $entityType Entity type
     * @return array Price fields
     */
    public function getPriceFields(string $entityType): array
    {
        return self::ENTITY_FIELDS[$entityType]['prices'] ?? [];
    }

    /**
     * Get base fields for an entity type
     *
     * @param string $entityType Entity type
     * @return array Base fields (name, description, etc.)
     */
    public function getBaseFields(string $entityType): array
    {
        return self::ENTITY_FIELDS[$entityType]['base'] ?? [];
    }
}
