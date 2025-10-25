<?php

namespace App\Services;

use App\Models\StandardEntityMapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Сервис для синхронизации стандартных справочников МойСклад
 * (единицы измерения, валюты, страны, ставки НДС)
 */
class StandardEntitySyncService
{
    protected MoySkladService $moySkladService;

    // Кеши для избежания повторных запросов в рамках одной операции
    protected array $uomCache = [];
    protected array $currencyCache = [];
    protected array $countryCache = [];

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Синхронизация единицы измерения (UOM) между аккаунтами
     *
     * @param string $parentAccountId UUID родительского аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $parentUomId UUID единицы измерения в родительском аккаунте
     * @return string|null UUID единицы измерения в дочернем аккаунте или null
     */
    public function syncUom(string $parentAccountId, string $childAccountId, string $parentUomId): ?string
    {
        try {
            $cacheKey = "{$parentAccountId}:{$childAccountId}:{$parentUomId}";

            // Проверяем локальный кеш
            if (isset($this->uomCache[$cacheKey])) {
                return $this->uomCache[$cacheKey];
            }

            // Загружаем единицу измерения из родительского аккаунта
            $parentUom = $this->moySkladService->getEntity($parentAccountId, 'uom', $parentUomId);
            if (!$parentUom || !isset($parentUom['code'])) {
                Log::warning('StandardEntitySync: UOM not found or has no code', [
                    'parent_account_id' => $parentAccountId,
                    'parent_uom_id' => $parentUomId
                ]);
                return null;
            }

            $code = $parentUom['code'];

            // Проверяем существующий маппинг в БД
            $mapping = StandardEntityMapping::where('parent_account_id', $parentAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('entity_type', 'uom')
                ->where('code', $code)
                ->first();

            if ($mapping) {
                $this->uomCache[$cacheKey] = $mapping->child_entity_id;
                return $mapping->child_entity_id;
            }

            // Ищем единицу измерения с таким же кодом в дочернем аккаунте
            $childUoms = $this->moySkladService->getList($childAccountId, 'uom', [
                'filter' => "code={$code}"
            ]);

            if (!empty($childUoms['rows'])) {
                $childUom = $childUoms['rows'][0];
                $childUomId = $this->extractId($childUom['meta']['href']);

                // Сохраняем маппинг (atomic operation to prevent race conditions)
                StandardEntityMapping::firstOrCreate(
                    [
                        'parent_account_id' => $parentAccountId,
                        'child_account_id' => $childAccountId,
                        'entity_type' => 'uom',
                        'code' => $code,
                    ],
                    [
                        'parent_entity_id' => $parentUomId,
                        'child_entity_id' => $childUomId,
                        'name' => $parentUom['name'] ?? null,
                    ]
                );

                $this->uomCache[$cacheKey] = $childUomId;

                Log::info('StandardEntitySync: UOM mapped', [
                    'code' => $code,
                    'parent_id' => $parentUomId,
                    'child_id' => $childUomId
                ]);

                return $childUomId;
            }

            // Если не найдено - создаём новую единицу измерения в дочернем аккаунте
            $newUom = [
                'name' => $parentUom['name'],
                'code' => $code,
                'description' => $parentUom['description'] ?? null,
            ];

            $createdUom = $this->moySkladService->createEntity($childAccountId, 'uom', $newUom);
            if (!$createdUom) {
                Log::error('StandardEntitySync: Failed to create UOM in child account', [
                    'code' => $code,
                    'child_account_id' => $childAccountId
                ]);
                return null;
            }

            $childUomId = $this->extractId($createdUom['meta']['href']);

            // Сохраняем маппинг (atomic operation to prevent race conditions)
            StandardEntityMapping::firstOrCreate(
                [
                    'parent_account_id' => $parentAccountId,
                    'child_account_id' => $childAccountId,
                    'entity_type' => 'uom',
                    'code' => $code,
                ],
                [
                    'parent_entity_id' => $parentUomId,
                    'child_entity_id' => $childUomId,
                    'name' => $parentUom['name'] ?? null,
                ]
            );

            $this->uomCache[$cacheKey] = $childUomId;

            Log::info('StandardEntitySync: UOM created and mapped', [
                'code' => $code,
                'parent_id' => $parentUomId,
                'child_id' => $childUomId
            ]);

            return $childUomId;

        } catch (\Exception $e) {
            Log::error('StandardEntitySync: UOM sync failed', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'parent_uom_id' => $parentUomId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Синхронизация валюты между аккаунтами
     *
     * @param string $parentAccountId UUID родительского аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $parentCurrencyId UUID валюты в родительском аккаунте
     * @return string|null UUID валюты в дочернем аккаунте или null
     */
    public function syncCurrency(string $parentAccountId, string $childAccountId, string $parentCurrencyId): ?string
    {
        try {
            $cacheKey = "{$parentAccountId}:{$childAccountId}:{$parentCurrencyId}";

            // Проверяем локальный кеш
            if (isset($this->currencyCache[$cacheKey])) {
                return $this->currencyCache[$cacheKey];
            }

            // Загружаем валюту из родительского аккаунта
            $parentCurrency = $this->moySkladService->getEntity($parentAccountId, 'currency', $parentCurrencyId);
            if (!$parentCurrency || !isset($parentCurrency['isoCode'])) {
                Log::warning('StandardEntitySync: Currency not found or has no isoCode', [
                    'parent_account_id' => $parentAccountId,
                    'parent_currency_id' => $parentCurrencyId
                ]);
                return null;
            }

            $isoCode = $parentCurrency['isoCode'];

            // Проверяем существующий маппинг в БД
            $mapping = StandardEntityMapping::where('parent_account_id', $parentAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('entity_type', 'currency')
                ->where('code', $isoCode)
                ->first();

            if ($mapping) {
                $this->currencyCache[$cacheKey] = $mapping->child_entity_id;
                return $mapping->child_entity_id;
            }

            // Ищем валюту с таким же isoCode в дочернем аккаунте
            $childCurrencies = $this->moySkladService->getList($childAccountId, 'currency', [
                'filter' => "isoCode={$isoCode}"
            ]);

            if (!empty($childCurrencies['rows'])) {
                $childCurrency = $childCurrencies['rows'][0];
                $childCurrencyId = $this->extractId($childCurrency['meta']['href']);

                // Сохраняем маппинг (atomic operation to prevent race conditions)
                StandardEntityMapping::firstOrCreate(
                    [
                        'parent_account_id' => $parentAccountId,
                        'child_account_id' => $childAccountId,
                        'entity_type' => 'currency',
                        'code' => $isoCode,
                    ],
                    [
                        'parent_entity_id' => $parentCurrencyId,
                        'child_entity_id' => $childCurrencyId,
                        'name' => $parentCurrency['name'] ?? null,
                        'metadata' => [
                            'symbol' => $parentCurrency['symbol'] ?? null,
                        ],
                    ]
                );

                $this->currencyCache[$cacheKey] = $childCurrencyId;

                Log::info('StandardEntitySync: Currency mapped', [
                    'isoCode' => $isoCode,
                    'parent_id' => $parentCurrencyId,
                    'child_id' => $childCurrencyId
                ]);

                return $childCurrencyId;
            }

            // Валюта не найдена - в МойСклад валюты обычно уже есть
            Log::warning('StandardEntitySync: Currency not found in child account', [
                'isoCode' => $isoCode,
                'child_account_id' => $childAccountId
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('StandardEntitySync: Currency sync failed', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'parent_currency_id' => $parentCurrencyId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Синхронизация страны между аккаунтами
     *
     * @param string $parentAccountId UUID родительского аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $parentCountryId UUID страны в родительском аккаунте
     * @return string|null UUID страны в дочернем аккаунте или null
     */
    public function syncCountry(string $parentAccountId, string $childAccountId, string $parentCountryId): ?string
    {
        try {
            $cacheKey = "{$parentAccountId}:{$childAccountId}:{$parentCountryId}";

            // Проверяем локальный кеш
            if (isset($this->countryCache[$cacheKey])) {
                return $this->countryCache[$cacheKey];
            }

            // Загружаем страну из родительского аккаунта
            $parentCountry = $this->moySkladService->getEntity($parentAccountId, 'country', $parentCountryId);
            if (!$parentCountry || !isset($parentCountry['code'])) {
                Log::warning('StandardEntitySync: Country not found or has no code', [
                    'parent_account_id' => $parentAccountId,
                    'parent_country_id' => $parentCountryId
                ]);
                return null;
            }

            $code = $parentCountry['code'];

            // Проверяем существующий маппинг в БД
            $mapping = StandardEntityMapping::where('parent_account_id', $parentAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('entity_type', 'country')
                ->where('code', $code)
                ->first();

            if ($mapping) {
                $this->countryCache[$cacheKey] = $mapping->child_entity_id;
                return $mapping->child_entity_id;
            }

            // Ищем страну с таким же кодом в дочернем аккаунте
            $childCountries = $this->moySkladService->getList($childAccountId, 'country', [
                'filter' => "code={$code}"
            ]);

            if (!empty($childCountries['rows'])) {
                $childCountry = $childCountries['rows'][0];
                $childCountryId = $this->extractId($childCountry['meta']['href']);

                // Сохраняем маппинг (atomic operation to prevent race conditions)
                StandardEntityMapping::firstOrCreate(
                    [
                        'parent_account_id' => $parentAccountId,
                        'child_account_id' => $childAccountId,
                        'entity_type' => 'country',
                        'code' => $code,
                    ],
                    [
                        'parent_entity_id' => $parentCountryId,
                        'child_entity_id' => $childCountryId,
                        'name' => $parentCountry['name'] ?? null,
                    ]
                );

                $this->countryCache[$cacheKey] = $childCountryId;

                Log::info('StandardEntitySync: Country mapped', [
                    'code' => $code,
                    'parent_id' => $parentCountryId,
                    'child_id' => $childCountryId
                ]);

                return $childCountryId;
            }

            // Страна не найдена - в МойСклад страны обычно уже есть
            Log::warning('StandardEntitySync: Country not found in child account', [
                'code' => $code,
                'child_account_id' => $childAccountId
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('StandardEntitySync: Country sync failed', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'parent_country_id' => $parentCountryId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Синхронизация ставки НДС между аккаунтами
     *
     * @param string $parentAccountId UUID родительского аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param int|null $vatRate Ставка НДС (20, 10, 0 или null)
     * @return int|null Ставка НДС (та же самая)
     */
    public function syncVat(string $parentAccountId, string $childAccountId, ?int $vatRate): ?int
    {
        try {
            // Ставки НДС одинаковы во всех аккаунтах, но для полноты сохраняем маппинг
            $code = $vatRate !== null ? (string)$vatRate : 'null';

            // Создаем маппинг (atomic operation to prevent race conditions)
            $mapping = StandardEntityMapping::firstOrCreate(
                [
                    'parent_account_id' => $parentAccountId,
                    'child_account_id' => $childAccountId,
                    'entity_type' => 'vat',
                    'code' => $code,
                ],
                [
                    'parent_entity_id' => $code,
                    'child_entity_id' => $code,
                    'name' => $vatRate !== null ? "НДС {$vatRate}%" : 'Без НДС',
                    'metadata' => [
                        'rate' => $vatRate,
                    ],
                ]
            );

            if ($mapping->wasRecentlyCreated) {
                Log::info('StandardEntitySync: VAT mapped', [
                    'rate' => $vatRate,
                    'code' => $code
                ]);
            }

            return $vatRate;

        } catch (\Exception $e) {
            Log::error('StandardEntitySync: VAT sync failed', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'vat_rate' => $vatRate,
                'error' => $e->getMessage()
            ]);
            return $vatRate; // Возвращаем исходную ставку даже при ошибке
        }
    }

    /**
     * Извлечение UUID из href
     *
     * @param string $href URL с UUID
     * @return string UUID
     */
    protected function extractId(string $href): string
    {
        $parts = explode('/', $href);
        return end($parts);
    }

    /**
     * Получить cached UOM mapping из БД (БЕЗ GET запросов)
     *
     * Используется для batch синхронизации после пре-кеширования
     *
     * @param string $parentAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $parentUomId UUID UOM в главном аккаунте
     * @return string|null UUID UOM в дочернем аккаунте или null если не найден
     */
    public function getCachedUomMapping(
        string $parentAccountId,
        string $childAccountId,
        string $parentUomId
    ): ?string {
        // Проверить in-memory cache
        $cacheKey = "{$parentAccountId}:{$childAccountId}:{$parentUomId}";

        if (isset($this->uomCache[$cacheKey])) {
            return $this->uomCache[$cacheKey];
        }

        // Проверить БД
        $mapping = StandardEntityMapping::where('parent_account_id', $parentAccountId)
            ->where('child_account_id', $childAccountId)
            ->where('entity_type', 'uom')
            ->where('parent_entity_id', $parentUomId)
            ->first();

        if ($mapping) {
            $this->uomCache[$cacheKey] = $mapping->child_entity_id;
            return $mapping->child_entity_id;
        }

        return null; // Not cached yet
    }

    /**
     * Получить cached Country mapping из БД (БЕЗ GET запросов)
     *
     * @param string $parentAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $parentCountryId UUID Country в главном аккаунте
     * @return string|null UUID Country в дочернем аккаунте или null если не найден
     */
    public function getCachedCountryMapping(
        string $parentAccountId,
        string $childAccountId,
        string $parentCountryId
    ): ?string {
        // Проверить in-memory cache
        $cacheKey = "{$parentAccountId}:{$childAccountId}:{$parentCountryId}";

        if (isset($this->countryCache[$cacheKey])) {
            return $this->countryCache[$cacheKey];
        }

        // Проверить БД
        $mapping = StandardEntityMapping::where('parent_account_id', $parentAccountId)
            ->where('child_account_id', $childAccountId)
            ->where('entity_type', 'country')
            ->where('parent_entity_id', $parentCountryId)
            ->first();

        if ($mapping) {
            $this->countryCache[$cacheKey] = $mapping->child_entity_id;
            return $mapping->child_entity_id;
        }

        return null; // Not cached yet
    }

    /**
     * Получить cached Currency mapping из БД (БЕЗ GET запросов)
     *
     * @param string $parentAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $parentCurrencyId UUID Currency в главном аккаунте
     * @return string|null UUID Currency в дочернем аккаунте или null если не найден
     */
    public function getCachedCurrencyMapping(
        string $parentAccountId,
        string $childAccountId,
        string $parentCurrencyId
    ): ?string {
        // Проверить in-memory cache
        $cacheKey = "{$parentAccountId}:{$childAccountId}:{$parentCurrencyId}";

        if (isset($this->currencyCache[$cacheKey])) {
            return $this->currencyCache[$cacheKey];
        }

        // Проверить БД
        $mapping = StandardEntityMapping::where('parent_account_id', $parentAccountId)
            ->where('child_account_id', $childAccountId)
            ->where('entity_type', 'currency')
            ->where('parent_entity_id' , $parentCurrencyId)
            ->first();

        if ($mapping) {
            $this->currencyCache[$cacheKey] = $mapping->child_entity_id;
            return $mapping->child_entity_id;
        }

        return null; // Not cached yet
    }

    /**
     * Очистка локальных кешей
     */
    public function clearCache(): void
    {
        $this->uomCache = [];
        $this->currencyCache = [];
        $this->countryCache = [];
    }
}

