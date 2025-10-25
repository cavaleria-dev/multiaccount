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

        $batchLoader = app(\App\Services\BatchEntityLoader::class);

        $tasksCreated = 0;

        try {
            // ПРЕ-КЕШ ЗАВИСИМОСТЕЙ (один раз для всех типов сущностей)
            $cacheService = app(\App\Services\DependencyCacheService::class);
            $cacheService->cacheAll($mainAccountId, $accountId, $syncSettings);

            Log::info('Dependencies pre-cached for batch sync');

            // УНИФИЦИРОВАННАЯ СИНХРОНИЗАЦИЯ через /entity/assortment
            // Определить какие типы сущностей нужно синхронизировать
            $enabledTypes = [];
            if ($syncSettings->sync_products) {
                $enabledTypes[] = 'product';
            }
            if ($syncSettings->sync_services ?? false) {
                $enabledTypes[] = 'service';
            }
            if ($syncSettings->sync_bundles) {
                $enabledTypes[] = 'bundle';
            }
            if ($syncSettings->sync_variants) {
                $enabledTypes[] = 'variant';
            }

            // Загрузить все включенные типы одним запросом через assortment
            if (!empty($enabledTypes)) {
                $tasksCreated += $batchLoader->loadAndCreateAssortmentBatchTasks(
                    $enabledTypes,
                    $mainAccountId,
                    $accountId,
                    $mainAccount->access_token,
                    $syncSettings
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
}
