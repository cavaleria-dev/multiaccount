<?php

namespace App\Services\Traits;

use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\AttributeMapping;
use App\Models\PriceTypeMapping;
use Illuminate\Support\Facades\Log;

/**
 * Трейт с общими методами для сервисов синхронизации
 *
 * Используется в: ProductSyncService, VariantSyncService, BundleSyncService, ServiceSyncService
 *
 * Требуемые зависимости в классе:
 * - protected MoySkladService $moySkladService
 * - protected CustomEntitySyncService $customEntitySyncService
 * - protected StandardEntitySyncService $standardEntitySync
 * - protected ProductFilterService $productFilterService (опционально, для passesFilters)
 */
trait SyncHelpers
{
    /**
     * Извлечь ID сущности из href
     */
    protected function extractEntityId(string $href): ?string
    {
        if (empty($href)) {
            return null;
        }

        $parts = explode('/', $href);
        return end($parts) ?: null;
    }

    /**
     * Синхронизировать цены (buyPrice + salePrices)
     *
     * Включает автоматическую синхронизацию валют через StandardEntitySyncService::syncCurrency()
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param array $entity Сущность с ценами (product, variant, service)
     * @param SyncSetting $settings Настройки синхронизации
     * @return array ['salePrices' => array, 'buyPrice' => array|null]
     */
    protected function syncPrices(
        string $mainAccountId,
        string $childAccountId,
        array $entity,
        SyncSetting $settings
    ): array {
        $salePrices = [];
        $buyPrice = null;

        // Получить цены из главной сущности
        $mainSalePrices = $entity['salePrices'] ?? [];
        $mainBuyPrice = $entity['buyPrice'] ?? null;

        // Если настроены маппинги цен, использовать их
        $priceMappings = $settings->price_mappings;
        $useMappings = !empty($priceMappings) && is_array($priceMappings);

        // Обработка закупочной цены
        if ($mainBuyPrice && $useMappings) {
            foreach ($priceMappings as $mapping) {
                $mainPriceTypeId = $mapping['main_price_type_id'] ?? null;
                $childPriceTypeId = $mapping['child_price_type_id'] ?? null;

                // buyPrice → buyPrice
                if ($mainPriceTypeId === 'buyPrice' && $childPriceTypeId === 'buyPrice') {
                    $buyPrice = $mainBuyPrice;

                    // Синхронизировать валюту в buyPrice
                    if (isset($buyPrice['currency']['meta']['href'])) {
                        $parentCurrencyId = $this->extractEntityId($buyPrice['currency']['meta']['href']);
                        if ($parentCurrencyId) {
                            $childCurrencyId = $this->standardEntitySync->syncCurrency(
                                $mainAccountId,
                                $childAccountId,
                                $parentCurrencyId
                            );
                            if ($childCurrencyId) {
                                $buyPrice['currency'] = [
                                    'meta' => [
                                        'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                        'type' => 'currency',
                                        'mediaType' => 'application/json'
                                    ]
                                ];
                            } else {
                                // Если валюта не найдена - удалить ссылку, МойСклад использует дефолтную
                                Log::warning('Failed to sync currency for buyPrice, using default', [
                                    'main_account_id' => $mainAccountId,
                                    'child_account_id' => $childAccountId,
                                    'parent_currency_id' => $parentCurrencyId
                                ]);
                                unset($buyPrice['currency']);
                            }
                        }
                    }
                }
                // buyPrice → salePrice
                elseif ($mainPriceTypeId === 'buyPrice' && $childPriceTypeId && $childPriceTypeId !== 'buyPrice') {
                    $priceData = [
                        'value' => $mainBuyPrice['value'] ?? 0,
                        'priceType' => [
                            'meta' => [
                                'href' => config('moysklad.api_url') . "/context/companysettings/pricetype/{$childPriceTypeId}",
                                'type' => 'pricetype',
                                'mediaType' => 'application/json'
                            ]
                        ]
                    ];

                    // Синхронизировать валюту если указана
                    if (isset($mainBuyPrice['currency']['meta']['href'])) {
                        $parentCurrencyId = $this->extractEntityId($mainBuyPrice['currency']['meta']['href']);
                        if ($parentCurrencyId) {
                            $childCurrencyId = $this->standardEntitySync->syncCurrency(
                                $mainAccountId,
                                $childAccountId,
                                $parentCurrencyId
                            );
                            if ($childCurrencyId) {
                                $priceData['currency'] = [
                                    'meta' => [
                                        'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                        'type' => 'currency',
                                        'mediaType' => 'application/json'
                                    ]
                                ];
                            }
                        }
                    }

                    $salePrices[] = $priceData;
                }
            }
        } elseif ($mainBuyPrice && !$useMappings) {
            // Если маппинги не используются - копировать buyPrice с синхронизацией валюты
            $buyPrice = $mainBuyPrice;

            // Синхронизировать валюту в buyPrice
            if (isset($buyPrice['currency']['meta']['href'])) {
                $parentCurrencyId = $this->extractEntityId($buyPrice['currency']['meta']['href']);
                if ($parentCurrencyId) {
                    $childCurrencyId = $this->standardEntitySync->syncCurrency(
                        $mainAccountId,
                        $childAccountId,
                        $parentCurrencyId
                    );
                    if ($childCurrencyId) {
                        $buyPrice['currency'] = [
                            'meta' => [
                                'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                'type' => 'currency',
                                'mediaType' => 'application/json'
                            ]
                        ];
                    } else {
                        // Если валюта не найдена - удалить ссылку, МойСклад использует дефолтную
                        Log::warning('Failed to sync currency for buyPrice (no mappings), using default', [
                            'main_account_id' => $mainAccountId,
                            'child_account_id' => $childAccountId,
                            'parent_currency_id' => $parentCurrencyId
                        ]);
                        unset($buyPrice['currency']);
                    }
                }
            }
        }

        // Обработка продажных цен
        foreach ($mainSalePrices as $priceInfo) {
            $priceTypeHref = $priceInfo['priceType']['meta']['href'] ?? null;

            if (!$priceTypeHref) {
                continue;
            }

            // Извлечь ID типа цены из href
            $mainPriceTypeId = $this->extractEntityId($priceTypeHref);

            if (!$mainPriceTypeId) {
                continue;
            }

            // Если используются маппинги - проверить разрешен ли этот тип цены
            $childPriceTypeId = null;

            if ($useMappings) {
                $allowed = false;
                foreach ($priceMappings as $mapping) {
                    if (($mapping['main_price_type_id'] ?? null) === $mainPriceTypeId) {
                        $childPriceTypeId = $mapping['child_price_type_id'] ?? null;
                        $allowed = true;
                        break;
                    }
                }

                // Если тип цены не в маппинге - пропустить
                if (!$allowed) {
                    continue;
                }
            }

            // Если маппинг указан явно - использовать его
            if ($childPriceTypeId) {
                // salePrice → buyPrice
                if ($childPriceTypeId === 'buyPrice') {
                    $buyPrice = [
                        'value' => $priceInfo['value'] ?? 0
                    ];

                    // Синхронизировать валюту (приоритет: из priceInfo, потом из mainBuyPrice)
                    $currencySource = $priceInfo['currency'] ?? $mainBuyPrice['currency'] ?? null;
                    if ($currencySource && isset($currencySource['meta']['href'])) {
                        $parentCurrencyId = $this->extractEntityId($currencySource['meta']['href']);
                        if ($parentCurrencyId) {
                            $childCurrencyId = $this->standardEntitySync->syncCurrency(
                                $mainAccountId,
                                $childAccountId,
                                $parentCurrencyId
                            );
                            if ($childCurrencyId) {
                                $buyPrice['currency'] = [
                                    'meta' => [
                                        'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                        'type' => 'currency',
                                        'mediaType' => 'application/json'
                                    ]
                                ];
                            }
                        }
                    }
                } else {
                    // salePrice → salePrice
                    $priceData = [
                        'value' => $priceInfo['value'] ?? 0,
                        'priceType' => [
                            'meta' => [
                                'href' => config('moysklad.api_url') . "/context/companysettings/pricetype/{$childPriceTypeId}",
                                'type' => 'pricetype',
                                'mediaType' => 'application/json'
                            ]
                        ]
                    ];

                    // Синхронизировать валюту если указана
                    if (isset($priceInfo['currency']['meta']['href'])) {
                        $parentCurrencyId = $this->extractEntityId($priceInfo['currency']['meta']['href']);
                        if ($parentCurrencyId) {
                            $childCurrencyId = $this->standardEntitySync->syncCurrency(
                                $mainAccountId,
                                $childAccountId,
                                $parentCurrencyId
                            );
                            if ($childCurrencyId) {
                                $priceData['currency'] = [
                                    'meta' => [
                                        'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                        'type' => 'currency',
                                        'mediaType' => 'application/json'
                                    ]
                                ];
                            }
                        }
                    }

                    $salePrices[] = $priceData;
                }
            } else {
                // Иначе найти или создать тип цены по имени (старая логика)
                $priceTypeName = $priceInfo['priceType']['name'] ?? null;

                if (!$priceTypeName) {
                    continue;
                }

                $priceTypeMapping = $this->getOrCreatePriceType($mainAccountId, $childAccountId, $priceTypeName);

                if ($priceTypeMapping) {
                    $priceData = [
                        'value' => $priceInfo['value'] ?? 0,
                        'priceType' => [
                            'meta' => [
                                'href' => config('moysklad.api_url') . "/context/companysettings/pricetype/{$priceTypeMapping->child_price_type_id}",
                                'type' => 'pricetype',
                                'mediaType' => 'application/json'
                            ]
                        ]
                    ];

                    // Синхронизировать валюту если указана
                    if (isset($priceInfo['currency']['meta']['href'])) {
                        $parentCurrencyId = $this->extractEntityId($priceInfo['currency']['meta']['href']);
                        if ($parentCurrencyId) {
                            $childCurrencyId = $this->standardEntitySync->syncCurrency(
                                $mainAccountId,
                                $childAccountId,
                                $parentCurrencyId
                            );
                            if ($childCurrencyId) {
                                $priceData['currency'] = [
                                    'meta' => [
                                        'href' => config('moysklad.api_url') . "/entity/currency/{$childCurrencyId}",
                                        'type' => 'currency',
                                        'mediaType' => 'application/json'
                                    ]
                                ];
                            }
                        }
                    }

                    $salePrices[] = $priceData;
                }
            }
        }

        $result = ['salePrices' => $salePrices];
        if ($buyPrice) {
            $result['buyPrice'] = $buyPrice;
        }

        return $result;
    }

    /**
     * Получить или создать тип цены в дочернем аккаунте
     */
    protected function getOrCreatePriceType(string $mainAccountId, string $childAccountId, string $priceTypeName): ?PriceTypeMapping
    {
        // Проверить маппинг
        $mapping = PriceTypeMapping::where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $childAccountId)
            ->where('price_type_name', $priceTypeName)
            ->first();

        if ($mapping) {
            return $mapping;
        }

        // Создать тип цены в дочернем (если включено auto_create_price_types)
        $settings = SyncSetting::where('account_id', $childAccountId)->first();

        if (!$settings || !$settings->auto_create_price_types) {
            return null;
        }

        try {
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            // Получить типы цен из главного
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $mainPriceTypesResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->get('context/companysettings');

            $mainPriceTypes = $mainPriceTypesResult['data']['priceTypes'] ?? [];
            $mainPriceType = null;

            foreach ($mainPriceTypes as $pt) {
                if ($pt['name'] === $priceTypeName) {
                    $mainPriceType = $pt;
                    break;
                }
            }

            if (!$mainPriceType) {
                return null;
            }

            // Создать в дочернем
            $childPriceTypesResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->post('context/companysettings/pricetype', [
                    'name' => $priceTypeName
                ]);

            $childPriceType = $childPriceTypesResult['data'];

            // Сохранить маппинг
            $mapping = PriceTypeMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'parent_price_type_id' => $mainPriceType['id'],
                'child_price_type_id' => $childPriceType['id'],
                'price_type_name' => $priceTypeName,
                'auto_created' => true,
            ]);

            Log::info('Price type created in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'price_type_name' => $priceTypeName
            ]);

            return $mapping;

        } catch (\Exception $e) {
            Log::error('Failed to create price type in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'price_type_name' => $priceTypeName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Проверить проходит ли сущность (product/bundle) фильтры
     *
     * Требуется наличие: protected ProductFilterService $productFilterService
     */
    protected function passesFilters(array $entity, SyncSetting $settings, string $mainAccountId): bool
    {
        // Если фильтры отключены - пропускаем все сущности
        if (!$settings->product_filters_enabled) {
            return true;
        }

        // Получить конфигурацию фильтров
        $filters = $settings->product_filters;

        // Если фильтры не заданы - пропускаем все сущности
        if (!$filters) {
            return true;
        }

        // Если это строка JSON - декодировать
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
        }

        // Применить фильтры
        return $this->productFilterService->passes($entity, $filters);
    }

}
