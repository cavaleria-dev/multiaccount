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
                    $accountId,
                    $syncSettings,
                    $filterService
                );
            }

            // Синхронизировать комплекты (bundle) - постранично
            if ($syncSettings->sync_bundles) {
                $tasksCreated += $this->syncEntityType(
                    $moysklad,
                    $mainAccount->access_token,
                    'bundle',
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
     * @param string $accountId
     * @param SyncSetting $syncSettings
     * @param ProductFilterService $filterService
     * @return int Количество созданных задач
     */
    private function syncEntityType(
        MoySkladService $moysklad,
        string $token,
        string $entityType,
        string $accountId,
        SyncSetting $syncSettings,
        ProductFilterService $filterService
    ): int {
        $tasksCreated = 0;
        $offset = 0;
        $limit = 1000; // Максимум для МойСклад без expand
        $totalProcessed = 0;

        Log::info("Starting sync for entity type: {$entityType}");

        do {
            // Загрузить страницу
            $response = $moysklad->request($token, 'GET', "/entity/{$entityType}", [
                'limit' => $limit,
                'offset' => $offset
            ]);

            $entities = $response['rows'] ?? [];
            $pageCount = count($entities);
            $totalProcessed += $pageCount;

            // Создать задачи для текущей страницы
            foreach ($entities as $entity) {
                // Применить фильтры (только для товаров, комплектов)
                if (in_array($entityType, ['product', 'bundle'])) {
                    if ($syncSettings->product_filters_enabled && $syncSettings->product_filters) {
                        if (!$filterService->passes($entity, $syncSettings->product_filters)) {
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
                    'attempts' => 0
                ]);

                $tasksCreated++;
            }

            Log::info("Processed page for {$entityType}", [
                'offset' => $offset,
                'page_size' => $pageCount,
                'tasks_created_on_page' => $tasksCreated,
                'total_processed' => $totalProcessed
            ]);

            $offset += $limit;

            // Продолжать пока получаем полную страницу (1000 записей)
        } while ($pageCount === $limit);

        Log::info("Finished sync for entity type: {$entityType}", [
            'total_entities_processed' => $totalProcessed,
            'total_tasks_created' => $tasksCreated
        ]);

        return $tasksCreated;
    }
}
