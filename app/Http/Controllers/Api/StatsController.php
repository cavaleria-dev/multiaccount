<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * API контроллер для получения статистики
 */
class StatsController extends Controller
{
    /**
     * Получить статистику для dashboard главного аккаунта
     */
    public function dashboard(Request $request)
    {
        $contextData = $request->get('moysklad_context');
        if (!$contextData || !isset($contextData['accountId'])) {
            return response()->json(['error' => 'Account context not found'], 400);
        }

        $mainAccountId = $contextData['accountId'];

        // Количество дочерних аккаунтов
        $childAccountsCount = DB::table('child_accounts')
            ->where('parent_account_id', $mainAccountId)
            ->count();

        // Количество активных дочерних аккаунтов
        $activeAccountsCount = DB::table('child_accounts')
            ->join('accounts', 'child_accounts.child_account_id', '=', 'accounts.account_id')
            ->where('child_accounts.parent_account_id', $mainAccountId)
            ->where('accounts.status', 'activated')
            ->count();

        // Количество синхронизаций сегодня
        $syncsToday = DB::table('sync_statistics')
            ->join('child_accounts', 'sync_statistics.account_id', '=', 'child_accounts.child_account_id')
            ->where('child_accounts.parent_account_id', $mainAccountId)
            ->whereDate('sync_statistics.last_sync_at', Carbon::today())
            ->count();

        // Общее количество синхронизированных товаров
        $totalProductsSynced = DB::table('entity_mappings')
            ->join('child_accounts', 'entity_mappings.child_account_id', '=', 'child_accounts.child_account_id')
            ->where('child_accounts.parent_account_id', $mainAccountId)
            ->whereIn('entity_mappings.entity_type', ['product', 'variant', 'bundle'])
            ->where('entity_mappings.sync_direction', 'main_to_child')
            ->count();

        // Общее количество синхронизированных заказов
        $totalOrdersSynced = DB::table('entity_mappings')
            ->join('child_accounts', 'entity_mappings.child_account_id', '=', 'child_accounts.child_account_id')
            ->where('child_accounts.parent_account_id', $mainAccountId)
            ->whereIn('entity_mappings.entity_type', ['customerorder', 'retaildemand'])
            ->where('entity_mappings.sync_direction', 'child_to_main')
            ->count();

        // Задачи в очереди
        $queuedTasks = DB::table('sync_queue')
            ->join('child_accounts', 'sync_queue.account_id', '=', 'child_accounts.child_account_id')
            ->where('child_accounts.parent_account_id', $mainAccountId)
            ->where('sync_queue.status', 'pending')
            ->count();

        // Последние ошибки
        $recentErrors = DB::table('sync_queue')
            ->join('child_accounts', 'sync_queue.account_id', '=', 'child_accounts.child_account_id')
            ->where('child_accounts.parent_account_id', $mainAccountId)
            ->where('sync_queue.status', 'failed')
            ->whereDate('sync_queue.updated_at', '>=', Carbon::today()->subDays(7))
            ->count();

        return response()->json([
            'childAccounts' => $childAccountsCount,
            'activeAccounts' => $activeAccountsCount,
            'syncsToday' => $syncsToday,
            'totalProductsSynced' => $totalProductsSynced,
            'totalOrdersSynced' => $totalOrdersSynced,
            'queuedTasks' => $queuedTasks,
            'recentErrors' => $recentErrors,
        ]);
    }

    /**
     * Получить статистику по конкретному дочернему аккаунту
     */
    public function childAccount(Request $request, $accountId)
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

        // Последняя статистика синхронизации
        $lastStats = DB::table('sync_statistics')
            ->where('account_id', $accountId)
            ->orderBy('id', 'desc')
            ->first();

        // Синхронизированные товары
        $productsSynced = DB::table('entity_mappings')
            ->where('child_account_id', $accountId)
            ->whereIn('entity_type', ['product', 'variant', 'bundle'])
            ->where('sync_direction', 'main_to_child')
            ->count();

        // Синхронизированные заказы
        $ordersSynced = DB::table('entity_mappings')
            ->where('child_account_id', $accountId)
            ->whereIn('entity_type', ['customerorder', 'retaildemand'])
            ->where('sync_direction', 'child_to_main')
            ->count();

        // Задачи в очереди
        $queuedTasks = DB::table('sync_queue')
            ->where('account_id', $accountId)
            ->where('status', 'pending')
            ->count();

        // Ошибки за последнюю неделю
        $recentErrors = DB::table('sync_queue')
            ->where('account_id', $accountId)
            ->where('status', 'failed')
            ->whereDate('updated_at', '>=', Carbon::today()->subDays(7))
            ->count();

        return response()->json([
            'lastSyncAt' => $lastStats->last_sync_at ?? null,
            'productsSynced' => $productsSynced,
            'ordersSynced' => $ordersSynced,
            'queuedTasks' => $queuedTasks,
            'recentErrors' => $recentErrors,
            'lastStats' => $lastStats,
        ]);
    }
}
