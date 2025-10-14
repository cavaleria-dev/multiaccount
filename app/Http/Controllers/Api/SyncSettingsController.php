<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\SyncSetting;
use App\Services\MoySkladService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * API контроллер для управления настройками синхронизации
 */
class SyncSettingsController extends Controller
{
    /**
     * Получить настройки синхронизации для дочернего аккаунта
     */
    public function show(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Проверить что это дочерний аккаунт текущего главного
        $link = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account not found'], 404);
        }

        $settings = SyncSetting::where('account_id', $accountId)->first();

        if (!$settings) {
            // Создать настройки по умолчанию
            $settings = SyncSetting::create([
                'account_id' => $accountId,
                'sync_enabled' => false,
            ]);
        }

        return response()->json([
            'data' => $settings
        ]);
    }

    /**
     * Обновить настройки синхронизации для дочернего аккаунта
     */
    public function update(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Проверить что это дочерний аккаунт текущего главного
        $link = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account not found'], 404);
        }

        $request->validate([
            'sync_enabled' => 'sometimes|boolean',
            'sync_products' => 'sometimes|boolean',
            'sync_variants' => 'sometimes|boolean',
            'sync_bundles' => 'sometimes|boolean',
            'sync_images' => 'sometimes|boolean',
            'sync_images_all' => 'sometimes|boolean',
            'sync_prices' => 'sometimes|boolean',
            'sync_customer_orders' => 'sometimes|boolean',
            'sync_retail_demands' => 'sometimes|boolean',
            'sync_purchase_orders' => 'sometimes|boolean',
            'target_organization_id' => 'nullable|uuid',
            'target_store_id' => 'nullable|uuid',
            'target_project_id' => 'nullable|uuid',
            'responsible_employee_id' => 'nullable|uuid',
            'customer_order_state_id' => 'nullable|uuid',
            'customer_order_sales_channel_id' => 'nullable|uuid',
            'retail_demand_state_id' => 'nullable|uuid',
            'retail_demand_sales_channel_id' => 'nullable|uuid',
            'purchase_order_state_id' => 'nullable|uuid',
            'purchase_order_sales_channel_id' => 'nullable|uuid',
            'supplier_counterparty_id' => 'nullable|uuid',
            'product_filters' => 'nullable|array',
            'product_filters_enabled' => 'sometimes|boolean',
        ]);

        $settings = SyncSetting::updateOrCreate(
            ['account_id' => $accountId],
            $request->only([
                'sync_enabled',
                'sync_products',
                'sync_variants',
                'sync_bundles',
                'sync_images',
                'sync_images_all',
                'sync_prices',
                'sync_customer_orders',
                'sync_retail_demands',
                'sync_purchase_orders',
                'target_organization_id',
                'target_store_id',
                'target_project_id',
                'responsible_employee_id',
                'customer_order_state_id',
                'customer_order_sales_channel_id',
                'retail_demand_state_id',
                'retail_demand_sales_channel_id',
                'purchase_order_state_id',
                'purchase_order_sales_channel_id',
                'supplier_counterparty_id',
                'product_filters',
                'product_filters_enabled',
            ])
        );

        return response()->json([
            'message' => 'Settings updated successfully',
            'data' => $settings->fresh()
        ]);
    }

    /**
     * Получить типы цен из main и child аккаунтов
     */
    public function getPriceTypes(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Проверить что это дочерний аккаунт текущего главного
        $link = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account not found'], 404);
        }

        try {
            // Получить аккаунты
            $mainAccount = Account::where('account_id', $mainAccountId)->first();
            $childAccount = Account::where('account_id', $accountId)->first();

            if (!$mainAccount || !$childAccount) {
                return response()->json(['error' => 'Account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            // Получить типы цен из обоих аккаунтов
            $mainPriceTypes = $moysklad->setAccessToken($mainAccount->access_token)->get('context/companysettings');
            $childPriceTypes = $moysklad->setAccessToken($childAccount->access_token)->get('context/companysettings');

            Log::info('Price types loaded', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'main_count' => count($mainPriceTypes['data']['priceTypes'] ?? []),
                'child_count' => count($childPriceTypes['data']['priceTypes'] ?? [])
            ]);

            return response()->json([
                'main' => array_map(fn($pt) => [
                    'id' => $pt['id'],
                    'name' => $pt['name']
                ], $mainPriceTypes['data']['priceTypes'] ?? []),
                'child' => array_map(fn($pt) => [
                    'id' => $pt['id'],
                    'name' => $pt['name']
                ], $childPriceTypes['data']['priceTypes'] ?? [])
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to load price types', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to load price types: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Получить все доп.поля из main аккаунта
     */
    public function getAttributes(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Проверить что это дочерний аккаунт текущего главного
        $link = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account not found'], 404);
        }

        try {
            // Получить main аккаунт
            $mainAccount = Account::where('account_id', $mainAccountId)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            // Получить доп.поля product из main аккаунта
            $metadata = $moysklad->setAccessToken($mainAccount->access_token)->get('entity/product/metadata');

            $result = [];
            foreach ($metadata['data']['attributes'] ?? [] as $attr) {
                $result[] = [
                    'id' => $attr['id'],
                    'name' => $attr['name'],
                    'type' => $attr['type']
                ];
            }

            Log::info('Attributes loaded', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'count' => count($result)
            ]);

            return response()->json(['data' => $result]);

        } catch (\Exception $e) {
            Log::error('Failed to load attributes', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to load attributes: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Получить дерево групп товаров из main аккаунта
     */
    public function getFolders(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Проверить что это дочерний аккаунт текущего главного
        $link = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account not found'], 404);
        }

        try {
            // Получить main аккаунт
            $mainAccount = Account::where('account_id', $mainAccountId)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            // Получить все папки
            $folders = $moysklad->setAccessToken($mainAccount->access_token)->get('entity/productfolder', [
                'limit' => 1000
            ]);

            // Построить дерево
            $folderTree = $this->buildFolderTree($folders['data']['rows'] ?? []);

            Log::info('Folders loaded', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'count' => count($folders['data']['rows'] ?? [])
            ]);

            return response()->json(['data' => $folderTree]);

        } catch (\Exception $e) {
            Log::error('Failed to load folders', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to load folders: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Построить дерево папок из плоского списка
     */
    private function buildFolderTree(array $folders): array
    {
        $tree = [];
        $indexed = [];

        // Индексировать папки
        foreach ($folders as $folder) {
            $parentId = null;

            // Извлечь parent_id из productFolder.meta.href
            if (isset($folder['productFolder']['meta']['href'])) {
                $parts = explode('/', $folder['productFolder']['meta']['href']);
                $parentId = end($parts);
            }

            $indexed[$folder['id']] = [
                'id' => $folder['id'],
                'name' => $folder['name'],
                'pathName' => $folder['pathName'] ?? $folder['name'],
                'parent_id' => $parentId,
                'children' => []
            ];
        }

        // Построить дерево
        foreach ($indexed as $id => $folder) {
            if ($folder['parent_id'] && isset($indexed[$folder['parent_id']])) {
                $indexed[$folder['parent_id']]['children'][] = &$indexed[$id];
            } else {
                $tree[] = &$indexed[$id];
            }
        }

        return $tree;
    }
}
