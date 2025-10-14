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
            // Синхронизировать товары (product)
            if ($syncSettings->sync_products) {
                $products = $this->fetchAllProducts($moysklad, $mainAccount->access_token);
                $tasksCreated += $this->createSyncTasks($products, 'product', $accountId, $syncSettings, $filterService);
            }

            // Синхронизировать комплекты (bundle)
            if ($syncSettings->sync_bundles) {
                $bundles = $this->fetchAllBundles($moysklad, $mainAccount->access_token);
                $tasksCreated += $this->createSyncTasks($bundles, 'bundle', $accountId, $syncSettings, $filterService);
            }

            // Синхронизировать услуги (service)
            if ($syncSettings->sync_services ?? false) {
                $services = $this->fetchAllServices($moysklad, $mainAccount->access_token);
                $tasksCreated += $this->createSyncTasks($services, 'service', $accountId, $syncSettings, $filterService);
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
     * Получить все товары из аккаунта
     */
    private function fetchAllProducts(MoySkladService $moysklad, string $token): array
    {
        $allProducts = [];
        $offset = 0;
        $limit = 1000;

        do {
            $response = $moysklad->request($token, 'GET', '/entity/product', [
                'limit' => $limit,
                'offset' => $offset
            ]);

            $products = $response['rows'] ?? [];
            $allProducts = array_merge($allProducts, $products);

            $offset += $limit;
        } while (count($products) === $limit);

        Log::info('Products fetched', ['count' => count($allProducts)]);

        return $allProducts;
    }

    /**
     * Получить все комплекты из аккаунта
     */
    private function fetchAllBundles(MoySkladService $moysklad, string $token): array
    {
        $allBundles = [];
        $offset = 0;
        $limit = 1000;

        do {
            $response = $moysklad->request($token, 'GET', '/entity/bundle', [
                'limit' => $limit,
                'offset' => $offset
            ]);

            $bundles = $response['rows'] ?? [];
            $allBundles = array_merge($allBundles, $bundles);

            $offset += $limit;
        } while (count($bundles) === $limit);

        Log::info('Bundles fetched', ['count' => count($allBundles)]);

        return $allBundles;
    }

    /**
     * Получить все услуги из аккаунта
     */
    private function fetchAllServices(MoySkladService $moysklad, string $token): array
    {
        $allServices = [];
        $offset = 0;
        $limit = 1000;

        do {
            $response = $moysklad->request($token, 'GET', '/entity/service', [
                'limit' => $limit,
                'offset' => $offset
            ]);

            $services = $response['rows'] ?? [];
            $allServices = array_merge($allServices, $services);

            $offset += $limit;
        } while (count($services) === $limit);

        Log::info('Services fetched', ['count' => count($allServices)]);

        return $allServices;
    }

    /**
     * Создать задачи синхронизации для сущностей
     */
    private function createSyncTasks(
        array $entities,
        string $entityType,
        string $accountId,
        SyncSetting $syncSettings,
        ProductFilterService $filterService
    ): int {
        $tasksCreated = 0;

        foreach ($entities as $entity) {
            // Применить фильтры (только для товаров, комплектов)
            if (in_array($entityType, ['product', 'bundle'])) {
                if ($syncSettings->product_filters_enabled && $syncSettings->product_filters) {
                    if (!$filterService->passes($entity, $syncSettings->product_filters)) {
                        continue; // Пропустить
                    }
                }
            }

            // Создать задачу
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

        Log::info('Sync tasks created', [
            'entity_type' => $entityType,
            'count' => $tasksCreated
        ]);

        return $tasksCreated;
    }
}
