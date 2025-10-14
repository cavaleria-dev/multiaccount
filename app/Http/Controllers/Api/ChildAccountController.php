<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\SyncSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        Log::info('ChildAccountController: Getting child accounts list', [
            'main_account_id' => $mainAccountId
        ]);

        // Получить все дочерние аккаунты
        $childAccounts = DB::table('child_accounts')
            ->join('accounts', 'child_accounts.child_account_id', '=', 'accounts.account_id')
            ->leftJoin('sync_settings', 'accounts.account_id', '=', 'sync_settings.account_id')
            ->where('child_accounts.parent_account_id', $mainAccountId)
            ->select(
                'accounts.id',
                'accounts.account_id',
                'accounts.account_name',
                'accounts.status',
                'accounts.created_at',
                'child_accounts.connected_at',
                'sync_settings.sync_enabled'
            )
            ->orderBy('child_accounts.connected_at', 'desc')
            ->get();

        Log::info('ChildAccountController: Found child accounts', [
            'count' => $childAccounts->count(),
            'accounts' => $childAccounts->map(fn($a) => [
                'account_id' => $a->account_id,
                'account_name' => $a->account_name,
                'status' => $a->status
            ])->toArray()
        ]);

        // Добавить статистику синхронизации для каждого аккаунта отдельным запросом
        foreach ($childAccounts as $account) {
            $stats = DB::table('sync_statistics')
                ->where('account_id', $account->account_id)
                ->orderBy('created_at', 'desc')
                ->first();

            $account->last_sync_at = $stats->last_sync_at ?? null;
            $account->products_synced = $stats->products_synced ?? 0;
            $account->orders_synced = $stats->orders_synced ?? 0;
        }

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
            'account_name' => 'required|string|max:255',
        ]);

        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Найти аккаунт по названию
        $childAccount = Account::where('account_name', $request->account_name)
            ->where('status', 'activated')
            ->first();

        if (!$childAccount) {
            return response()->json([
                'error' => 'Аккаунт с таким названием не найден или приложение не установлено'
            ], 404);
        }

        // Проверить что это не текущий аккаунт
        if ($childAccount->account_id === $mainAccountId) {
            return response()->json([
                'error' => 'Нельзя добавить самого себя в качестве дочернего аккаунта'
            ], 400);
        }

        // Проверить что добавляемый аккаунт не является главным
        if ($childAccount->account_type === 'main') {
            return response()->json([
                'error' => 'Нельзя добавить главный аккаунт в качестве дочернего. Только дочерние аккаунты могут быть подключены к главному.'
            ], 400);
        }

        // Проверить что связь еще не существует
        $existingLink = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $childAccount->account_id)
            ->exists();

        if ($existingLink) {
            return response()->json(['error' => 'Этот аккаунт уже подключен'], 409);
        }

        // Проверить что не подключен к другому главному аккаунту
        $connectedToOther = DB::table('child_accounts')
            ->where('child_account_id', $childAccount->account_id)
            ->first();

        if ($connectedToOther) {
            $parentAccount = Account::where('account_id', $connectedToOther->parent_account_id)->first();
            return response()->json([
                'error' => 'Этот аккаунт уже подключен к другому главному аккаунту: ' . ($parentAccount?->account_name ?? 'Unknown')
            ], 409);
        }

        // Создать связь
        DB::table('child_accounts')->insert([
            'parent_account_id' => $mainAccountId,
            'child_account_id' => $childAccount->account_id,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Создать настройки синхронизации если не существуют
        if (!$childAccount->syncSettings) {
            SyncSetting::create([
                'account_id' => $childAccount->account_id,
                'sync_enabled' => true,
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
