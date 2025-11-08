<?php

namespace App\Services\Webhook;

use App\Models\SyncSetting;
use App\Models\PriceTypeMapping;
use App\Models\AttributeMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * UpdateStrategyService
 *
 * Determines the best update strategy based on:
 * - Classified fields (from FieldClassifierService)
 * - Child account sync_settings
 *
 * Strategies:
 * - SKIP: All fields filtered by settings
 * - FULL_SYNC: Complex dependencies detected (productFolder, uom, country)
 * - PRICES_ONLY: Only price fields (standard + custom from price_mappings)
 * - ATTRIBUTES_ONLY: Only custom attributes (from attribute_sync_list)
 * - BASE_FIELDS_ONLY: Only base fields (name, description, article, code)
 * - MIXED_SIMPLE: Mix of simple fields (no complex dependencies)
 */
class UpdateStrategyService
{
    const STRATEGY_SKIP = 'SKIP';
    const STRATEGY_FULL_SYNC = 'FULL_SYNC';
    const STRATEGY_PRICES_ONLY = 'PRICES_ONLY';
    const STRATEGY_ATTRIBUTES_ONLY = 'ATTRIBUTES_ONLY';
    const STRATEGY_BASE_FIELDS_ONLY = 'BASE_FIELDS_ONLY';
    const STRATEGY_MIXED_SIMPLE = 'MIXED_SIMPLE';

    protected FieldClassifierService $fieldClassifier;

    public function __construct(FieldClassifierService $fieldClassifier)
    {
        $this->fieldClassifier = $fieldClassifier;
    }

    /**
     * Determine update strategy
     *
     * @param array $classified Result from FieldClassifierService
     * @param SyncSetting $settings Child account sync settings
     * @param string $mainAccountId Main account UUID (for price/attribute lookup)
     * @return array Strategy data with filtered fields
     */
    public function determine(array $classified, SyncSetting $settings, string $mainAccountId): array
    {
        // 1. Filter fields by sync_settings
        $filtered = $this->filterBySettings($classified, $settings, $mainAccountId);

        Log::debug('Fields filtered by settings', [
            'input' => $classified,
            'filtered' => $filtered,
            'sync_prices' => $settings->sync_prices,
        ]);

        // 2. Check if everything was filtered out
        if ($this->isEmpty($filtered)) {
            return [
                'strategy' => self::STRATEGY_SKIP,
                'reason' => 'All fields filtered by sync_settings',
                'filtered' => $filtered,
            ];
        }

        // 3. Check for complex dependencies → always full sync
        if ($classified['has_complex_deps']) {
            $complexFields = array_intersect(
                $classified['standard'],
                $this->fieldClassifier->getComplexDependencyFields($classified['entity_type'])
            );

            return [
                'strategy' => self::STRATEGY_FULL_SYNC,
                'reason' => 'Complex dependencies detected',
                'complex_fields' => $complexFields,
                'filtered' => $filtered,
            ];
        }

        // 4. Determine strategy by composition
        $hasOnlyPrices = $this->hasOnlyPrices($filtered);
        $hasOnlyAttributes = $this->hasOnlyAttributes($filtered);
        $hasOnlyBaseFields = $this->hasOnlyBaseFields($filtered, $classified['entity_type']);

        if ($hasOnlyPrices) {
            return [
                'strategy' => self::STRATEGY_PRICES_ONLY,
                'standard_prices' => $filtered['standard_prices'],
                'custom_prices' => $filtered['custom_price_types'],
                'filtered' => $filtered,
            ];
        }

        if ($hasOnlyAttributes) {
            return [
                'strategy' => self::STRATEGY_ATTRIBUTES_ONLY,
                'attributes' => $filtered['custom_attributes'],
                'filtered' => $filtered,
            ];
        }

        if ($hasOnlyBaseFields) {
            return [
                'strategy' => self::STRATEGY_BASE_FIELDS_ONLY,
                'base_fields' => $filtered['base_fields'],
                'filtered' => $filtered,
            ];
        }

        // 5. Mixed strategy (multiple simple field types)
        return [
            'strategy' => self::STRATEGY_MIXED_SIMPLE,
            'standard_fields' => $filtered['standard_non_price'],
            'base_fields' => $filtered['base_fields'],
            'standard_prices' => $filtered['standard_prices'],
            'custom_prices' => $filtered['custom_price_types'],
            'attributes' => $filtered['custom_attributes'],
            'filtered' => $filtered,
        ];
    }

    /**
     * Filter classified fields by sync_settings
     *
     * Returns categorized allowed fields
     */
    protected function filterBySettings(array $classified, SyncSetting $settings, string $mainAccountId): array
    {
        $entityType = $classified['entity_type'];
        $baseFieldsList = $this->fieldClassifier->getBaseFields($entityType);
        $priceFieldsList = $this->fieldClassifier->getPriceFields($entityType);

        // Base fields - always allowed if sync enabled
        $baseFields = array_intersect($classified['standard'], $baseFieldsList);

        // Price fields - filter by sync_prices and price_mappings
        $standardPriceFields = array_intersect($classified['standard'], $priceFieldsList);
        $standardPrices = $settings->sync_prices ? $standardPriceFields : [];

        // Custom price types - ONLY from price_mappings
        $customPriceTypes = $settings->sync_prices
            ? $this->filterPricesByMappings(
                $classified['custom_price_types'],
                $settings,
                $mainAccountId,
                $settings->account_id // child account id
            )
            : [];

        // Attributes - filter by attribute_sync_list
        $customAttributes = $this->filterAttributesByList(
            $classified['custom_attributes'],
            $settings->attribute_sync_list ?? [],
            $mainAccountId,
            $settings->account_id
        );

        // Other standard fields (non-base, non-price)
        $otherStandard = array_diff(
            $classified['standard'],
            $baseFields,
            $standardPriceFields
        );

        return [
            'base_fields' => $baseFields,
            'standard_prices' => $standardPrices,
            'custom_price_types' => $customPriceTypes,
            'custom_attributes' => $customAttributes,
            'standard_non_price' => $otherStandard,
        ];
    }

    /**
     * Filter custom price types by price_mappings
     *
     * CRITICAL: Only sync prices that are mapped in sync_settings.price_mappings!
     *
     * @param array $customPriceNames Price type names from updatedFields (e.g., ["Тест цены"])
     * @param SyncSetting $settings Sync settings with price_mappings
     * @param string $mainAccountId Main account UUID
     * @param string $childAccountId Child account UUID
     * @return array Allowed price type names
     */
    protected function filterPricesByMappings(
        array $customPriceNames,
        SyncSetting $settings,
        string $mainAccountId,
        string $childAccountId
    ): array {
        if (empty($settings->price_mappings)) {
            return []; // No price mappings configured
        }

        $priceMappings = $settings->price_mappings; // [{"main_price_type_id": "uuid", "child_price_type_id": "uuid"}]
        $allowedPrices = [];

        foreach ($customPriceNames as $priceName) {
            // Find price type mapping by name
            $mapping = $this->findPriceTypeMappingByName($priceName, $mainAccountId, $childAccountId);

            if (!$mapping) {
                Log::debug('Custom price type not found in mappings', [
                    'price_name' => $priceName,
                    'main_account_id' => $mainAccountId,
                ]);
                continue;
            }

            // Check if this price type is in price_mappings
            $isMapped = false;
            foreach ($priceMappings as $priceMapping) {
                $mainPriceTypeId = $priceMapping['main_price_type_id'] ?? null;

                if ($mainPriceTypeId === $mapping->parent_price_type_id) {
                    $isMapped = true;
                    break;
                }
            }

            if ($isMapped) {
                $allowedPrices[] = $priceName;
            } else {
                Log::debug('Custom price type not in price_mappings', [
                    'price_name' => $priceName,
                    'price_type_id' => $mapping->parent_price_type_id,
                ]);
            }
        }

        return $allowedPrices;
    }

    /**
     * Filter custom attributes by attribute_sync_list
     *
     * @param array $customAttributeNames Attribute names from updatedFields
     * @param array|null $syncList Attribute IDs to sync (or null for all)
     * @param string $mainAccountId Main account UUID
     * @param string $childAccountId Child account UUID
     * @return array Allowed attribute names
     */
    protected function filterAttributesByList(
        array $customAttributeNames,
        ?array $syncList,
        string $mainAccountId,
        string $childAccountId
    ): array {
        if (empty($customAttributeNames)) {
            return [];
        }

        // If sync_list is null or empty - sync all attributes
        if ($syncList === null || empty($syncList)) {
            return $customAttributeNames;
        }

        $allowedAttributes = [];

        foreach ($customAttributeNames as $attrName) {
            // Find attribute mapping by name
            $mapping = $this->findAttributeMappingByName($attrName, $mainAccountId, $childAccountId);

            if (!$mapping) {
                Log::debug('Custom attribute not found in mappings', [
                    'attribute_name' => $attrName,
                    'main_account_id' => $mainAccountId,
                ]);
                continue;
            }

            // Check if attribute ID is in sync_list
            if (in_array($mapping->parent_attribute_id, $syncList)) {
                $allowedAttributes[] = $attrName;
            } else {
                Log::debug('Custom attribute not in sync_list', [
                    'attribute_name' => $attrName,
                    'attribute_id' => $mapping->parent_attribute_id,
                ]);
            }
        }

        return $allowedAttributes;
    }

    /**
     * Find PriceTypeMapping by price type name (with caching)
     */
    protected function findPriceTypeMappingByName(
        string $priceName,
        string $mainAccountId,
        string $childAccountId
    ): ?PriceTypeMapping {
        $cacheKey = "price_mapping:name:{$mainAccountId}:{$childAccountId}:{$priceName}";

        return Cache::remember($cacheKey, 600, function () use ($priceName, $mainAccountId, $childAccountId) {
            return PriceTypeMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('price_type_name', $priceName)
                ->first();
        });
    }

    /**
     * Find AttributeMapping by attribute name (with caching)
     */
    protected function findAttributeMappingByName(
        string $attrName,
        string $mainAccountId,
        string $childAccountId
    ): ?AttributeMapping {
        $cacheKey = "attr_mapping:name:{$mainAccountId}:{$childAccountId}:{$attrName}";

        return Cache::remember($cacheKey, 600, function () use ($attrName, $mainAccountId, $childAccountId) {
            return AttributeMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('attribute_name', $attrName)
                ->first();
        });
    }

    // ===== Composition Checks =====

    protected function isEmpty(array $filtered): bool
    {
        return empty($filtered['base_fields'])
            && empty($filtered['standard_prices'])
            && empty($filtered['custom_price_types'])
            && empty($filtered['custom_attributes'])
            && empty($filtered['standard_non_price']);
    }

    protected function hasOnlyPrices(array $filtered): bool
    {
        return (
            !empty($filtered['standard_prices']) || !empty($filtered['custom_price_types'])
        ) && (
            empty($filtered['base_fields'])
            && empty($filtered['custom_attributes'])
            && empty($filtered['standard_non_price'])
        );
    }

    protected function hasOnlyAttributes(array $filtered): bool
    {
        return !empty($filtered['custom_attributes']) && (
            empty($filtered['base_fields'])
            && empty($filtered['standard_prices'])
            && empty($filtered['custom_price_types'])
            && empty($filtered['standard_non_price'])
        );
    }

    protected function hasOnlyBaseFields(array $filtered, string $entityType): bool
    {
        return !empty($filtered['base_fields']) && (
            empty($filtered['standard_prices'])
            && empty($filtered['custom_price_types'])
            && empty($filtered['custom_attributes'])
            && empty($filtered['standard_non_price'])
        );
    }
}
