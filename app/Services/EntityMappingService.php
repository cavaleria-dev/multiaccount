<?php

namespace App\Services;

use App\Models\Account;
use App\Models\EntityMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы с маппингами сущностей между main и child аккаунтами
 *
 * Основная задача: найти существующую сущность в child по match_field
 * и создать маппинг, чтобы избежать ошибки 412 при попытке создания дубликата
 */
class EntityMappingService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Найти или создать маппинг услуги
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $mainServiceId UUID услуги в main
     * @param string $matchField code|externalCode|name
     * @param mixed $matchValue Значение поля
     * @return EntityMapping|null
     */
    public function findOrCreateServiceMapping(
        string $mainAccountId,
        string $childAccountId,
        string $mainServiceId,
        string $matchField,
        $matchValue
    ): ?EntityMapping {
        // 1. Проверить существующий маппинг в БД
        $mapping = EntityMapping::where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $childAccountId)
            ->where('parent_entity_id', $mainServiceId)
            ->where('entity_type', 'service')
            ->where('sync_direction', 'main_to_child')
            ->first();

        if ($mapping) {
            Log::debug('Service mapping found in database', [
                'main_service_id' => $mainServiceId,
                'child_service_id' => $mapping->child_entity_id
            ]);
            return $mapping;
        }

        // 2. Маппинг не найден → попробовать найти услугу в child по match_field
        if (!$matchValue) {
            Log::warning('Cannot search for service in child - match_value is empty', [
                'main_service_id' => $mainServiceId,
                'match_field' => $matchField
            ]);
            return null;
        }

        $childService = $this->findServiceInChild(
            $childAccountId,
            $matchField,
            $matchValue
        );

        if (!$childService) {
            Log::info('Service not found in child - will need to create', [
                'match_field' => $matchField,
                'match_value' => $matchValue
            ]);
            return null;
        }

        // 3. Услуга найдена в child → создать маппинг
        Log::info('Service found in child - creating mapping', [
            'main_service_id' => $mainServiceId,
            'child_service_id' => $childService['id'],
            'service_name' => $childService['name'] ?? null,
            'match_field' => $matchField,
            'match_value' => $matchValue
        ]);

        // Use firstOrCreate to avoid race conditions
        $mapping = EntityMapping::firstOrCreate(
            [
                // Unique keys
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'service',
                'parent_entity_id' => $mainServiceId,
                'sync_direction' => 'main_to_child',
            ],
            [
                // Additional fields
                'child_entity_id' => $childService['id'],
                'match_field' => $matchField,
                'match_value' => $matchValue,
            ]
        );

        return $mapping;
    }

    /**
     * Найти или создать маппинг товара
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $mainProductId UUID товара в main
     * @param string $matchField code|externalCode|name
     * @param mixed $matchValue Значение поля
     * @return EntityMapping|null
     */
    public function findOrCreateProductMapping(
        string $mainAccountId,
        string $childAccountId,
        string $mainProductId,
        string $matchField,
        $matchValue
    ): ?EntityMapping {
        // 1. Проверить существующий маппинг в БД
        $mapping = EntityMapping::where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $childAccountId)
            ->where('parent_entity_id', $mainProductId)
            ->where('entity_type', 'product')
            ->where('sync_direction', 'main_to_child')
            ->first();

        if ($mapping) {
            Log::debug('Product mapping found in database', [
                'main_product_id' => $mainProductId,
                'child_product_id' => $mapping->child_entity_id
            ]);
            return $mapping;
        }

        // 2. Маппинг не найден → попробовать найти товар в child по match_field
        if (!$matchValue) {
            Log::warning('Cannot search for product in child - match_value is empty', [
                'main_product_id' => $mainProductId,
                'match_field' => $matchField
            ]);
            return null;
        }

        $childProduct = $this->findProductInChild(
            $childAccountId,
            $matchField,
            $matchValue
        );

        if (!$childProduct) {
            Log::info('Product not found in child - will need to create', [
                'match_field' => $matchField,
                'match_value' => $matchValue
            ]);
            return null;
        }

        // 3. Товар найден в child → создать маппинг
        Log::info('Product found in child - creating mapping', [
            'main_product_id' => $mainProductId,
            'child_product_id' => $childProduct['id'],
            'product_name' => $childProduct['name'] ?? null,
            'match_field' => $matchField,
            'match_value' => $matchValue
        ]);

        // Use firstOrCreate to avoid race conditions
        $mapping = EntityMapping::firstOrCreate(
            [
                // Unique keys
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'product',
                'parent_entity_id' => $mainProductId,
                'sync_direction' => 'main_to_child',
            ],
            [
                // Additional fields
                'child_entity_id' => $childProduct['id'],
                'match_field' => $matchField,
                'match_value' => $matchValue,
            ]
        );

        return $mapping;
    }

    /**
     * Найти или создать маппинг комплекта
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $mainBundleId UUID комплекта в main
     * @param string $matchField code|externalCode|name
     * @param mixed $matchValue Значение поля
     * @return EntityMapping|null
     */
    public function findOrCreateBundleMapping(
        string $mainAccountId,
        string $childAccountId,
        string $mainBundleId,
        string $matchField,
        $matchValue
    ): ?EntityMapping {
        // 1. Проверить существующий маппинг в БД
        $mapping = EntityMapping::where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $childAccountId)
            ->where('parent_entity_id', $mainBundleId)
            ->where('entity_type', 'bundle')
            ->where('sync_direction', 'main_to_child')
            ->first();

        if ($mapping) {
            Log::debug('Bundle mapping found in database', [
                'main_bundle_id' => $mainBundleId,
                'child_bundle_id' => $mapping->child_entity_id
            ]);
            return $mapping;
        }

        // 2. Маппинг не найден → попробовать найти комплект в child по match_field
        if (!$matchValue) {
            Log::warning('Cannot search for bundle in child - match_value is empty', [
                'main_bundle_id' => $mainBundleId,
                'match_field' => $matchField
            ]);
            return null;
        }

        $childBundle = $this->findBundleInChild(
            $childAccountId,
            $matchField,
            $matchValue
        );

        if (!$childBundle) {
            Log::info('Bundle not found in child - will need to create', [
                'match_field' => $matchField,
                'match_value' => $matchValue
            ]);
            return null;
        }

        // 3. Комплект найден в child → создать маппинг
        Log::info('Bundle found in child - creating mapping', [
            'main_bundle_id' => $mainBundleId,
            'child_bundle_id' => $childBundle['id'],
            'bundle_name' => $childBundle['name'] ?? null,
            'match_field' => $matchField,
            'match_value' => $matchValue
        ]);

        // Use firstOrCreate to avoid race conditions
        $mapping = EntityMapping::firstOrCreate(
            [
                // Unique keys
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'bundle',
                'parent_entity_id' => $mainBundleId,
                'sync_direction' => 'main_to_child',
            ],
            [
                // Additional fields
                'child_entity_id' => $childBundle['id'],
                'match_field' => $matchField,
                'match_value' => $matchValue,
            ]
        );

        return $mapping;
    }

    /**
     * Найти услугу в child по match_field (БЕЗ фильтра!)
     *
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $matchField code|externalCode|name
     * @param mixed $matchValue Значение поля
     * @return array|null Найденная услуга или null
     */
    protected function findServiceInChild(
        string $childAccountId,
        string $matchField,
        $matchValue
    ): ?array {
        try {
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            // Экранировать значение для API фильтра
            $escapedValue = $this->escapeFilterValue($matchValue);

            Log::info('Searching for service in child by match field', [
                'child_account_id' => $childAccountId,
                'match_field' => $matchField,
                'match_value' => $matchValue
            ]);

            // Простой поиск по match_field БЕЗ product_filters
            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'internal',
                    relatedAccountId: null,
                    entityType: 'service',
                    entityId: null
                )
                ->setOperationContext(
                    operationType: 'search_existing',
                    operationResult: null
                )
                ->get('entity/service', [
                    'filter' => "{$matchField}={$escapedValue}",
                    'limit' => 1
                ]);

            $services = $result['data']['rows'] ?? [];

            if (!empty($services)) {
                Log::info('Service found in child', [
                    'service_id' => $services[0]['id'],
                    'service_name' => $services[0]['name'] ?? null,
                    'service_code' => $services[0]['code'] ?? null
                ]);

                // Обновить результат операции
                $this->moySkladService->setOperationContext(
                    operationType: 'search_existing',
                    operationResult: 'found_existing'
                );

                return $services[0];
            }

            Log::info('Service not found in child', [
                'match_field' => $matchField,
                'match_value' => $matchValue
            ]);

            // Обновить результат операции
            $this->moySkladService->setOperationContext(
                operationType: 'search_existing',
                operationResult: 'not_found'
            );

            return null;

        } catch (\Exception $e) {
            Log::error('Failed to search for service in child', [
                'child_account_id' => $childAccountId,
                'match_field' => $matchField,
                'match_value' => $matchValue,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Найти товар в child по match_field (БЕЗ фильтра!)
     *
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $matchField code|externalCode|name
     * @param mixed $matchValue Значение поля
     * @return array|null Найденный товар или null
     */
    protected function findProductInChild(
        string $childAccountId,
        string $matchField,
        $matchValue
    ): ?array {
        try {
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            // Экранировать значение для API фильтра
            $escapedValue = $this->escapeFilterValue($matchValue);

            Log::info('Searching for product in child by match field', [
                'child_account_id' => $childAccountId,
                'match_field' => $matchField,
                'match_value' => $matchValue
            ]);

            // Простой поиск по match_field БЕЗ product_filters
            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'internal',
                    relatedAccountId: null,
                    entityType: 'product',
                    entityId: null
                )
                ->setOperationContext(
                    operationType: 'search_existing',
                    operationResult: null
                )
                ->get('entity/product', [
                    'filter' => "{$matchField}={$escapedValue}",
                    'limit' => 1
                ]);

            $products = $result['data']['rows'] ?? [];

            if (!empty($products)) {
                Log::info('Product found in child', [
                    'product_id' => $products[0]['id'],
                    'product_name' => $products[0]['name'] ?? null,
                    'product_code' => $products[0]['code'] ?? null
                ]);

                // Обновить результат операции
                $this->moySkladService->setOperationContext(
                    operationType: 'search_existing',
                    operationResult: 'found_existing'
                );

                return $products[0];
            }

            Log::info('Product not found in child', [
                'match_field' => $matchField,
                'match_value' => $matchValue
            ]);

            // Обновить результат операции
            $this->moySkladService->setOperationContext(
                operationType: 'search_existing',
                operationResult: 'not_found'
            );

            return null;

        } catch (\Exception $e) {
            Log::error('Failed to search for product in child', [
                'child_account_id' => $childAccountId,
                'match_field' => $matchField,
                'match_value' => $matchValue,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Найти комплект в child по match_field (БЕЗ фильтра!)
     *
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $matchField code|externalCode|name
     * @param mixed $matchValue Значение поля
     * @return array|null Найденный комплект или null
     */
    protected function findBundleInChild(
        string $childAccountId,
        string $matchField,
        $matchValue
    ): ?array {
        try {
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            // Экранировать значение для API фильтра
            $escapedValue = $this->escapeFilterValue($matchValue);

            Log::info('Searching for bundle in child by match field', [
                'child_account_id' => $childAccountId,
                'match_field' => $matchField,
                'match_value' => $matchValue
            ]);

            // Простой поиск по match_field БЕЗ product_filters
            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'internal',
                    relatedAccountId: null,
                    entityType: 'bundle',
                    entityId: null
                )
                ->setOperationContext(
                    operationType: 'search_existing',
                    operationResult: null
                )
                ->get('entity/bundle', [
                    'filter' => "{$matchField}={$escapedValue}",
                    'limit' => 1
                ]);

            $bundles = $result['data']['rows'] ?? [];

            if (!empty($bundles)) {
                Log::info('Bundle found in child', [
                    'bundle_id' => $bundles[0]['id'],
                    'bundle_name' => $bundles[0]['name'] ?? null,
                    'bundle_code' => $bundles[0]['code'] ?? null
                ]);

                // Обновить результат операции
                $this->moySkladService->setOperationContext(
                    operationType: 'search_existing',
                    operationResult: 'found_existing'
                );

                return $bundles[0];
            }

            Log::info('Bundle not found in child', [
                'match_field' => $matchField,
                'match_value' => $matchValue
            ]);

            // Обновить результат операции
            $this->moySkladService->setOperationContext(
                operationType: 'search_existing',
                operationResult: 'not_found'
            );

            return null;

        } catch (\Exception $e) {
            Log::error('Failed to search for bundle in child', [
                'child_account_id' => $childAccountId,
                'match_field' => $matchField,
                'match_value' => $matchValue,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Экранировать значение для МойСклад API фильтра
     *
     * @param mixed $value Значение для экранирования
     * @return string Экранированное значение
     */
    protected function escapeFilterValue($value): string
    {
        // МойСклад требует экранирования специальных символов в фильтрах
        return str_replace([';', '='], ['%3B', '%3D'], (string)$value);
    }
}
