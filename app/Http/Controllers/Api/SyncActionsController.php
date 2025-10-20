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
            // Синхронизировать товары (product) - постранично
            if ($syncSettings->sync_products) {
                $tasksCreated += $this->syncEntityType(
                    $moysklad,
                    $mainAccount->access_token,
                    'product',
                    $mainAccountId,
                    $accountId,
                    $syncSettings,
                    $filterService
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

            // Синхронизировать услуги (service) - постранично
            if ($syncSettings->sync_services ?? false) {
                $tasksCreated += $this->syncEntityType(
                    $moysklad,
                    $mainAccount->access_token,
                    'service',
                    $mainAccountId,
                    $accountId,
                    $syncSettings,
                    $filterService
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
