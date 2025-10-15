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
            'target_objects_meta' => 'nullable|array',
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
                'target_objects_meta',
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

            $newPriceTypeName = $request->input('name');

            // Получить все существующие типы цен
            $existingPriceTypesResponse = $moysklad
                ->setAccessToken($childAccount->access_token)
                ->get('context/companysettings/pricetype');

            $existingPriceTypes = $existingPriceTypesResponse['data'];

            Log::info('Fetched existing price types', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'existing_count' => count($existingPriceTypes),
                'new_name' => $newPriceTypeName
            ]);

            // Проверить лимит (максимум 100 типов цен)
            if (count($existingPriceTypes) >= 100) {
                Log::warning('Price type limit reached', [
                    'main_account_id' => $mainAccountId,
                    'child_account_id' => $accountId,
                    'count' => count($existingPriceTypes)
                ]);
                return response()->json([
                    'error' => 'Достигнут лимит типов цен (максимум 100)'
                ], 400);
            }

            // Проверить уникальность имени
            foreach ($existingPriceTypes as $priceType) {
                if (strcasecmp($priceType['name'], $newPriceTypeName) === 0) {
                    Log::warning('Price type name already exists', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $accountId,
                        'name' => $newPriceTypeName
                    ]);
                    return response()->json([
                        'error' => 'Тип цены с таким названием уже существует'
                    ], 409);
                }
            }

            // Добавить новый тип цены в массив
            $existingPriceTypes[] = [
                'name' => $newPriceTypeName
            ];

            // Отправить весь массив обратно (МойСклад требует полный список)
            $result = $moysklad
                ->setAccessToken($childAccount->access_token)
                ->post('context/companysettings/pricetype', $existingPriceTypes);

            $allPriceTypes = $result['data'];

            // Найти только что созданный тип (последний в массиве)
            $createdPriceType = end($allPriceTypes);

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

    /**
     * Получить список организаций из главного аккаунта
     */
    public function getOrganizations(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Получить главный аккаунт через parent_account_id
        $link = DB::table('child_accounts')
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account link not found'], 404);
        }

        try {
            $mainAccount = Account::where('account_id', $link->parent_account_id)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            $response = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->get('entity/organization', ['limit' => 1000]);

            $result = array_map(fn($item) => [
                'id' => $item['id'],
                'name' => $item['name']
            ], $response['data']['rows'] ?? []);

            Log::info('Organizations loaded', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'count' => count($result)
            ]);

            return response()->json(['data' => $result]);

        } catch (\Exception $e) {
            Log::error('Failed to load organizations', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to load organizations: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Получить список складов из главного аккаунта
     */
    public function getStores(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Получить главный аккаунт через parent_account_id
        $link = DB::table('child_accounts')
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account link not found'], 404);
        }

        try {
            $mainAccount = Account::where('account_id', $link->parent_account_id)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            $response = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->get('entity/store', ['limit' => 1000]);

            $result = array_map(fn($item) => [
                'id' => $item['id'],
                'name' => $item['name']
            ], $response['data']['rows'] ?? []);

            Log::info('Stores loaded', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'count' => count($result)
            ]);

            return response()->json(['data' => $result]);

        } catch (\Exception $e) {
            Log::error('Failed to load stores', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to load stores: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Получить список проектов из главного аккаунта
     */
    public function getProjects(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Получить главный аккаунт через parent_account_id
        $link = DB::table('child_accounts')
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account link not found'], 404);
        }

        try {
            $mainAccount = Account::where('account_id', $link->parent_account_id)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            $response = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->get('entity/project', ['limit' => 1000]);

            $result = array_map(fn($item) => [
                'id' => $item['id'],
                'name' => $item['name']
            ], $response['data']['rows'] ?? []);

            Log::info('Projects loaded', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'count' => count($result)
            ]);

            return response()->json(['data' => $result]);

        } catch (\Exception $e) {
            Log::error('Failed to load projects', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to load projects: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Получить список сотрудников из главного аккаунта
     */
    public function getEmployees(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Получить главный аккаунт через parent_account_id
        $link = DB::table('child_accounts')
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account link not found'], 404);
        }

        try {
            $mainAccount = Account::where('account_id', $link->parent_account_id)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            $response = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->get('entity/employee', ['limit' => 1000]);

            $result = array_map(fn($item) => [
                'id' => $item['id'],
                'name' => $item['name']
            ], $response['data']['rows'] ?? []);

            Log::info('Employees loaded', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'count' => count($result)
            ]);

            return response()->json(['data' => $result]);

        } catch (\Exception $e) {
            Log::error('Failed to load employees', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to load employees: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Получить список каналов продаж из главного аккаунта
     */
    public function getSalesChannels(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Получить главный аккаунт через parent_account_id
        $link = DB::table('child_accounts')
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account link not found'], 404);
        }

        try {
            $mainAccount = Account::where('account_id', $link->parent_account_id)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            $response = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->get('entity/saleschannel', ['limit' => 1000]);

            $result = array_map(fn($item) => [
                'id' => $item['id'],
                'name' => $item['name'],
                'type' => $item['type'] ?? null
            ], $response['data']['rows'] ?? []);

            Log::info('Sales channels loaded', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'count' => count($result)
            ]);

            return response()->json(['data' => $result]);

        } catch (\Exception $e) {
            Log::error('Failed to load sales channels', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to load sales channels: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Получить статусы для типа документа из главного аккаунта
     */
    public function getStates(Request $request, $accountId, $entityType)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Валидация: только customerorder и purchaseorder
        $allowedTypes = ['customerorder', 'purchaseorder'];
        if (!in_array($entityType, $allowedTypes)) {
            return response()->json(['error' => 'Invalid entity type. Allowed: ' . implode(', ', $allowedTypes)], 400);
        }

        // Получить главный аккаунт через parent_account_id
        $link = DB::table('child_accounts')
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account link not found'], 404);
        }

        try {
            $mainAccount = Account::where('account_id', $link->parent_account_id)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            // Получить metadata для получения статусов
            $metadata = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->get("entity/{$entityType}/metadata");

            $states = array_map(fn($state) => [
                'id' => $state['id'],
                'name' => $state['name'],
                'color' => $state['color'] ?? null,
                'stateType' => $state['stateType'] ?? 'Regular'
            ], $metadata['data']['states'] ?? []);

            Log::info('States loaded', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'entity_type' => $entityType,
                'count' => count($states)
            ]);

            return response()->json(['data' => $states]);

        } catch (\Exception $e) {
            Log::error('Failed to load states', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'entity_type' => $entityType,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to load states: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Создать новый проект в главном аккаунте
     */
    public function createProject(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Получить главный аккаунт через parent_account_id
        $link = DB::table('child_accounts')
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account link not found'], 404);
        }

        // Валидация
        $request->validate([
            'name' => 'required|string|min:1|max:255',
            'description' => 'nullable|string|max:4096'
        ]);

        try {
            $mainAccount = Account::where('account_id', $link->parent_account_id)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            $data = ['name' => $request->input('name')];

            if ($request->has('description')) {
                $data['description'] = $request->input('description');
            }

            $result = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->post('entity/project', $data);

            Log::info('Project created in main account', [
                'main_account_id' => $link->parent_account_id,
                'child_account_id' => $accountId,
                'project_id' => $result['data']['id'],
                'project_name' => $result['data']['name']
            ]);

            return response()->json([
                'message' => 'Project created successfully',
                'data' => [
                    'id' => $result['data']['id'],
                    'name' => $result['data']['name']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create project', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to create project: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Создать новый склад в главном аккаунте
     */
    public function createStore(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Получить главный аккаунт через parent_account_id
        $link = DB::table('child_accounts')
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account link not found'], 404);
        }

        // Валидация
        $request->validate([
            'name' => 'required|string|min:1|max:255',
            'address' => 'nullable|string|max:4096'
        ]);

        try {
            $mainAccount = Account::where('account_id', $link->parent_account_id)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            $data = ['name' => $request->input('name')];

            if ($request->has('address')) {
                $data['address'] = $request->input('address');
            }

            $result = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->post('entity/store', $data);

            Log::info('Store created in main account', [
                'main_account_id' => $link->parent_account_id,
                'child_account_id' => $accountId,
                'store_id' => $result['data']['id'],
                'store_name' => $result['data']['name']
            ]);

            return response()->json([
                'message' => 'Store created successfully',
                'data' => [
                    'id' => $result['data']['id'],
                    'name' => $result['data']['name']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create store', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to create store: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Создать новый канал продаж в главном аккаунте
     */
    public function createSalesChannel(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Получить главный аккаунт через parent_account_id
        $link = DB::table('child_accounts')
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account link not found'], 404);
        }

        // Валидация
        $request->validate([
            'name' => 'required|string|min:1|max:255',
            'type' => 'required|in:MESSENGER,SOCIAL_NETWORK,MARKETPLACE,ECOMMERCE,CLASSIFIED_ADS,DIRECT_SALES,RETAIL_SALES,OTHER',
            'description' => 'nullable|string|max:4096'
        ]);

        try {
            $mainAccount = Account::where('account_id', $link->parent_account_id)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            $data = [
                'name' => $request->input('name'),
                'type' => $request->input('type', 'OTHER'),
            ];

            if ($request->has('description')) {
                $data['description'] = $request->input('description');
            }

            $result = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->post('entity/saleschannel', $data);

            Log::info('Sales channel created in main account', [
                'main_account_id' => $link->parent_account_id,
                'child_account_id' => $accountId,
                'channel_id' => $result['data']['id'],
                'channel_name' => $result['data']['name']
            ]);

            return response()->json([
                'message' => 'Sales channel created successfully',
                'data' => [
                    'id' => $result['data']['id'],
                    'name' => $result['data']['name']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create sales channel', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to create sales channel: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Создать новый статус документа в главном аккаунте
     */
    public function createState(Request $request, $accountId, $entityType)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Валидация entityType
        $allowedTypes = ['customerorder', 'purchaseorder'];
        if (!in_array($entityType, $allowedTypes)) {
            return response()->json(['error' => 'Invalid entity type. Allowed: ' . implode(', ', $allowedTypes)], 400);
        }

        // Получить главный аккаунт через parent_account_id
        $link = DB::table('child_accounts')
            ->where('child_account_id', $accountId)
            ->first();

        if (!$link) {
            return response()->json(['error' => 'Child account link not found'], 404);
        }

        // Валидация
        $request->validate([
            'name' => 'required|string|min:1|max:255'
        ]);

        try {
            $mainAccount = Account::where('account_id', $link->parent_account_id)->first();

            if (!$mainAccount) {
                return response()->json(['error' => 'Main account not found'], 404);
            }

            $moysklad = app(MoySkladService::class);

            // Получить текущие статусы
            $metadata = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->get("entity/{$entityType}/metadata");

            $states = $metadata['data']['states'] ?? [];

            // Добавить новый статус
            $states[] = [
                'name' => $request->input('name'),
                'stateType' => 'Regular',
                'color' => 15106425 // Синий цвет по умолчанию
            ];

            // Обновить metadata
            $result = $moysklad
                ->setAccessToken($mainAccount->access_token)
                ->put("entity/{$entityType}/metadata", [
                    'states' => $states
                ]);

            $createdState = end($result['data']['states']);

            Log::info('State created in main account', [
                'main_account_id' => $link->parent_account_id,
                'child_account_id' => $accountId,
                'entity_type' => $entityType,
                'state_id' => $createdState['id'],
                'state_name' => $createdState['name']
            ]);

            return response()->json([
                'message' => 'State created successfully',
                'data' => [
                    'id' => $createdState['id'],
                    'name' => $createdState['name']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create state', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $accountId,
                'entity_type' => $entityType,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to create state: ' . $e->getMessage()], 500);
        }
    }
}
