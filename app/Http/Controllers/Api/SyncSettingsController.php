<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
}
