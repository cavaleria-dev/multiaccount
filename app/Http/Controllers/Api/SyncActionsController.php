<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\SyncSetting;
use App\Models\SyncQueue;
use App\Services\MoySkladService;
use App\Services\ProductFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * API контроллер для действий синхронизации
 */
class SyncActionsController extends Controller
{
    /**
     * Синхронизировать всю номенклатуру из main в child
     */
    public function syncAllProducts(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Проверить что это дочерний аккаунт
        $link = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account not found'], 404);
        }

        // Получить настройки синхронизации
        $syncSettings = SyncSetting::where('account_id', $accountId)->first();

        if (!$syncSettings || !$syncSettings->sync_enabled) {
            return response()->json(['error' => 'Sync is not enabled for this account'], 400);
        }

        // Получить main аккаунт
        $mainAccount = Account::where('account_id', $mainAccountId)->first();

        if (!$mainAccount) {
            return response()->json(['error' => 'Main account not found'], 404);
        }

        $moysklad = app(MoySkladService::class);
        $filterService = app(ProductFilterService::class);

        $tasksCreated = 0;

        try {
            // ПРЕ-КЕШ ЗАВИСИМОСТЕЙ (один раз для всех типов сущностей)
            $cacheService = app(\App\Services\DependencyCacheService::class);
            $cacheService->cacheAll($mainAccountId, $accountId);

            Log::info('Dependencies pre-cached for batch sync');

            // Синхронизировать товары (product) - ПАКЕТНО с batch POST
            if ($syncSettings->sync_products) {
                $tasksCreated += $this->createBatchProductTasks(
                    $moysklad,
                    $mainAccount->access_token,
                    $mainAccountId,
                    $accountId
                );
            }

            // Синхронизировать модификации (variant) - ПАКЕТНО по товарам
            if ($syncSettings->sync_variants) {
                $tasksCreated += $this->createBatchVariantTasks(
                    $moysklad,
                    $mainAccount->access_token,
                    $mainAccountId,
                    $accountId
                );
            }

            // Синхронизировать комплекты (bundle) - постранично
            if ($syncSettings->sync_bundles) {
                $tasksCreated += $this->syncEntityType(
                    $moysklad,
                    $mainAccount->access_token,
                    'bundle',
                    $mainAccountId,
                    $accountId,
                    $syncSettings,
                    $filterService
                );
            }

            // Синхронизировать услуги (service) - ПАКЕТНО с batch POST
            if ($syncSettings->sync_services ?? false) {
                $tasksCreated += $this->createBatchServiceTasks(
                    $moysklad,
                    $mainAccount->access_token,
                    $mainAccountId,
                    $accountId
                );
            }

            Log::info('Sync all products initiated', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'tasks_created' => $tasksCreated
            ]);

            return response()->json([
                'tasks_created' => $tasksCreated,
                'status' => 'queued',
                'message' => "Создано {$tasksCreated} задач синхронизации"
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to sync all products', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Failed to create sync tasks: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Синхронизировать сущности постранично (избегаем загрузки всех товаров в память)
     *
     * @param MoySkladService $moysklad
     * @param string $token
     * @param string $entityType product|bundle|service
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $accountId UUID дочернего аккаунта
     * @param SyncSetting $syncSettings
     * @param ProductFilterService $filterService
     * @return int Количество созданных задач
     */
    private function syncEntityType(
        MoySkladService $moysklad,
        string $token,
        string $entityType,
        string $mainAccountId,
        string $accountId,
        SyncSetting $syncSettings,
        ProductFilterService $filterService
    ): int {
        $tasksCreated = 0;
        $offset = 0;
        $totalProcessed = 0;
        $totalFiltered = 0;

        // Проверить нужен ли expand для фильтров
        $needsExpand = in_array($entityType, ['product', 'bundle'])
            && $syncSettings->product_filters_enabled
            && $syncSettings->product_filters;

        // С expand лимит 100, без expand - 1000
        $limit = $needsExpand ? 100 : 1000;

        Log::info("Starting sync for entity type: {$entityType}", [
            'filters_enabled' => $syncSettings->product_filters_enabled ?? false,
            'needs_expand' => $needsExpand,
            'limit' => $limit
        ]);

        do {
            $pageTasksCreated = 0;
            $pageFiltered = 0;

            // Загрузить страницу
            $params = [
                'limit' => $limit,
                'offset' => $offset
            ];

            // Добавить expand для полей, если нужны фильтры
            if ($needsExpand) {
                $params['expand'] = 'productFolder,attributes';
            }

            $response = $moysklad->setAccessToken($token)
                ->get("/entity/{$entityType}", $params);

            $entities = $response['data']['rows'] ?? [];
            $pageCount = count($entities);
            $totalProcessed += $pageCount;

            // Создать задачи для текущей страницы
            foreach ($entities as $entity) {
                // Применить фильтры (только для товаров, комплектов)
                if (in_array($entityType, ['product', 'bundle'])) {
                    if ($syncSettings->product_filters_enabled && $syncSettings->product_filters) {
                        if (!$filterService->passes($entity, $syncSettings->product_filters)) {
                            $pageFiltered++;
                            $totalFiltered++;
                            continue; // Пропустить
                        }
                    }
                }

                // Создать задачу синхронизации
                SyncQueue::create([
                    'account_id' => $accountId,
                    'entity_type' => $entityType,
                    'entity_id' => $entity['id'],
                    'operation' => 'create', // Или 'update' если проверять entity_mappings
                    'priority' => 10, // Высокий приоритет для ручной синхронизации
                    'scheduled_at' => now(),
                    'status' => 'pending',
                    'attempts' => 0,
                    'payload' => [
                        'main_account_id' => $mainAccountId
                    ]
                ]);

                $pageTasksCreated++;
                $tasksCreated++;
            }

            Log::info("Processed page for {$entityType}", [
                'offset' => $offset,
                'page_size' => $pageCount,
                'tasks_created_on_page' => $pageTasksCreated,
                'filtered_on_page' => $pageFiltered,
                'total_processed' => $totalProcessed,
                'total_created' => $tasksCreated,
                'total_filtered' => $totalFiltered
            ]);

            $offset += $limit;

            // Продолжать пока получаем полную страницу
        } while ($pageCount === $limit);

        Log::info("Finished sync for entity type: {$entityType}", [
            'total_entities_processed' => $totalProcessed,
            'total_tasks_created' => $tasksCreated,
            'total_filtered_out' => $totalFiltered
        ]);

        return $tasksCreated;
    }

    /**
     * Создать пакетные задачи синхронизации модификаций (по родительскому товару)
     *
     * Вместо создания задачи для каждой модификации, создаём задачи для каждого товара.
     * Обработчик загрузит все модификации товара одним запросом (с фильтром product.id=xxx)
     *
     * @param MoySkladService $moysklad
     * @param string $token Access token главного аккаунта
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $accountId UUID дочернего аккаунта
     * @return int Количество созданных задач
     */
    private function createBatchVariantTasks(
        MoySkladService $moysklad,
        string $token,
        string $mainAccountId,
        string $accountId
    ): int {
        $tasksCreated = 0;
        $offset = 0;
        $limit = 1000; // Variants не требуют expand для фильтров, можем загружать 1000
        $totalVariants = 0;
        $productIds = []; // Собираем уникальные product IDs

        Log::info('Starting batch variant sync (collecting parent products)');

        // Шаг 1: Собрать уникальные product IDs из всех variants
        do {
            $params = [
                'limit' => $limit,
                'offset' => $offset
            ];

            $response = $moysklad->setAccessToken($token)
                ->get('/entity/variant', $params);

            $variants = $response['data']['rows'] ?? [];
            $pageCount = count($variants);
            $totalVariants += $pageCount;

            foreach ($variants as $variant) {
                // Извлечь product ID из meta.href
                if (isset($variant['product']['meta']['href'])) {
                    $productId = $this->extractEntityId($variant['product']['meta']['href']);
                    if ($productId && !in_array($productId, $productIds)) {
                        $productIds[] = $productId;
                    }
                }
            }

            Log::debug("Collected product IDs from variant page", [
                'offset' => $offset,
                'page_size' => $pageCount,
                'unique_products_so_far' => count($productIds)
            ]);

            $offset += $limit;

        } while ($pageCount === $limit);

        Log::info('Finished collecting parent products', [
            'total_variants' => $totalVariants,
            'unique_products' => count($productIds)
        ]);

        // Шаг 2: Создать batch задачи для каждого product (с типом 'product_variants')
        foreach ($productIds as $productId) {
            SyncQueue::create([
                'account_id' => $accountId,
                'entity_type' => 'product_variants', // Новый тип: пакетная синхронизация модификаций
                'entity_id' => $productId, // ID товара-родителя
                'operation' => 'batch_sync',
                'priority' => 10, // Высокий приоритет для ручной синхронизации
                'scheduled_at' => now(),
                'status' => 'pending',
                'attempts' => 0,
                'payload' => [
                    'main_account_id' => $mainAccountId
                ]
            ]);

            $tasksCreated++;
        }

        Log::info('Batch variant tasks created', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $accountId,
            'total_variants' => $totalVariants,
            'batch_tasks_created' => $tasksCreated,
            'variants_per_task_avg' => $totalVariants > 0 ? round($totalVariants / max($tasksCreated, 1), 2) : 0
        ]);

        return $tasksCreated;
    }

    /**
     * Создать пакетные задачи синхронизации товаров (по 100 товаров)
     *
     * Загружает товары постранично с expand и сохраняет preloaded данные в payload.
     * Batch POST выполняется в ProcessSyncQueueJob::processBatchProductSync().
     *
     * Применяет фильтры через МойСклад API (если возможно) или client-side.
     *
     * @param MoySkladService $moysklad
     * @param string $token Access token главного аккаунта
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $accountId UUID дочернего аккаунта
     * @return int Количество созданных задач
     */
    private function createBatchProductTasks(
        MoySkladService $moysklad,
        string $token,
        string $mainAccountId,
        string $accountId
    ): int {
        // Получить настройки синхронизации
        $syncSettings = SyncSetting::where('account_id', $accountId)->first();

        // Построить API фильтр
        $filterService = app(ProductFilterService::class);
        $apiFilterString = null;
        $needsClientFilter = false;

        if ($syncSettings && $syncSettings->product_filters_enabled && $syncSettings->product_filters) {
            $apiFilterString = $filterService->buildApiFilter(
                $syncSettings->product_filters,
                $mainAccountId
            );

            // Если API фильтр не построен (OR логика, not_in, и т.д.) → применять client-side
            if ($apiFilterString === null) {
                $needsClientFilter = true;
            }
        }

        $tasksCreated = 0;
        $offset = 0;
        $limit = 100; // Batch size: 100 products per task
        $totalLoaded = 0;
        $totalFilteredClient = 0;

        Log::info('Starting batch product sync', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $accountId,
            'api_filter_enabled' => $apiFilterString !== null,
            'client_filter_enabled' => $needsClientFilter,
            'api_filter_preview' => $apiFilterString ? substr($apiFilterString, 0, 200) . '...' : null
        ]);

        do {
            // Загрузить страницу товаров с expand
            $params = [
                'limit' => $limit,
                'offset' => $offset,
                'expand' => 'attributes,productFolder,uom,country,packs.uom,salePrices'
            ];

            // Добавить API фильтр если построен
            if ($apiFilterString) {
                $params['filter'] = $apiFilterString;
            }

            $response = $moysklad->setAccessToken($token)
                ->get('/entity/product', $params);

            $products = $response['data']['rows'] ?? [];
            $pageCount = count($products);
            $totalLoaded += $pageCount;

            // Применить client-side фильтр если нужно
            if ($needsClientFilter && !empty($products) && $syncSettings) {
                $filteredProducts = [];
                foreach ($products as $product) {
                    if ($filterService->passes($product, $syncSettings->product_filters)) {
                        $filteredProducts[] = $product;
                    } else {
                        $totalFilteredClient++;
                    }
                }
                $products = $filteredProducts;
            }

            // Создать batch задачу если есть товары
            if (!empty($products)) {
                SyncQueue::create([
                    'account_id' => $accountId,
                    'entity_type' => 'batch_products',
                    'entity_id' => null, // Not used for batch
                    'operation' => 'batch_sync',
                    'priority' => 10, // High priority (manual sync)
                    'scheduled_at' => now(),
                    'status' => 'pending',
                    'attempts' => 0,
                    'payload' => [
                        'main_account_id' => $mainAccountId,
                        'products' => $products // Preloaded data
                    ]
                ]);

                $tasksCreated++;
            }

            Log::debug("Loaded product batch", [
                'offset' => $offset,
                'page_size' => $pageCount,
                'filtered_client' => $totalFilteredClient,
                'in_batch' => count($products),
                'batch_tasks_created' => $tasksCreated
            ]);

            $offset += $limit;

        } while ($pageCount === $limit);

        Log::info('Batch product tasks created', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $accountId,
            'total_loaded' => $totalLoaded,
            'total_filtered_client' => $totalFilteredClient,
            'total_in_batches' => $totalLoaded - $totalFilteredClient,
            'batch_tasks_created' => $tasksCreated,
            'products_per_task_avg' => $totalLoaded > 0 ? round(($totalLoaded - $totalFilteredClient) / max($tasksCreated, 1), 2) : 0,
            'api_filter_used' => $apiFilterString !== null
        ]);

        return $tasksCreated;
    }

    /**
     * Создать пакетные задачи синхронизации услуг (по 100 услуг)
     *
     * Загружает услуги постранично с expand и сохраняет preloaded данные в payload.
     * Batch POST выполняется в ProcessSyncQueueJob::processBatchServiceSync().
     *
     * @param MoySkladService $moysklad
     * @param string $token Access token главного аккаунта
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $accountId UUID дочернего аккаунта
     * @return int Количество созданных задач
     */
    private function createBatchServiceTasks(
        MoySkladService $moysklad,
        string $token,
        string $mainAccountId,
        string $accountId
    ): int {
        $tasksCreated = 0;
        $offset = 0;
        $limit = 100; // Batch size: 100 services per task
        $totalServices = 0;

        Log::info('Starting batch service sync (loading in batches of 100)');

        do {
            // Загрузить страницу услуг с expand
            $params = [
                'limit' => $limit,
                'offset' => $offset,
                'expand' => 'attributes,uom,salePrices'
            ];

            $response = $moysklad->setAccessToken($token)
                ->get('/entity/service', $params);

            $services = $response['data']['rows'] ?? [];
            $pageCount = count($services);
            $totalServices += $pageCount;

            // Получить настройки и применить фильтры
            $syncSettings = SyncSetting::where('account_id', $accountId)->first();
            $totalFilteredClient = 0;

            // Применить фильтры (общие product_filters)
            if ($syncSettings && $syncSettings->product_filters_enabled && $syncSettings->product_filters) {
                $filterService = app(ProductFilterService::class);
                $filteredServices = [];

                foreach ($services as $service) {
                    if ($filterService->passes($service, $syncSettings->product_filters)) {
                        $filteredServices[] = $service;
                    } else {
                        $totalFilteredClient++;
                    }
                }

                $services = $filteredServices;

                Log::info("Services filtered", [
                    'before' => $pageCount,
                    'after' => count($services),
                    'filtered_out' => $totalFilteredClient
                ]);
            }

            if (!empty($services)) {
                // Создать batch задачу для этой страницы
                SyncQueue::create([
                    'account_id' => $accountId,
                    'entity_type' => 'batch_services',
                    'entity_id' => null, // Not used for batch
                    'operation' => 'batch_sync',
                    'priority' => 10, // High priority (manual sync)
                    'scheduled_at' => now(),
                    'status' => 'pending',
                    'attempts' => 0,
                    'payload' => [
                        'main_account_id' => $mainAccountId,
                        'services' => $services // Preloaded data
                    ]
                ]);

                $tasksCreated++;
            }

            Log::debug("Loaded service batch", [
                'offset' => $offset,
                'page_size' => $pageCount,
                'batch_tasks_created' => $tasksCreated
            ]);

            $offset += $limit;

        } while ($pageCount === $limit);

        Log::info('Batch service tasks created', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $accountId,
            'total_services' => $totalServices,
            'batch_tasks_created' => $tasksCreated,
            'services_per_task_avg' => $totalServices > 0 ? round($totalServices / max($tasksCreated, 1), 2) : 0
        ]);

        return $tasksCreated;
    }

    /**
     * Извлечь UUID сущности из МойСклад href
     *
     * @param string $href URL вида https://api.moysklad.ru/api/remap/1.2/entity/product/UUID
     * @return string|null UUID или null если не удалось извлечь
     */
    private function extractEntityId(string $href): ?string
    {
        if (preg_match('/\/([a-f0-9-]{36})$/', $href, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
