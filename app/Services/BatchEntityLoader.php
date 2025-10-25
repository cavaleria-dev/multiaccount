<?php

namespace App\Services;

use App\Models\SyncQueue;
use App\Models\SyncSetting;
use Illuminate\Support\Facades\Log;

/**
 * Универсальный загрузчик сущностей с поддержкой фильтров и batch задач
 *
 * Поддерживает 4 стратегии загрузки:
 * 1. no_filter - загрузить всё (фильтры отключены или отсутствуют)
 * 2. single_api_filter - один API фильтр для AND логики
 * 3. multiple_api_filters - несколько API фильтров для OR логики (по группе) + дедупликация
 * 4. client_side - загрузить всё + PHP фильтрация (fallback для сложных фильтров)
 */
class BatchEntityLoader
{
    public function __construct(
        protected MoySkladService $moySkladService,
        protected ProductFilterService $filterService,
        protected AttributeSyncService $attributeSyncService
    ) {}

    /**
     * Загрузить сущности с фильтрами и создать batch задачи
     *
     * Автоматически выбирает оптимальную стратегию загрузки.
     *
     * @param string $entityType Тип сущности (product, service, bundle, variant)
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $accessToken Access token главного аккаунта
     * @param SyncSetting|null $syncSettings Настройки синхронизации
     * @return int Количество созданных задач
     * @throws \Exception
     */
    public function loadAndCreateBatchTasks(
        string $entityType,
        string $mainAccountId,
        string $childAccountId,
        string $accessToken,
        ?SyncSetting $syncSettings = null
    ): int {
        // Получить конфигурацию для типа сущности
        $config = EntityConfig::get($entityType);

        // Подготовить фильтры
        $filters = null;
        $attributesMetadata = null;

        if ($syncSettings && $syncSettings->product_filters_enabled && $syncSettings->product_filters && $config['supports_filters']) {
            // Декодировать фильтры
            $filters = $syncSettings->product_filters;
            if (is_string($filters)) {
                $filters = json_decode($filters, true);
            }

            // Загрузить metadata если фильтры в UI формате (старом или новом)
            if (isset($filters['groups']) || isset($filters['conditions'])) {
                try {
                    $attributesMetadata = $this->attributeSyncService->loadAttributesMetadata(
                        $mainAccountId,
                        $config['filter_metadata_type']
                    );
                } catch (\Exception $e) {
                    Log::warning('Failed to load attributes metadata for filters', [
                        'entity_type' => $entityType,
                        'main_account_id' => $mainAccountId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // Определить стратегию загрузки
        $strategy = $this->determineStrategy($filters, $attributesMetadata, $mainAccountId, $entityType);

        Log::info("Batch entity sync strategy selected", [
            'entity_type' => $entityType,
            'strategy' => $strategy,
            'filters_enabled' => $filters !== null
        ]);

        // Выполнить загрузку по выбранной стратегии
        $entities = match($strategy) {
            'no_filter' => $this->loadAll($entityType, $mainAccountId, $childAccountId, $accessToken),
            'single_api_filter' => $this->loadWithSingleFilter($entityType, $mainAccountId, $childAccountId, $accessToken, $filters, $attributesMetadata),
            'multiple_api_filters' => $this->loadWithMultipleFilters($entityType, $mainAccountId, $childAccountId, $accessToken, $filters, $attributesMetadata),
            'client_side' => $this->loadAllAndFilterClientSide($entityType, $mainAccountId, $childAccountId, $accessToken, $filters, $attributesMetadata),
            default => throw new \Exception("Unknown strategy: {$strategy}")
        };

        // Применить match_field фильтр если требуется
        if ($config['has_match_field_check'] && $syncSettings) {
            $entities = $this->filterByMatchField($entities, $entityType, $syncSettings);
        }

        // Создать batch задачи
        return $this->createBatchTasks($entities, $entityType, $mainAccountId, $childAccountId);
    }

    /**
     * Загрузить сущности через /entity/assortment и создать batch задачи
     *
     * Универсальный метод для одновременной загрузки product/service/bundle.
     * Использует общие фильтры (атрибуты, папки) и применяет индивидуальные
     * match_field проверки для каждого типа.
     *
     * @param array $entityTypes Массив типов ['product', 'service', 'bundle']
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $accessToken Access token
     * @param SyncSetting|null $syncSettings Настройки синхронизации
     * @return int Общее количество созданных задач
     * @throws \Exception
     */
    public function loadAndCreateAssortmentBatchTasks(
        array $entityTypes,
        string $mainAccountId,
        string $childAccountId,
        string $accessToken,
        ?SyncSetting $syncSettings = null
    ): int {
        if (empty($entityTypes)) {
            Log::warning("No entity types provided for assortment batch tasks");
            return 0;
        }

        // 1. Подготовить фильтры (общие для всех типов)
        $filters = null;
        $attributesMetadata = null;

        if ($syncSettings && $syncSettings->product_filters_enabled && $syncSettings->product_filters) {
            $filters = $syncSettings->product_filters;
            if (is_string($filters)) {
                $filters = json_decode($filters, true);
            }

            // Загрузить metadata (product metadata общие для всех типов)
            if (isset($filters['groups']) || isset($filters['conditions'])) {
                try {
                    $attributesMetadata = $this->attributeSyncService->loadAttributesMetadata(
                        $mainAccountId,
                        'product'  // Всегда product (общие атрибуты для product/service/bundle)
                    );
                } catch (\Exception $e) {
                    Log::warning('Failed to load attributes metadata for assortment filters', [
                        'entity_types' => $entityTypes,
                        'main_account_id' => $mainAccountId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // 2. Загрузить ВСЕ сущности через /entity/assortment
        $allEntities = $this->loadFromAssortment(
            $entityTypes,
            $mainAccountId,
            $childAccountId,
            $accessToken,
            $filters,
            $attributesMetadata
        );

        // 3. Разделить по типам и применить фильтры
        $totalTasksCreated = 0;
        $allFilteredEntities = []; // Собираем все отфильтрованные сущности для pre-sync групп

        foreach ($entityTypes as $entityType) {
            // 3.1 Отфильтровать сущности по типу
            $entitiesOfType = array_filter($allEntities, function($entity) use ($entityType) {
                $metaType = $this->extractEntityTypeFromMeta($entity['meta']['type'] ?? '');
                return $metaType === $entityType;
            });

            // Сбросить ключи массива (array_filter сохраняет ключи)
            $entitiesOfType = array_values($entitiesOfType);

            Log::debug("Entities filtered by type", [
                'entity_type' => $entityType,
                'count' => count($entitiesOfType)
            ]);

            // 3.2 Применить match_field фильтр (индивидуально для каждого типа)
            $config = EntityConfig::get($entityType);
            if ($config['has_match_field_check'] && $syncSettings) {
                $entitiesOfType = $this->filterByMatchField($entitiesOfType, $entityType, $syncSettings);
            }

            // 3.3 Собрать все отфильтрованные сущности для pre-sync групп
            $allFilteredEntities = array_merge($allFilteredEntities, $entitiesOfType);

            // 3.4 Создать batch задачи для этого типа
            $tasksCreated = $this->createBatchTasks(
                $entitiesOfType,
                $entityType,
                $mainAccountId,
                $childAccountId
            );

            $totalTasksCreated += $tasksCreated;
        }

        // 4. Pre-sync групп товаров для ВСЕХ отфильтрованных сущностей (если настройка включена)
        if ($syncSettings && $syncSettings->create_product_folders && !empty($allFilteredEntities)) {
            try {
                $productFolderSyncService = app(\App\Services\ProductFolderSyncService::class);
                $productFolderSyncService->syncFoldersForEntities(
                    $mainAccountId,
                    $childAccountId,
                    $allFilteredEntities
                );
                Log::info('Product folders pre-synced for all filtered entities', [
                    'total_entities' => count($allFilteredEntities),
                    'entity_types' => $entityTypes
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to pre-sync product folders', [
                    'main_account_id' => $mainAccountId,
                    'child_account_id' => $childAccountId,
                    'error' => $e->getMessage()
                ]);
                // Не прерываем выполнение - группы будут синхронизированы индивидуально в batch job
            }
        }

        Log::info("Assortment batch tasks created", [
            'entity_types' => $entityTypes,
            'total_tasks_created' => $totalTasksCreated
        ]);

        return $totalTasksCreated;
    }

    /**
     * Определить оптимальную стратегию загрузки
     *
     * @param array|null $filters Фильтры
     * @param array|null $attributesMetadata Метаданные атрибутов
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $entityType Тип сущности
     * @return string Название стратегии
     */
    protected function determineStrategy(
        ?array $filters,
        ?array $attributesMetadata,
        string $mainAccountId,
        string $entityType
    ): string {
        // Если фильтры не заданы
        if (!$filters) {
            return 'no_filter';
        }

        // Если фильтры в UI формате - сконвертировать для анализа
        if (isset($filters['groups'])) {
            $filters = $this->filterService->convertFromUiFormat($filters, $mainAccountId, $attributesMetadata);
        }

        // Если фильтры отключены или пустые
        if (!($filters['enabled'] ?? false) || empty($filters['conditions'] ?? [])) {
            return 'no_filter';
        }

        // Проверить логику (AND или OR)
        $logic = strtoupper($filters['logic'] ?? 'AND');

        // Если AND логика - попробовать построить API фильтр
        if ($logic === 'AND') {
            $apiFilter = $this->filterService->buildApiFilter($filters, $mainAccountId, $attributesMetadata);
            return $apiFilter !== null ? 'single_api_filter' : 'client_side';
        }

        // OR логика - проверить можно ли для каждой группы построить API фильтр
        $conditions = $filters['conditions'] ?? [];
        $groups = array_filter($conditions, fn($c) => ($c['type'] ?? null) === 'group');

        if (empty($groups)) {
            // Нет групп - fallback на client-side
            return 'client_side';
        }

        // Проверить каждую группу
        $allGroupsSupported = true;
        foreach ($groups as $group) {
            $groupFilter = [
                'enabled' => true,
                'logic' => strtoupper($group['logic'] ?? 'AND'),
                'conditions' => $group['conditions'] ?? []
            ];

            // Если группа не AND или API фильтр не построился
            if ($groupFilter['logic'] !== 'AND') {
                $allGroupsSupported = false;
                break;
            }

            $apiFilter = $this->filterService->buildApiFilter($groupFilter, $mainAccountId, $attributesMetadata);
            if ($apiFilter === null) {
                $allGroupsSupported = false;
                break;
            }
        }

        return $allGroupsSupported ? 'multiple_api_filters' : 'client_side';
    }

    /**
     * Загрузить все сущности без фильтра
     *
     * @param string $entityType Тип сущности
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $accessToken Access token
     * @return array Массив сущностей
     */
    protected function loadAll(string $entityType, string $mainAccountId, string $childAccountId, string $accessToken): array
    {
        $config = EntityConfig::get($entityType);
        $entities = [];
        $offset = 0;
        $limit = 100;  // Batch size
        $totalLoaded = 0;

        Log::info("Loading all entities without filter", [
            'entity_type' => $entityType,
            'main_account_id' => $mainAccountId
        ]);

        do {
            $params = [
                'limit' => $limit,
                'offset' => $offset,
                'expand' => $config['expand']
            ];

            $response = $this->moySkladService
                ->setAccessToken($accessToken)
                ->setLogContext(
                    accountId: $mainAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $childAccountId,
                    entityType: $entityType,
                    entityId: null  // Batch load - нет конкретного entity_id
                )
                ->setOperationContext(
                    operationType: 'load',
                    operationResult: 'success'
                )
                ->get($config['endpoint'], $params);

            $rows = $response['data']['rows'] ?? [];
            $pageCount = count($rows);
            $totalLoaded += $pageCount;

            $entities = array_merge($entities, $rows);

            Log::debug("Loaded page", [
                'entity_type' => $entityType,
                'offset' => $offset,
                'page_count' => $pageCount,
                'total' => $totalLoaded
            ]);

            $offset += $limit;

        } while ($pageCount === $limit);

        Log::info("Finished loading all entities", [
            'entity_type' => $entityType,
            'total_loaded' => $totalLoaded
        ]);

        return $entities;
    }

    /**
     * Загрузить сущности с одним API фильтром (AND логика)
     *
     * @param string $entityType Тип сущности
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $accessToken Access token
     * @param array $filters Фильтры
     * @param array|null $attributesMetadata Метаданные атрибутов
     * @return array Массив сущностей
     */
    protected function loadWithSingleFilter(
        string $entityType,
        string $mainAccountId,
        string $childAccountId,
        string $accessToken,
        array $filters,
        ?array $attributesMetadata
    ): array {
        $config = EntityConfig::get($entityType);

        // Определить endpoint и фильтр
        $useAssortment = $config['use_assortment_for_filters'] ?? false;
        $endpoint = $useAssortment ? '/entity/assortment' : $config['endpoint'];

        // Построить API фильтр
        $apiFilterString = $useAssortment
            ? $this->filterService->buildAssortmentApiFilter($filters, $entityType, $mainAccountId, $attributesMetadata)
            : $this->filterService->buildApiFilter($filters, $mainAccountId, $attributesMetadata);

        if ($apiFilterString === null) {
            // Не должно произойти (determineStrategy проверил), но на всякий случай
            Log::warning("API filter returned null in single_api_filter strategy, fallback to client-side");
            return $this->loadAllAndFilterClientSide($entityType, $mainAccountId, $childAccountId, $accessToken, $filters, $attributesMetadata);
        }

        $entities = [];
        $offset = 0;
        $limit = 100;
        $totalLoaded = 0;

        // Построить полный URL для диагностики
        $fullUrlPreview = config('moysklad.api_url') . $endpoint .
            '?filter=' . urlencode($apiFilterString) .
            '&expand=' . urlencode($config['expand']) .
            '&limit=100&offset=0';

        Log::info("Loading entities with single API filter", [
            'entity_type' => $entityType,
            'main_account_id' => $mainAccountId,
            'use_assortment' => $useAssortment,
            'endpoint' => $endpoint,
            'api_filter_string' => $apiFilterString,  // Не закодированная строка
            'full_url_preview' => $fullUrlPreview  // Полный URL (первая страница)
        ]);

        do {
            $params = [
                'limit' => $limit,
                'offset' => $offset,
                'expand' => $config['expand'],
                'filter' => $apiFilterString
            ];

            try {
                $response = $this->moySkladService
                    ->setAccessToken($accessToken)
                    ->setLogContext(
                        accountId: $mainAccountId,
                        direction: 'main_to_child',
                        relatedAccountId: $childAccountId,
                        entityType: $entityType,
                        entityId: null  // Batch load with filter
                    )
                    ->setOperationContext(
                        operationType: 'load',
                        operationResult: 'success'
                    )
                    ->get($endpoint, $params);

                $rows = $response['data']['rows'] ?? [];
                $pageCount = count($rows);
                $totalLoaded += $pageCount;

                $entities = array_merge($entities, $rows);

                $offset += $limit;

            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();

                // Проверить ошибку 412 Precondition Failed (code 1034 - неизвестное поле фильтрации)
                if (str_contains($errorMessage, '412') || str_contains($errorMessage, '1034') || str_contains($errorMessage, 'Precondition Failed')) {
                    Log::warning("API filter failed with 412/1034, fallback to client-side filtering", [
                        'entity_type' => $entityType,
                        'api_filter_string' => $apiFilterString,
                        'error' => $errorMessage
                    ]);

                    // Fallback на client-side фильтрацию
                    return $this->loadAllAndFilterClientSide($entityType, $mainAccountId, $childAccountId, $accessToken, $filters, $attributesMetadata);
                }

                // Для других ошибок - пробросить дальше
                throw $e;
            }

        } while ($pageCount === $limit);

        Log::info("Finished loading with single API filter", [
            'entity_type' => $entityType,
            'total_loaded' => $totalLoaded
        ]);

        return $entities;
    }

    /**
     * Загрузить сущности с несколькими API фильтрами (OR логика)
     *
     * Для каждой группы делается отдельный API запрос, результаты дедуплицируются по entity_id.
     *
     * @param string $entityType Тип сущности
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $accessToken Access token
     * @param array $filters Фильтры
     * @param array|null $attributesMetadata Метаданные атрибутов
     * @return array Массив уникальных сущностей
     */
    protected function loadWithMultipleFilters(
        string $entityType,
        string $mainAccountId,
        string $childAccountId,
        string $accessToken,
        array $filters,
        ?array $attributesMetadata
    ): array {
        $config = EntityConfig::get($entityType);

        // Определить endpoint
        $useAssortment = $config['use_assortment_for_filters'] ?? false;
        $endpoint = $useAssortment ? '/entity/assortment' : $config['endpoint'];

        // Конвертировать из UI формата если нужно
        if (isset($filters['groups'])) {
            $filters = $this->filterService->convertFromUiFormat($filters, $mainAccountId, $attributesMetadata);
        }

        $conditions = $filters['conditions'] ?? [];
        $groups = array_filter($conditions, fn($c) => ($c['type'] ?? null) === 'group');

        if (empty($groups)) {
            Log::warning("No groups found in multiple_api_filters strategy");
            return $this->loadAll($entityType, $mainAccountId, $childAccountId, $accessToken);
        }

        $uniqueEntities = [];  // [entity_id => entity_data]
        $totalApiRequests = 0;
        $totalLoaded = 0;

        Log::info("Loading entities with multiple API filters (OR logic)", [
            'entity_type' => $entityType,
            'main_account_id' => $mainAccountId,
            'use_assortment' => $useAssortment,
            'endpoint' => $endpoint,
            'groups_count' => count($groups)
        ]);

        foreach ($groups as $groupIndex => $group) {
            // Построить фильтр для этой группы
            $groupFilter = [
                'enabled' => true,
                'logic' => 'AND',
                'conditions' => $group['conditions'] ?? []
            ];

            // Построить API фильтр (с или без assortment)
            $apiFilterString = $useAssortment
                ? $this->filterService->buildAssortmentApiFilter($groupFilter, $entityType, $mainAccountId, $attributesMetadata)
                : $this->filterService->buildApiFilter($groupFilter, $mainAccountId, $attributesMetadata);

            if ($apiFilterString === null) {
                Log::warning("Failed to build API filter for group, skipping", [
                    'group_index' => $groupIndex
                ]);
                continue;
            }

            // Построить полный URL для диагностики
            $fullUrlPreview = config('moysklad.api_url') . $endpoint .
                '?filter=' . urlencode($apiFilterString) .
                '&expand=' . urlencode($config['expand']) .
                '&limit=100&offset=0';

            Log::info("Loading group with API filter", [
                'entity_type' => $entityType,
                'group_index' => $groupIndex,
                'api_filter_string' => $apiFilterString,  // Не закодированная строка
                'full_url_preview' => $fullUrlPreview  // Полный URL (первая страница)
            ]);

            // Загрузить сущности с этим фильтром
            $offset = 0;
            $limit = 100;
            $groupLoaded = 0;

            do {
                $params = [
                    'limit' => $limit,
                    'offset' => $offset,
                    'expand' => $config['expand'],
                    'filter' => $apiFilterString
                ];

                try {
                    $response = $this->moySkladService
                        ->setAccessToken($accessToken)
                        ->setLogContext(
                            accountId: $mainAccountId,
                            direction: 'main_to_child',
                            relatedAccountId: $childAccountId,
                            entityType: $entityType,
                            entityId: null  // Batch load with multiple filters (OR logic)
                        )
                        ->setOperationContext(
                            operationType: 'load',
                            operationResult: 'success'
                        )
                        ->get($endpoint, $params);

                    $rows = $response['data']['rows'] ?? [];
                    $pageCount = count($rows);
                    $groupLoaded += $pageCount;
                    $totalApiRequests++;

                    // Дедупликация по entity_id
                    foreach ($rows as $entity) {
                        $uniqueEntities[$entity['id']] = $entity;
                    }

                    $offset += $limit;

                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();

                    // Проверить ошибку 412 Precondition Failed (code 1034 - неизвестное поле фильтрации)
                    if (str_contains($errorMessage, '412') || str_contains($errorMessage, '1034') || str_contains($errorMessage, 'Precondition Failed')) {
                        Log::warning("API filter failed with 412/1034 for group, fallback to client-side for ALL groups", [
                            'entity_type' => $entityType,
                            'group_index' => $groupIndex,
                            'api_filter_string' => $apiFilterString,
                            'error' => $errorMessage
                        ]);

                        // Fallback на client-side для ВСЕГО фильтра (не можем продолжить с API фильтрами)
                        return $this->loadAllAndFilterClientSide($entityType, $mainAccountId, $childAccountId, $accessToken, $filters, $attributesMetadata);
                    }

                    // Для других ошибок - пробросить дальше
                    throw $e;
                }

            } while ($pageCount === $limit);

            Log::debug("Loaded group", [
                'group_index' => $groupIndex,
                'group_loaded' => $groupLoaded,
                'unique_so_far' => count($uniqueEntities)
            ]);

            $totalLoaded += $groupLoaded;
        }

        Log::info("Finished loading with multiple API filters", [
            'entity_type' => $entityType,
            'groups_processed' => count($groups),
            'total_loaded' => $totalLoaded,
            'unique_entities' => count($uniqueEntities),
            'duplicates_removed' => $totalLoaded - count($uniqueEntities),
            'total_api_requests' => $totalApiRequests
        ]);

        return array_values($uniqueEntities);
    }

    /**
     * Загрузить все сущности и отфильтровать на стороне PHP
     *
     * Используется как fallback для сложных фильтров, которые нельзя выразить через API.
     *
     * @param string $entityType Тип сущности
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $accessToken Access token
     * @param array $filters Фильтры
     * @param array|null $attributesMetadata Метаданные атрибутов
     * @return array Массив отфильтрованных сущностей
     */
    protected function loadAllAndFilterClientSide(
        string $entityType,
        string $mainAccountId,
        string $childAccountId,
        string $accessToken,
        array $filters,
        ?array $attributesMetadata
    ): array {
        $config = EntityConfig::get($entityType);
        $filteredEntities = [];
        $offset = 0;
        $limit = 100;
        $totalLoaded = 0;
        $totalFiltered = 0;

        Log::info("Loading all entities with client-side filtering", [
            'entity_type' => $entityType,
            'main_account_id' => $mainAccountId
        ]);

        do {
            $params = [
                'limit' => $limit,
                'offset' => $offset,
                'expand' => $config['expand']
            ];

            $response = $this->moySkladService
                ->setAccessToken($accessToken)
                ->setLogContext(
                    accountId: $mainAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $childAccountId,
                    entityType: $entityType,
                    entityId: null  // Batch load for client-side filtering
                )
                ->setOperationContext(
                    operationType: 'load',
                    operationResult: 'success'
                )
                ->get($config['endpoint'], $params);

            $rows = $response['data']['rows'] ?? [];
            $pageCount = count($rows);
            $totalLoaded += $pageCount;

            // Применить client-side фильтр
            foreach ($rows as $entity) {
                if ($this->filterService->passes($entity, $filters, $mainAccountId, $attributesMetadata)) {
                    $filteredEntities[] = $entity;
                } else {
                    $totalFiltered++;
                }
            }

            $offset += $limit;

        } while ($pageCount === $limit);

        Log::info("Finished loading with client-side filtering", [
            'entity_type' => $entityType,
            'total_loaded' => $totalLoaded,
            'passed_filter' => count($filteredEntities),
            'filtered_out' => $totalFiltered
        ]);

        return $filteredEntities;
    }

    /**
     * Фильтровать сущности по match_field
     *
     * Применяется только для entity types с has_match_field_check=true (services).
     *
     * @param array $entities Массив сущностей
     * @param string $entityType Тип сущности
     * @param SyncSetting $syncSettings Настройки синхронизации
     * @return array Отфильтрованный массив
     */
    protected function filterByMatchField(array $entities, string $entityType, SyncSetting $syncSettings): array
    {
        $config = EntityConfig::get($entityType);
        $matchFieldSetting = $config['match_field_setting'];
        $matchField = $syncSettings->$matchFieldSetting ?? $config['default_match_field'];

        $filtered = [];
        $filteredCount = 0;

        foreach ($entities as $entity) {
            // name - обязательное поле
            if ($matchField === 'name') {
                if (!empty($entity['name'])) {
                    $filtered[] = $entity;
                } else {
                    $filteredCount++;
                    Log::warning("{$entityType} has empty name (required field!)", [
                        'entity_id' => $entity['id'] ?? 'unknown'
                    ]);
                }
            } else {
                // code, externalCode, barcode
                if (!empty($entity[$matchField])) {
                    $filtered[] = $entity;
                } else {
                    $filteredCount++;
                }
            }
        }

        if ($filteredCount > 0) {
            Log::info("Entities filtered by match_field", [
                'entity_type' => $entityType,
                'match_field' => $matchField,
                'before' => count($entities),
                'after' => count($filtered),
                'filtered_out' => $filteredCount
            ]);
        }

        return $filtered;
    }

    /**
     * Создать batch задачи из массива сущностей
     *
     * Разбивает сущности на батчи по 100 и создаёт задачи в sync_queue.
     *
     * @param array $entities Массив сущностей
     * @param string $entityType Тип сущности
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @return int Количество созданных задач
     */
    protected function createBatchTasks(
        array $entities,
        string $entityType,
        string $mainAccountId,
        string $childAccountId
    ): int {
        if (empty($entities)) {
            Log::info("No entities to create batch tasks", ['entity_type' => $entityType]);
            return 0;
        }

        $config = EntityConfig::get($entityType);
        $batchEntityType = $config['batch_entity_type'];
        $tasksCreated = 0;

        // Разбить на batch по 100 сущностей
        $batches = array_chunk($entities, 100);

        // Определить правильный ключ для payload (ProcessSyncQueueJob ожидает специфичные ключи)
        $payloadKey = match($batchEntityType) {
            'batch_products' => 'products',
            'batch_services' => 'services',
            'batch_bundles' => 'bundles',
            default => 'entities'  // Fallback для других типов
        };

        foreach ($batches as $batch) {
            SyncQueue::create([
                'account_id' => $childAccountId,
                'entity_type' => $batchEntityType,
                'entity_id' => null,  // Not used for batch
                'operation' => 'batch_sync',
                'priority' => 10,  // High priority (manual sync)
                'scheduled_at' => now(),
                'status' => 'pending',
                'attempts' => 0,
                'payload' => [
                    'main_account_id' => $mainAccountId,
                    $payloadKey => $batch  // Динамический ключ (products/services/bundles)
                ]
            ]);

            $tasksCreated++;
        }

        Log::info("Batch tasks created", [
            'entity_type' => $entityType,
            'total_entities' => count($entities),
            'batch_tasks_created' => $tasksCreated,
            'entities_per_task_avg' => round(count($entities) / max($tasksCreated, 1), 2)
        ]);

        return $tasksCreated;
    }

    /**
     * Загрузить сущности из /entity/assortment
     *
     * Универсальный endpoint для загрузки product/service/bundle с общими фильтрами.
     *
     * @param array $entityTypes Массив типов для загрузки ['product', 'service', 'bundle']
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $accessToken Access token
     * @param array|null $filters Фильтры (папки, атрибуты)
     * @param array|null $attributesMetadata Метаданные атрибутов
     * @return array Массив всех загруженных сущностей
     */
    protected function loadFromAssortment(
        array $entityTypes,
        string $mainAccountId,
        string $childAccountId,
        string $accessToken,
        ?array $filters,
        ?array $attributesMetadata
    ): array {
        $entities = [];
        $offset = 0;
        $limit = 100;

        // Построить фильтр по типам: type=product;type=service;type=bundle
        $typeFilters = array_map(fn($t) => "type=$t", $entityTypes);
        $typeFilterString = implode(';', $typeFilters);

        // Построить пользовательский API фильтр
        $userFilterString = null;
        if ($filters) {
            $userFilterString = $this->filterService->buildApiFilter($filters, $mainAccountId, $attributesMetadata);
        }

        // Объединить фильтры
        $finalFilter = $typeFilterString;
        if ($userFilterString) {
            $finalFilter .= ';' . $userFilterString;
        }

        // Построить унифицированный expand для всех типов
        $unifiedExpand = EntityConfig::buildUnifiedExpand($entityTypes);

        Log::info("Loading assortment with combined types", [
            'entity_types' => $entityTypes,
            'type_filter' => $typeFilterString,
            'user_filter' => $userFilterString,
            'final_filter' => $finalFilter,
            'unified_expand' => $unifiedExpand
        ]);

        do {
            $params = [
                'limit' => $limit,
                'offset' => $offset,
                'expand' => $unifiedExpand,
                'filter' => $finalFilter
            ];

            $response = $this->moySkladService
                ->setAccessToken($accessToken)
                ->setLogContext(
                    accountId: $mainAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $childAccountId,
                    entityType: 'assortment',
                    entityId: null
                )
                ->setOperationContext(
                    operationType: 'load',
                    operationResult: 'success'
                )
                ->get('/entity/assortment', $params);

            $rows = $response['data']['rows'] ?? [];
            $pageCount = count($rows);

            $entities = array_merge($entities, $rows);

            $offset += $limit;

        } while ($pageCount === $limit);

        Log::info("Finished loading assortment", [
            'entity_types' => $entityTypes,
            'total_loaded' => count($entities)
        ]);

        return $entities;
    }

    /**
     * Извлечь тип сущности из meta.type
     *
     * МойСклад может возвращать либо короткую форму ("product"), либо полный URL.
     *
     * @param string $metaType Значение meta.type
     * @return string Тип сущности (product/service/bundle/variant)
     */
    protected function extractEntityTypeFromMeta(string $metaType): string
    {
        // Если это полный URL (https://api.moysklad.ru/api/remap/1.2/entity/product)
        if (str_contains($metaType, '/')) {
            $parts = explode('/', $metaType);
            return end($parts);
        }

        // Иначе это уже короткая форма ("product")
        return $metaType;
    }
}
