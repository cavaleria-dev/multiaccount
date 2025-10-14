<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для сбора и анализа статистики синхронизации
 */
class SyncStatisticsService
{
    /**
     * Записать статистику синхронизации
     *
     * @param string $parentAccountId UUID родительского аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $type Тип синхронизации ('product' или 'order')
     * @param bool $success Успешность синхронизации
     * @param int $duration Длительность в миллисекундах
     * @return void
     */
    public function recordSync(
        string $parentAccountId,
        string $childAccountId,
        string $type,
        bool $success,
        int $duration
    ): void {
        try {
            $today = now()->toDateString();

            // Получить текущую статистику
            $stats = DB::table('sync_statistics')
                ->where('parent_account_id', $parentAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('date', $today)
                ->first();

            if ($stats) {
                // Обновить существующую запись
                $updates = [
                    'api_calls_count' => $stats->api_calls_count + 1,
                    'last_sync_at' => now(),
                    'updated_at' => now(),
                ];

                if ($type === 'product') {
                    $updates['products_synced'] = $stats->products_synced + ($success ? 1 : 0);
                    $updates['products_failed'] = $stats->products_failed + ($success ? 0 : 1);
                } else {
                    $updates['orders_synced'] = $stats->orders_synced + ($success ? 1 : 0);
                    $updates['orders_failed'] = $stats->orders_failed + ($success ? 0 : 1);
                }

                // Пересчитать среднюю длительность
                $totalDuration = ($stats->sync_duration_avg * $stats->api_calls_count) + $duration;
                $updates['sync_duration_avg'] = (int)($totalDuration / ($stats->api_calls_count + 1));

                DB::table('sync_statistics')
                    ->where('parent_account_id', $parentAccountId)
                    ->where('child_account_id', $childAccountId)
                    ->where('date', $today)
                    ->update($updates);

            } else {
                // Создать новую запись
                DB::table('sync_statistics')->insert([
                    'parent_account_id' => $parentAccountId,
                    'child_account_id' => $childAccountId,
                    'date' => $today,
                    'products_synced' => $type === 'product' && $success ? 1 : 0,
                    'products_failed' => $type === 'product' && !$success ? 1 : 0,
                    'orders_synced' => $type === 'order' && $success ? 1 : 0,
                    'orders_failed' => $type === 'order' && !$success ? 1 : 0,
                    'sync_duration_avg' => $duration,
                    'api_calls_count' => 1,
                    'last_sync_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to record sync statistics', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Получить статистику для родительского аккаунта
     *
     * @param string $parentAccountId UUID родительского аккаунта
     * @param int $days Количество дней (по умолчанию 7)
     * @return array Статистика по дочерним аккаунтам
     */
    public function getStatistics(string $parentAccountId, int $days = 7): array
    {
        $startDate = now()->subDays($days)->toDateString();

        $stats = DB::table('sync_statistics')
            ->join('accounts', 'sync_statistics.child_account_id', '=', 'accounts.account_id')
            ->where('sync_statistics.parent_account_id', $parentAccountId)
            ->where('sync_statistics.date', '>=', $startDate)
            ->select([
                'sync_statistics.*',
                'accounts.account_name',
            ])
            ->orderBy('sync_statistics.date', 'desc')
            ->get()
            ->groupBy('child_account_id');

        return $stats->toArray();
    }

    /**
     * Получить агрегированную статистику
     *
     * @param string $parentAccountId UUID родительского аккаунта
     * @param int $days Количество дней (по умолчанию 30)
     * @return array Агрегированная статистика
     */
    public function getAggregatedStats(string $parentAccountId, int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();

        $stats = DB::table('sync_statistics')
            ->where('parent_account_id', $parentAccountId)
            ->where('date', '>=', $startDate)
            ->select([
                DB::raw('SUM(products_synced) as total_products_synced'),
                DB::raw('SUM(products_failed) as total_products_failed'),
                DB::raw('SUM(orders_synced) as total_orders_synced'),
                DB::raw('SUM(orders_failed) as total_orders_failed'),
                DB::raw('AVG(sync_duration_avg) as avg_duration'),
                DB::raw('SUM(api_calls_count) as total_api_calls'),
            ])
            ->first();

        return [
            'total_products_synced' => $stats->total_products_synced ?? 0,
            'total_products_failed' => $stats->total_products_failed ?? 0,
            'total_orders_synced' => $stats->total_orders_synced ?? 0,
            'total_orders_failed' => $stats->total_orders_failed ?? 0,
            'avg_duration' => round($stats->avg_duration ?? 0, 2),
            'total_api_calls' => $stats->total_api_calls ?? 0,
        ];
    }

    /**
     * Получить статистику по франшизам
     *
     * @param string $parentAccountId UUID родительского аккаунта
     * @param int $days Количество дней
     * @return array Список франшиз со статистикой
     */
    public function getFranchiseStats(string $parentAccountId, int $days = 7): array
    {
        $startDate = now()->subDays($days)->toDateString();

        $stats = DB::table('sync_statistics')
            ->join('accounts', 'sync_statistics.child_account_id', '=', 'accounts.account_id')
            ->where('sync_statistics.parent_account_id', $parentAccountId)
            ->where('sync_statistics.date', '>=', $startDate)
            ->select([
                'sync_statistics.child_account_id',
                'accounts.account_name',
                DB::raw('SUM(products_synced) as products_synced'),
                DB::raw('SUM(products_failed) as products_failed'),
                DB::raw('SUM(orders_synced) as orders_synced'),
                DB::raw('SUM(orders_failed) as orders_failed'),
                DB::raw('AVG(sync_duration_avg) as avg_duration'),
                DB::raw('MAX(last_sync_at) as last_sync_at'),
            ])
            ->groupBy('sync_statistics.child_account_id', 'accounts.account_name')
            ->orderBy('last_sync_at', 'desc')
            ->get();

        return $stats->map(function($stat) {
            return [
                'child_account_id' => $stat->child_account_id,
                'account_name' => $stat->account_name,
                'products_synced' => $stat->products_synced ?? 0,
                'products_failed' => $stat->products_failed ?? 0,
                'orders_synced' => $stat->orders_synced ?? 0,
                'orders_failed' => $stat->orders_failed ?? 0,
                'avg_duration' => round($stat->avg_duration ?? 0, 2),
                'last_sync_at' => $stat->last_sync_at,
            ];
        })->toArray();
    }

    /**
     * Получить тренд синхронизации по дням
     *
     * @param string $parentAccountId UUID родительского аккаунта
     * @param int $days Количество дней
     * @return array Данные для графика
     */
    public function getSyncTrend(string $parentAccountId, int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();

        $stats = DB::table('sync_statistics')
            ->where('parent_account_id', $parentAccountId)
            ->where('date', '>=', $startDate)
            ->select([
                'date',
                DB::raw('SUM(products_synced) as products_synced'),
                DB::raw('SUM(products_failed) as products_failed'),
                DB::raw('SUM(orders_synced) as orders_synced'),
                DB::raw('SUM(orders_failed) as orders_failed'),
            ])
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        return $stats->map(function($stat) {
            return [
                'date' => $stat->date,
                'products_synced' => $stat->products_synced ?? 0,
                'products_failed' => $stat->products_failed ?? 0,
                'orders_synced' => $stat->orders_synced ?? 0,
                'orders_failed' => $stat->orders_failed ?? 0,
            ];
        })->toArray();
    }

    /**
     * Проверить здоровье синхронизации (high failure rate)
     *
     * @param string $parentAccountId UUID родительского аккаунта
     * @param float $threshold Порог процента ошибок (по умолчанию 20%)
     * @return array Проблемные франшизы
     */
    public function checkSyncHealth(string $parentAccountId, float $threshold = 20.0): array
    {
        $stats = DB::table('sync_statistics')
            ->join('accounts', 'sync_statistics.child_account_id', '=', 'accounts.account_id')
            ->where('sync_statistics.parent_account_id', $parentAccountId)
            ->where('sync_statistics.date', '>=', now()->subDays(1)->toDateString())
            ->select([
                'sync_statistics.child_account_id',
                'accounts.account_name',
                DB::raw('SUM(products_synced + orders_synced) as total_synced'),
                DB::raw('SUM(products_failed + orders_failed) as total_failed'),
            ])
            ->groupBy('sync_statistics.child_account_id', 'accounts.account_name')
            ->get();

        $problematic = [];

        foreach ($stats as $stat) {
            $totalOperations = $stat->total_synced + $stat->total_failed;

            if ($totalOperations === 0) {
                continue;
            }

            $failureRate = ($stat->total_failed / $totalOperations) * 100;

            if ($failureRate >= $threshold) {
                $problematic[] = [
                    'child_account_id' => $stat->child_account_id,
                    'account_name' => $stat->account_name,
                    'failure_rate' => round($failureRate, 2),
                    'total_synced' => $stat->total_synced,
                    'total_failed' => $stat->total_failed,
                ];
            }
        }

        if (!empty($problematic)) {
            Log::warning('High failure rate detected', [
                'parent_account_id' => $parentAccountId,
                'problematic_franchises' => $problematic
            ]);
        }

        return $problematic;
    }

    /**
     * Архивировать старую статистику
     *
     * @param int $days Количество дней (по умолчанию 90)
     * @return int Количество удаленных записей
     */
    public function archiveOldStatistics(int $days = 90): int
    {
        $deleted = DB::table('sync_statistics')
            ->where('date', '<', now()->subDays($days)->toDateString())
            ->delete();

        Log::info('Archive old statistics', [
            'days' => $days,
            'deleted_count' => $deleted
        ]);

        return $deleted;
    }
}
