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

            // Подготовить типы цен с добавлением buyPrice
            $mainPrices = array_map(fn($pt) => [
                'id' => $pt['id'],
                'name' => $pt['name']
            ], $mainPriceTypes['data']['priceTypes'] ?? []);

            $childPrices = array_map(fn($pt) => [
                'id' => $pt['id'],
                'name' => $pt['name']
            ], $childPriceTypes['data']['priceTypes'] ?? []);

            // Добавить закупочную цену как специальный тип
            $buyPriceItem = [
                'id' => 'buyPrice',
                'name' => 'Закупочная цена'
            ];

            array_unshift($mainPrices, $buyPriceItem);
            array_unshift($childPrices, $buyPriceItem);

            return response()->json([
                'main' => $mainPrices,
                'child' => $childPrices
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
                Log::error('Main account not found', ['main_account_id' => $mainAccountId]);
                return response()->json(['error' => 'Main account not found'], 404);
            }

            if (!$mainAccount->access_token) {
                Log::error('Main account has no access token', ['main_account_id' => $mainAccountId]);
                return response()->json(['error' => 'Main account has no access token'], 500);
            }

            $moysklad = app(MoySkladService::class);

            Log::info('Fetching attributes metadata', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'endpoint' => 'entity/product/metadata'
            ]);

            // Получить доп.поля product из main аккаунта
            // МойСклад возвращает только meta для attributes, нужен отдельный запрос
            $attributesResponse = $moysklad->setAccessToken($mainAccount->access_token)->get('entity/product/metadata/attributes');

            Log::info('Attributes received', [
                'main_account_id' => $mainAccountId,
                'has_data' => isset($attributesResponse['data']),
                'has_rows' => isset($attributesResponse['data']['rows']),
                'rows_count' => count($attributesResponse['data']['rows'] ?? [])
            ]);

            $result = [];
            $skipped = 0;

            foreach ($attributesResponse['data']['rows'] ?? [] as $index => $attr) {
                // Пропустить элементы без id
                if (!isset($attr['id'])) {
                    $skipped++;
                    Log::warning('Attribute without id skipped', [
                        'index' => $index,
                        'attribute' => json_encode($attr, JSON_UNESCAPED_UNICODE)
                    ]);
                    continue;
                }

                $result[] = [
                    'id' => $attr['id'],
                    'name' => $attr['name'] ?? 'Без имени',
                    'type' => $attr['type'] ?? 'unknown'
                ];
            }

            Log::info('Attributes processed', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'total_count' => count($metadata['data']['attributes'] ?? []),
                'result_count' => count($result),
                'skipped_count' => $skipped
            ]);

            return response()->json(['data' => $result]);

        } catch (\Exception $e) {
            Log::error('Failed to load attributes', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'error' => 'Failed to load attributes: ' . $e->getMessage(),
                'debug' => config('app.debug') ? [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString())
                ] : null
            ], 500);
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

    /**
     * Создать новый тип цены в дочернем аккаунте
     */
    public function createPriceType(Request $request, $accountId)
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

        // Валидация
        $request->validate([
            'name' => 'required|string|min:2|max:255'
        ]);

        try {
            // Получить дочерний аккаунт
            $childAccount = Account::where('account_id', $accountId)->first();

            if (!$childAccount) {
                return response()->json(['error' => 'Child account not found'], 404);
            }

            if (!$childAccount->access_token) {
                return response()->json(['error' => 'Child account has no access token'], 500);
            }

            $moysklad = app(MoySkladService::class);

            // Создать тип цены через МойСклад API
            $priceTypeData = [
                'name' => $request->input('name')
            ];

            $result = $moysklad
                ->setAccessToken($childAccount->access_token)
                ->post('context/companysettings/pricetype', $priceTypeData);

            $createdPriceType = $result['data'];

            Log::info('Price type created in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'price_type_id' => $createdPriceType['id'] ?? null,
                'price_type_name' => $createdPriceType['name'] ?? null
            ]);

            return response()->json([
                'message' => 'Price type created successfully',
                'data' => [
                    'id' => $createdPriceType['id'],
                    'name' => $createdPriceType['name']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create price type', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'name' => $request->input('name'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Проверить на ошибку дубликата от МойСклад API
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'duplicate') || str_contains($errorMessage, 'already exists')) {
                return response()->json([
                    'error' => 'Тип цены с таким названием уже существует'
                ], 409);
            }

            return response()->json([
                'error' => 'Failed to create price type: ' . $errorMessage
            ], 500);
        }
    }
}
