<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\SyncSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * API контроллер для управления дочерними аккаунтами
 */
class ChildAccountController extends Controller
{
    /**
     * Получить список всех дочерних аккаунтов главного аккаунта
     */
    public function index(Request $request)
    {
        // Получить текущий аккаунт из контекста
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Получить все дочерние аккаунты
        $childAccounts = DB::table('child_accounts')
            ->join('accounts', 'child_accounts.child_account_id', '=', 'accounts.account_id')
            ->leftJoin('sync_settings', 'accounts.account_id', '=', 'sync_settings.account_id')
            ->leftJoin('sync_statistics', function($join) {
                $join->on('accounts.account_id', '=', 'sync_statistics.account_id')
                     ->whereRaw('sync_statistics.id IN (SELECT MAX(id) FROM sync_statistics GROUP BY account_id)');
            })
            ->where('child_accounts.parent_account_id', $mainAccountId)
            ->select(
                'accounts.id',
                'accounts.account_id',
                'accounts.account_name',
                'accounts.status',
                'accounts.created_at',
                'sync_settings.sync_enabled',
                'sync_statistics.last_sync_at',
                'sync_statistics.products_synced',
                'sync_statistics.orders_synced'
            )
            ->orderBy('accounts.created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $childAccounts
        ]);
    }

    /**
     * Получить информацию о конкретном дочернем аккаунте
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

        $account = Account::with('syncSettings', 'syncStatistics')
            ->where('account_id', $accountId)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Account not found'], 404);
        }

        return response()->json([
            'data' => $account
        ]);
    }

    /**
     * Создать связь с дочерним аккаунтом
     */
    public function store(Request $request)
    {
        $request->validate([
            'child_account_id' => 'required|uuid',
            'counterparty_id' => 'nullable|uuid',
            'supplier_counterparty_id' => 'nullable|uuid',
        ]);

        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Проверить что дочерний аккаунт существует
        $childAccount = Account::where('account_id', $request->child_account_id)->first();
        if (!$childAccount) {
            return response()->json(['error' => 'Child account not found in system'], 404);
        }

        // Проверить что связь еще не существует
        $existingLink = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $request->child_account_id)
            ->exists();

        if ($existingLink) {
            return response()->json(['error' => 'Link already exists'], 409);
        }

        // Создать связь
        DB::table('child_accounts')->insert([
            'parent_account_id' => $mainAccountId,
            'child_account_id' => $request->child_account_id,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Обновить counterparty_id если передан
        if ($request->counterparty_id) {
            $childAccount->update([
                'counterparty_id' => $request->counterparty_id
            ]);
        }

        // Создать настройки синхронизации если не существуют
        if (!$childAccount->syncSettings) {
            SyncSetting::create([
                'account_id' => $request->child_account_id,
                'sync_enabled' => true,
                'supplier_counterparty_id' => $request->supplier_counterparty_id,
            ]);
        }

        return response()->json([
            'message' => 'Child account linked successfully',
            'data' => $childAccount->fresh('syncSettings')
        ], 201);
    }

    /**
     * Обновить настройки дочернего аккаунта
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

        $account = Account::where('account_id', $accountId)->first();
        if (!$account) {
            return response()->json(['error' => 'Account not found'], 404);
        }

        // Обновить название если передано
        if ($request->has('account_name')) {
            $account->update([
                'account_name' => $request->account_name
            ]);
        }

        // Обновить counterparty_id если передан
        if ($request->has('counterparty_id')) {
            $account->update([
                'counterparty_id' => $request->counterparty_id
            ]);
        }

        return response()->json([
            'message' => 'Child account updated successfully',
            'data' => $account->fresh()
        ]);
    }

    /**
     * Удалить связь с дочерним аккаунтом
     */
    public function destroy(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Удалить связь
        $deleted = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $accountId)
            ->delete();

        if (!$deleted) {
            return response()->json(['error' => 'Child account link not found'], 404);
        }

        return response()->json([
            'message' => 'Child account link deleted successfully'
        ]);
    }

    /**
     * Получить список доступных аккаунтов (где установлено приложение)
     */
    public function available(Request $request)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Получить все аккаунты где установлено приложение (статус activated)
        $availableAccounts = Account::where('status', 'activated')
            ->where('account_id', '!=', $mainAccountId) // Исключить текущий аккаунт
            ->select('account_id', 'account_name', 'status', 'created_at')
            ->orderBy('account_name')
            ->get();

        // Получить список уже подключенных аккаунтов к текущему
        $connectedIds = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->pluck('child_account_id')
            ->toArray();

        // Получить список аккаунтов подключенных к другим главным
        $connectedToOthers = DB::table('child_accounts')
            ->where('parent_account_id', '!=', $mainAccountId)
            ->pluck('child_account_id')
            ->toArray();

        // Отметить статус каждого аккаунта
        $availableAccounts = $availableAccounts->map(function($account) use ($connectedIds, $connectedToOthers) {
            if (in_array($account->account_id, $connectedIds)) {
                $account->availability = 'connected';
            } elseif (in_array($account->account_id, $connectedToOthers)) {
                $account->availability = 'connected_to_other';
            } else {
                $account->availability = 'available';
            }
            return $account;
        });

        return response()->json([
            'data' => $availableAccounts
        ]);
    }

    /**
     * Проверить доступность конкретного аккаунта
     */
    public function checkAvailability(Request $request, $accountId)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Проверить что аккаунт существует и приложение установлено
        $account = Account::where('account_id', $accountId)
            ->where('status', 'activated')
            ->first();

        if (!$account) {
            return response()->json([
                'available' => false,
                'reason' => 'app_not_installed',
                'message' => 'Приложение не установлено в этом аккаунте или аккаунт не найден'
            ]);
        }

        // Проверить что не подключен к текущему
        $connectedToCurrent = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $accountId)
            ->exists();

        if ($connectedToCurrent) {
            return response()->json([
                'available' => false,
                'reason' => 'already_connected',
                'message' => 'Этот аккаунт уже подключен к текущему'
            ]);
        }

        // Проверить что не подключен к другому главному
        $connectedToOther = DB::table('child_accounts')
            ->where('child_account_id', $accountId)
            ->first();

        if ($connectedToOther) {
            $parentAccount = Account::where('account_id', $connectedToOther->parent_account_id)->first();
            return response()->json([
                'available' => false,
                'reason' => 'connected_to_other',
                'message' => 'Этот аккаунт уже подключен к другому главному аккаунту',
                'parent_account_name' => $parentAccount?->account_name ?? 'Unknown'
            ]);
        }

        // Аккаунт доступен
        return response()->json([
            'available' => true,
            'account' => $account
        ]);
    }
}
