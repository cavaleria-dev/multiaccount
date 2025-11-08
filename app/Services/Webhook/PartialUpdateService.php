<?php

namespace App\Services\Webhook;

use App\Models\Account;
use App\Models\EntityUpdateLog;
use App\Models\SyncSetting;
use App\Models\PriceTypeMapping;
use App\Services\MoySkladService;
use Illuminate\Support\Facades\Log;

/**
 * PartialUpdateService
 *
 * Universal service for executing partial entity updates
 * Works with any entity type (product, service, variant, order, etc.)
 *
 * Key responsibilities:
 * - Execute updates based on strategy from UpdateStrategyService
 * - Create and maintain EntityUpdateLog audit trail
 * - Handle errors with fallback to full sync
 * - Respect sync_settings (price_mappings, attribute_sync_list)
 */
class PartialUpdateService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Execute partial update based on strategy
     *
     * @param string $entityType Entity type (product, service, variant, etc.)
     * @param Account $mainAccount Main account
     * @param Account $childAccount Child account
     * @param string $mainEntityId Entity ID in main account
     * @param string $childEntityId Entity ID in child account
     * @param array $strategyData Strategy from UpdateStrategyService
     * @param SyncSetting $settings Child account settings
     * @param array $originalFields Original updatedFields from webhook
     * @return EntityUpdateLog
     */
    public function update(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId,
        array $strategyData,
        SyncSetting $settings,
        array $originalFields
    ): EntityUpdateLog {
        // Create audit log
        $updateLog = EntityUpdateLog::create([
            'main_account_id' => $mainAccount->account_id,
            'child_account_id' => $childAccount->account_id,
            'entity_type' => $entityType,
            'main_entity_id' => $mainEntityId,
            'child_entity_id' => $childEntityId,
            'update_strategy' => $strategyData['strategy'],
            'updated_fields_received' => $originalFields,
            'fields_classified' => $strategyData['filtered'] ?? [],
            'status' => 'processing',
        ]);

        try {
            $startTime = microtime(true);

            Log::info('Partial update started', [
                'update_log_id' => $updateLog->id,
                'entity_type' => $entityType,
                'main_entity_id' => $mainEntityId,
                'child_entity_id' => $childEntityId,
                'strategy' => $strategyData['strategy'],
            ]);

            // Execute update by strategy
            $appliedFields = match ($strategyData['strategy']) {
                UpdateStrategyService::STRATEGY_SKIP => [],
                UpdateStrategyService::STRATEGY_FULL_SYNC => $this->fullUpdate($entityType, $mainAccount, $childAccount, $mainEntityId, $childEntityId),
                UpdateStrategyService::STRATEGY_PRICES_ONLY => $this->updatePricesOnly($entityType, $mainAccount, $childAccount, $mainEntityId, $childEntityId, $strategyData, $settings),
                UpdateStrategyService::STRATEGY_ATTRIBUTES_ONLY => $this->updateAttributesOnly($entityType, $mainAccount, $childAccount, $mainEntityId, $childEntityId, $strategyData),
                UpdateStrategyService::STRATEGY_BASE_FIELDS_ONLY => $this->updateBaseFields($entityType, $mainAccount, $childAccount, $mainEntityId, $childEntityId, $strategyData),
                UpdateStrategyService::STRATEGY_MIXED_SIMPLE => $this->updateMixedFields($entityType, $mainAccount, $childAccount, $mainEntityId, $childEntityId, $strategyData, $settings),
                default => throw new \Exception("Unknown strategy: {$strategyData['strategy']}"),
            };

            $processingTime = (int) ((microtime(true) - $startTime) * 1000);

            // Update log as completed
            $updateLog->markAsCompleted($appliedFields, $processingTime);

            Log::info('Partial update completed', [
                'update_log_id' => $updateLog->id,
                'entity_type' => $entityType,
                'strategy' => $strategyData['strategy'],
                'fields_applied' => count($appliedFields),
                'processing_time_ms' => $processingTime,
            ]);

        } catch (\Exception $e) {
            $updateLog->markAsFailed($e->getMessage());

            Log::error('Partial update failed', [
                'update_log_id' => $updateLog->id,
                'entity_type' => $entityType,
                'main_entity_id' => $mainEntityId,
                'child_entity_id' => $childEntityId,
                'strategy' => $strategyData['strategy'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        return $updateLog;
    }

    /**
     * Update prices only
     *
     * CRITICAL: Only prices from price_mappings!
     *
     * @param string $entityType Entity type
     * @param Account $mainAccount Main account
     * @param Account $childAccount Child account
     * @param string $mainEntityId Main entity ID
     * @param string $childEntityId Child entity ID
     * @param array $strategyData Strategy data with filtered prices
     * @param SyncSetting $settings Sync settings with price_mappings
     * @return array Applied field names
     */
    protected function updatePricesOnly(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId,
        array $strategyData,
        SyncSetting $settings
    ): array {
        Log::debug('Updating prices only', [
            'entity_type' => $entityType,
            'main_entity_id' => $mainEntityId,
            'child_entity_id' => $childEntityId,
            'standard_prices' => $strategyData['standard_prices'] ?? [],
            'custom_prices' => $strategyData['custom_prices'] ?? [],
        ]);

        // 1. GET entity from main account with expanded salePrices
        $mainEntity = $this->moySkladService
            ->setAccessToken($mainAccount->access_token)
            ->get("entity/{$entityType}/{$mainEntityId}?expand=salePrices");

        if (!isset($mainEntity['data'])) {
            throw new \Exception("Failed to fetch main entity: {$mainEntityId}");
        }

        $mainEntityData = $mainEntity['data'];
        $priceData = [];
        $appliedFields = [];

        // 2. Standard prices (buyPrice, minPrice)
        foreach ($strategyData['standard_prices'] ?? [] as $priceField) {
            if (isset($mainEntityData[$priceField])) {
                $priceData[$priceField] = $mainEntityData[$priceField];
                $appliedFields[] = $priceField;

                Log::debug('Added standard price', [
                    'field' => $priceField,
                    'value' => $mainEntityData[$priceField],
                ]);
            }
        }

        // 3. Custom price types - ONLY mapped prices from price_mappings!
        if (!empty($strategyData['custom_prices'])) {
            $salePrices = $this->buildMappedSalePrices(
                $mainEntityData['salePrices'] ?? [],
                $settings->price_mappings ?? [],
                $strategyData['custom_prices'],
                $mainAccount->account_id,
                $childAccount->account_id
            );

            if (!empty($salePrices)) {
                $priceData['salePrices'] = $salePrices;
                $appliedFields = array_merge($appliedFields, $strategyData['custom_prices']);

                Log::debug('Added custom sale prices', [
                    'count' => count($salePrices),
                    'price_names' => $strategyData['custom_prices'],
                ]);
            }
        }

        // 4. PUT to child account (only if we have data to update)
        if (!empty($priceData)) {
            Log::debug('Sending PUT request to child account', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'price_data' => $priceData,
            ]);

            $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->put("entity/{$entityType}/{$childEntityId}", $priceData);

            Log::info('Prices updated successfully', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'fields_updated' => $appliedFields,
            ]);
        } else {
            Log::warning('No price data to update', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
            ]);
        }

        return $appliedFields;
    }

    /**
     * Build salePrices array using ONLY mapped price types
     *
     * @param array $mainSalePrices Sale prices from main entity
     * @param array $priceMappings Price mappings from sync_settings
     * @param array $allowedPriceNames Allowed custom price type names (already filtered)
     * @param string $mainAccountId Main account UUID
     * @param string $childAccountId Child account UUID
     * @return array Sale prices for child entity
     */
    protected function buildMappedSalePrices(
        array $mainSalePrices,
        array $priceMappings,
        array $allowedPriceNames,
        string $mainAccountId,
        string $childAccountId
    ): array {
        if (empty($mainSalePrices) || empty($priceMappings)) {
            return [];
        }

        $result = [];

        // Get mappings for allowed price types (by name)
        $priceTypeMappingsByName = [];
        foreach ($allowedPriceNames as $priceName) {
            $mapping = PriceTypeMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('price_type_name', $priceName)
                ->first();

            if ($mapping) {
                $priceTypeMappingsByName[$mapping->parent_price_type_id] = $mapping->child_price_type_id;
            }
        }

        Log::debug('Price type mappings found', [
            'mappings_by_id' => $priceTypeMappingsByName,
        ]);

        // Build sale prices for child account
        foreach ($mainSalePrices as $salePrice) {
            $priceTypeHref = $salePrice['priceType']['meta']['href'] ?? null;
            if (!$priceTypeHref) {
                continue;
            }

            // Extract price type UUID from href
            $mainPriceTypeId = $this->extractEntityId($priceTypeHref);

            // Check if this price type should be synced (must be in price_mappings AND in allowed names)
            if (isset($priceTypeMappingsByName[$mainPriceTypeId])) {
                $childPriceTypeId = $priceTypeMappingsByName[$mainPriceTypeId];

                // Verify it's actually in price_mappings config
                $isMapped = false;
                foreach ($priceMappings as $mapping) {
                    if (($mapping['main_price_type_id'] ?? null) === $mainPriceTypeId) {
                        $isMapped = true;
                        break;
                    }
                }

                if ($isMapped) {
                    $result[] = [
                        'value' => $salePrice['value'],
                        'currency' => $salePrice['currency'],
                        'priceType' => [
                            'meta' => [
                                'href' => "https://api.moysklad.ru/api/remap/1.2/context/companysettings/pricetype/{$childPriceTypeId}",
                                'type' => 'pricetype',
                                'mediaType' => 'application/json',
                            ],
                        ],
                    ];

                    Log::debug('Price type mapped', [
                        'main_price_type_id' => $mainPriceTypeId,
                        'child_price_type_id' => $childPriceTypeId,
                        'value' => $salePrice['value'],
                    ]);
                }
            }
        }

        return $result;
    }

    /**
     * Update custom attributes only
     */
    protected function updateAttributesOnly(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId,
        array $strategyData
    ): array {
        Log::debug('Updating attributes only', [
            'entity_type' => $entityType,
            'main_entity_id' => $mainEntityId,
            'child_entity_id' => $childEntityId,
            'attributes' => $strategyData['attributes'] ?? [],
        ]);

        // 1. GET entity from main account with expanded attributes
        $mainEntity = $this->moySkladService
            ->setAccessToken($mainAccount->access_token)
            ->get("entity/{$entityType}/{$mainEntityId}?expand=attributes");

        if (!isset($mainEntity['data'])) {
            throw new \Exception("Failed to fetch main entity: {$mainEntityId}");
        }

        $mainEntityData = $mainEntity['data'];
        $mainAttributes = $mainEntityData['attributes'] ?? [];

        if (empty($mainAttributes)) {
            Log::warning('No attributes found in main entity', [
                'entity_type' => $entityType,
                'main_entity_id' => $mainEntityId,
            ]);
            return [];
        }

        // 2. Find attribute mappings by name for allowed attributes
        $allowedAttributeNames = $strategyData['attributes'] ?? [];
        $childAttributes = [];
        $appliedFields = [];

        foreach ($allowedAttributeNames as $attrName) {
            // Find the attribute in main entity by name
            $mainAttribute = null;
            foreach ($mainAttributes as $attr) {
                // МойСклад attributes have 'name' field
                if (($attr['name'] ?? null) === $attrName) {
                    $mainAttribute = $attr;
                    break;
                }
            }

            if (!$mainAttribute) {
                Log::debug('Attribute not found in main entity', [
                    'attribute_name' => $attrName,
                ]);
                continue;
            }

            // Find mapping for this attribute
            $mapping = \App\Models\AttributeMapping::where('parent_account_id', $mainAccount->account_id)
                ->where('child_account_id', $childAccount->account_id)
                ->where('attribute_name', $attrName)
                ->first();

            if (!$mapping) {
                Log::warning('Attribute mapping not found', [
                    'attribute_name' => $attrName,
                    'main_account_id' => $mainAccount->account_id,
                    'child_account_id' => $childAccount->account_id,
                ]);
                continue;
            }

            // Build attribute for child account
            $childAttributes[] = [
                'meta' => [
                    'href' => "https://api.moysklad.ru/api/remap/1.2/entity/{$entityType}/metadata/attributes/{$mapping->child_attribute_id}",
                    'type' => 'attributemetadata',
                    'mediaType' => 'application/json',
                ],
                'value' => $mainAttribute['value'] ?? null,
            ];

            $appliedFields[] = $attrName;

            Log::debug('Attribute mapped', [
                'attribute_name' => $attrName,
                'main_attribute_id' => $mapping->parent_attribute_id,
                'child_attribute_id' => $mapping->child_attribute_id,
                'value' => $mainAttribute['value'] ?? null,
            ]);
        }

        // 3. PUT only attributes to child account
        if (!empty($childAttributes)) {
            Log::debug('Sending PUT request with attributes', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'attributes_count' => count($childAttributes),
            ]);

            $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->put("entity/{$entityType}/{$childEntityId}", [
                    'attributes' => $childAttributes,
                ]);

            Log::info('Attributes updated successfully', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'fields_updated' => $appliedFields,
            ]);
        } else {
            Log::warning('No attributes to update', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
            ]);
        }

        return $appliedFields;
    }

    /**
     * Update base fields only (name, description, article, code)
     *
     * @param string $entityType Entity type (product, service, variant, bundle)
     * @param Account $mainAccount Main account
     * @param Account $childAccount Child account
     * @param string $mainEntityId Entity ID in main account
     * @param string $childEntityId Entity ID in child account
     * @param array $strategyData Strategy data with base_fields list
     * @return array Applied fields
     */
    protected function updateBaseFields(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId,
        array $strategyData
    ): array {
        $baseFields = $strategyData['base_fields'] ?? [];
        $appliedFields = [];

        if (empty($baseFields)) {
            Log::warning('No base fields to update', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
            ]);
            return $appliedFields;
        }

        // 1. GET entity from main account
        $mainEntityData = $this->getEntityFromAccount(
            $mainAccount,
            $entityType,
            $mainEntityId
        );

        if (!$mainEntityData) {
            throw new \RuntimeException("Failed to fetch entity from main account: {$mainEntityId}");
        }

        // 2. Extract base fields
        $updateData = [];
        foreach ($baseFields as $fieldName) {
            if (array_key_exists($fieldName, $mainEntityData)) {
                $updateData[$fieldName] = $mainEntityData[$fieldName];
                $appliedFields[] = $fieldName;
            } else {
                Log::debug('Base field not found in entity', [
                    'field' => $fieldName,
                    'entity_id' => $mainEntityId,
                ]);
            }
        }

        // 3. PUT to child account
        if (!empty($updateData)) {
            $this->updateEntityInAccount(
                $childAccount,
                $entityType,
                $childEntityId,
                $updateData
            );

            Log::info('Base fields updated successfully', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'applied_fields' => $appliedFields,
            ]);
        } else {
            Log::warning('No base fields to apply', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
            ]);
        }

        return $appliedFields;
    }

    /**
     * Update mixed simple fields
     *
     * Handles combination of: base_fields, standard_prices, custom_prices, attributes, other standard fields
     *
     * @param string $entityType Entity type (product, service, variant, bundle)
     * @param Account $mainAccount Main account
     * @param Account $childAccount Child account
     * @param string $mainEntityId Entity ID in main account
     * @param string $childEntityId Entity ID in child account
     * @param array $strategyData Strategy data with multiple field categories
     * @param SyncSetting $settings Sync settings for price/attribute mappings
     * @return array Applied fields
     */
    protected function updateMixedFields(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId,
        array $strategyData,
        SyncSetting $settings
    ): array {
        $appliedFields = [];

        // 1. GET entity with expand for prices and attributes
        $mainEntityData = $this->getEntityFromAccount(
            $mainAccount,
            $entityType,
            $mainEntityId,
            'attributes,salePrices'
        );

        if (!$mainEntityData) {
            throw new \RuntimeException("Failed to fetch entity from main account: {$mainEntityId}");
        }

        $updateData = [];

        // 2. Add base fields
        $baseFields = $strategyData['base_fields'] ?? [];
        foreach ($baseFields as $fieldName) {
            if (array_key_exists($fieldName, $mainEntityData)) {
                $updateData[$fieldName] = $mainEntityData[$fieldName];
                $appliedFields[] = $fieldName;
            }
        }

        // 3. Add other standard fields (non-price)
        $standardFields = $strategyData['standard_fields'] ?? [];
        foreach ($standardFields as $fieldName) {
            if (array_key_exists($fieldName, $mainEntityData)) {
                $updateData[$fieldName] = $mainEntityData[$fieldName];
                $appliedFields[] = $fieldName;
            }
        }

        // 4. Add standard prices (buyPrice, minPrice)
        $standardPrices = $strategyData['standard_prices'] ?? [];
        foreach ($standardPrices as $priceField) {
            if (array_key_exists($priceField, $mainEntityData)) {
                $updateData[$priceField] = $mainEntityData[$priceField];
                $appliedFields[] = $priceField;
            }
        }

        // 5. Add custom prices (salePrices from price_mappings)
        $customPriceNames = $strategyData['custom_prices'] ?? [];
        if (!empty($customPriceNames) && isset($mainEntityData['salePrices'])) {
            $mainSalePrices = $mainEntityData['salePrices'];
            $priceMappings = $settings->price_mappings ?? [];

            $mappedSalePrices = $this->buildMappedSalePrices(
                $mainSalePrices,
                $priceMappings,
                $customPriceNames,
                $mainAccount->account_id,
                $childAccount->account_id
            );

            if (!empty($mappedSalePrices)) {
                $updateData['salePrices'] = $mappedSalePrices;
                $appliedFields[] = 'salePrices';
                $appliedFields = array_merge($appliedFields, $customPriceNames);
            }
        }

        // 6. Add attributes
        $attributeNames = $strategyData['attributes'] ?? [];
        if (!empty($attributeNames) && isset($mainEntityData['attributes'])) {
            $mainAttributes = $mainEntityData['attributes'];
            $childAttributes = [];

            foreach ($attributeNames as $attrName) {
                // Find attribute in main entity by name
                $mainAttribute = null;
                foreach ($mainAttributes as $attr) {
                    if (($attr['name'] ?? null) === $attrName) {
                        $mainAttribute = $attr;
                        break;
                    }
                }

                if (!$mainAttribute) {
                    Log::warning('Attribute not found in main entity', [
                        'attribute_name' => $attrName,
                        'main_entity_id' => $mainEntityId,
                    ]);
                    continue;
                }

                // Find AttributeMapping by name
                $mapping = \App\Models\AttributeMapping::where('parent_account_id', $mainAccount->account_id)
                    ->where('child_account_id', $childAccount->account_id)
                    ->where('attribute_name', $attrName)
                    ->first();

                if (!$mapping) {
                    Log::warning('AttributeMapping not found for attribute', [
                        'attribute_name' => $attrName,
                        'main_account_id' => $mainAccount->account_id,
                    ]);
                    continue;
                }

                // Build attribute for child account
                $childAttributes[] = [
                    'meta' => [
                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/{$entityType}/metadata/attributes/{$mapping->child_attribute_id}",
                        'type' => 'attributemetadata',
                        'mediaType' => 'application/json',
                    ],
                    'value' => $mainAttribute['value'] ?? null,
                ];

                $appliedFields[] = $attrName;
            }

            if (!empty($childAttributes)) {
                $updateData['attributes'] = $childAttributes;
            }
        }

        // 7. PUT to child account
        if (!empty($updateData)) {
            $this->updateEntityInAccount(
                $childAccount,
                $entityType,
                $childEntityId,
                $updateData
            );

            Log::info('Mixed fields updated successfully', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'applied_fields' => $appliedFields,
            ]);
        } else {
            Log::warning('No fields to apply in mixed update', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
            ]);
        }

        return $appliedFields;
    }

    /**
     * Fallback to full sync
     *
     * Used when complex dependencies are detected (productFolder, uom, country, packs, characteristics, components)
     * Performs a complete entity GET and PUT to ensure all dependencies are synchronized
     *
     * @param string $entityType Entity type (product, service, variant, bundle)
     * @param Account $mainAccount Main account
     * @param Account $childAccount Child account
     * @param string $mainEntityId Entity ID in main account
     * @param string $childEntityId Entity ID in child account
     * @return array Applied fields (all fields from entity)
     */
    protected function fullUpdate(
        string $entityType,
        Account $mainAccount,
        Account $childAccount,
        string $mainEntityId,
        string $childEntityId
    ): array {
        Log::info('Falling back to full sync', [
            'entity_type' => $entityType,
            'main_entity_id' => $mainEntityId,
            'child_entity_id' => $childEntityId,
        ]);

        // 1. GET full entity from main account with all expansions
        $expand = 'attributes,salePrices';
        if (in_array($entityType, ['product', 'variant'])) {
            $expand .= ',images';
        }

        $mainEntityData = $this->getEntityFromAccount(
            $mainAccount,
            $entityType,
            $mainEntityId,
            $expand
        );

        if (!$mainEntityData) {
            throw new \RuntimeException("Failed to fetch entity for full sync: {$mainEntityId}");
        }

        // 2. Extract all updateable fields (exclude read-only fields like meta, id, accountId, etc.)
        $readOnlyFields = ['meta', 'id', 'accountId', 'owner', 'shared', 'group', 'updated', 'syncId'];
        $updateData = array_diff_key($mainEntityData, array_flip($readOnlyFields));

        // 3. PUT full entity to child account
        $this->updateEntityInAccount(
            $childAccount,
            $entityType,
            $childEntityId,
            $updateData
        );

        // 4. Return list of all updated fields
        $appliedFields = array_keys($updateData);

        Log::info('Full sync completed', [
            'entity_type' => $entityType,
            'child_entity_id' => $childEntityId,
            'fields_count' => count($appliedFields),
        ]);

        return $appliedFields;
    }

    /**
     * Extract entity ID from МойСклад href
     *
     * @param string $href Entity href URL
     * @return string Entity ID (UUID)
     */
    protected function extractEntityId(string $href): string
    {
        $parts = explode('/', $href);
        return end($parts);
    }
}
