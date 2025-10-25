<?php

namespace App\Services;

use App\Models\SyncQueue;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Сервис для мониторинга очередей синхронизации
 */
class QueueMonitorService
{
    /**
     * Получить сводную статистику по очередям
     *
     * @return array
     */
    public function getStatistics(): array
    {
        // Статистика по статусам
        $statusStats = SyncQueue::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Статистика по main аккаунтам
        $mainAccountStats = SyncQueue::select(
                DB::raw("payload->>'main_account_id' as main_account_id"),
                'status',
                DB::raw('count(*) as count')
            )
            ->whereNotNull(DB::raw("payload->>'main_account_id'"))
            ->groupBy(DB::raw("payload->>'main_account_id'"), 'status')
            ->get()
            ->groupBy('main_account_id')
            ->map(function ($group) {
                return $group->pluck('count', 'status')->toArray();
            })
            ->toArray();

        // Получить имена main аккаунтов
        $mainAccountIds = array_keys($mainAccountStats);
        $mainAccounts = Account::whereIn('account_id', $mainAccountIds)
            ->pluck('account_name', 'account_id')
            ->toArray();

        // Добавить имена к статистике
        foreach ($mainAccountStats as $accountId => $stats) {
            $mainAccountStats[$accountId]['name'] = $mainAccounts[$accountId] ?? 'Unknown';
        }

        // Статистика по типам сущностей
        $entityTypeStats = SyncQueue::select('entity_type', 'status', DB::raw('count(*) as count'))
            ->groupBy('entity_type', 'status')
            ->get()
            ->groupBy('entity_type')
            ->map(function ($group) {
                return $group->pluck('count', 'status')->toArray();
            })
            ->toArray();

        // Последние 10 failed задач
        $recentFailed = SyncQueue::where('status', 'failed')
            ->with('account')
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        // Отложенные задачи (scheduled_at > now)
        $scheduledCount = SyncQueue::where('status', 'pending')
            ->where('scheduled_at', '>', now())
            ->count();

        return [
            'total' => array_sum($statusStats),
            'by_status' => $statusStats,
            'by_main_account' => $mainAccountStats,
            'by_entity_type' => $entityTypeStats,
            'recent_failed' => $recentFailed,
            'scheduled_count' => $scheduledCount,
        ];
    }

    /**
     * Получить список задач с фильтрами и пагинацией
     *
     * @param array $filters Фильтры
     * @param int $perPage Количество на странице
     * @return LengthAwarePaginator
     */
    public function getTasksWithFilters(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = SyncQueue::query()->with('account');

        // Фильтр по main account
        if (!empty($filters['main_account_id'])) {
            $query->whereRaw("payload->>'main_account_id' = ?", [$filters['main_account_id']]);
        }

        // Фильтр по child account
        if (!empty($filters['account_id'])) {
            $query->where('account_id', $filters['account_id']);
        }

        // Фильтр по статусу
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Фильтр по типу сущности
        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        // Фильтр по операции
        if (!empty($filters['operation'])) {
            $query->where('operation', $filters['operation']);
        }

        // Фильтр по приоритету
        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        // Фильтр: только отложенные
        if (!empty($filters['scheduled_only'])) {
            $query->where('status', 'pending')
                  ->where('scheduled_at', '>', now());
        }

        // Фильтр: только с ошибками
        if (!empty($filters['errors_only'])) {
            $query->whereNotNull('error');
        }

        // Фильтр по дате (от)
        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        // Фильтр по дате (до)
        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        // Сортировка
        $sortBy = $filters['sort_by'] ?? 'priority';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        if ($sortBy === 'priority') {
            $query->orderBy('priority', 'desc')
                  ->orderBy('created_at', 'asc');
        } elseif ($sortBy === 'created_at') {
            $query->orderBy('created_at', $sortOrder);
        } elseif ($sortBy === 'updated_at') {
            $query->orderBy('updated_at', $sortOrder);
        }

        return $query->paginate($perPage);
    }

    /**
     * Получить детали задачи по ID
     *
     * @param int $id
     * @return SyncQueue|null
     */
    public function getTaskById(int $id): ?SyncQueue
    {
        return SyncQueue::with('account')->find($id);
    }

    /**
     * Перезапустить failed задачу
     *
     * @param int $id
     * @return SyncQueue|null Новая созданная задача или null если ошибка
     */
    public function retryTask(int $id): ?SyncQueue
    {
        $task = SyncQueue::find($id);

        if (!$task || $task->status !== 'failed') {
            return null;
        }

        // Создать новую задачу с теми же параметрами
        $newTask = SyncQueue::create([
            'account_id' => $task->account_id,
            'entity_type' => $task->entity_type,
            'entity_id' => $task->entity_id,
            'operation' => $task->operation,
            'priority' => $task->priority,
            'status' => 'pending',
            'payload' => array_merge($task->payload ?? [], [
                'retry_from_task_id' => $task->id,
                'retried_at' => now()->toDateTimeString()
            ]),
            'attempts' => 0,
            'max_attempts' => $task->max_attempts ?? 3,
            'scheduled_at' => null, // Запустить сразу
        ]);

        return $newTask;
    }

    /**
     * Удалить задачу
     *
     * @param int $id
     * @return bool
     */
    public function deleteTask(int $id): bool
    {
        $task = SyncQueue::find($id);

        if (!$task) {
            return false;
        }

        // Можно удалять только pending и failed задачи
        if (!in_array($task->status, ['pending', 'failed'])) {
            return false;
        }

        return $task->delete();
    }

    /**
     * Получить состояние rate limits для всех main аккаунтов
     *
     * @return array
     */
    public function getRateLimitStatus(): array
    {
        // Получить все main аккаунты (у которых есть задачи в очереди)
        $mainAccountIds = SyncQueue::select(DB::raw("DISTINCT payload->>'main_account_id' as main_account_id"))
            ->whereNotNull(DB::raw("payload->>'main_account_id'"))
            ->pluck('main_account_id')
            ->filter()
            ->toArray();

        // Получить имена аккаунтов
        $accounts = Account::whereIn('account_id', $mainAccountIds)
            ->pluck('account_name', 'account_id')
            ->toArray();

        $statuses = [];

        foreach ($mainAccountIds as $accountId) {
            $cacheKey = "rate_limit:{$accountId}";
            $rateLimitData = Cache::get($cacheKey);

            $status = [
                'account_id' => $accountId,
                'account_name' => $accounts[$accountId] ?? 'Unknown',
                'limit' => $rateLimitData['limit'] ?? null,
                'remaining' => $rateLimitData['remaining'] ?? null,
                'reset' => $rateLimitData['reset'] ?? null,
                'retry_interval' => $rateLimitData['retry_interval'] ?? null,
                'last_updated' => $rateLimitData['last_updated'] ?? null,
            ];

            // Определить статус
            if ($rateLimitData && isset($rateLimitData['remaining'])) {
                $remaining = $rateLimitData['remaining'];
                $limit = $rateLimitData['limit'] ?? 45;

                if ($remaining <= 5) {
                    $status['status'] = 'exhausted';
                    $status['status_text'] = 'Исчерпан';
                } elseif ($remaining <= 15) {
                    $status['status'] = 'warning';
                    $status['status_text'] = 'Внимание';
                } else {
                    $status['status'] = 'ok';
                    $status['status_text'] = 'OK';
                }

                // Время до сброса
                if ($rateLimitData['reset']) {
                    $status['seconds_until_reset'] = max(0, $rateLimitData['reset'] - time());
                }
            } else {
                $status['status'] = 'unknown';
                $status['status_text'] = 'Нет данных';
            }

            $statuses[] = $status;
        }

        // Сортировать по статусу (exhausted первые)
        usort($statuses, function ($a, $b) {
            $order = ['exhausted' => 1, 'warning' => 2, 'ok' => 3, 'unknown' => 4];
            return ($order[$a['status']] ?? 5) - ($order[$b['status']] ?? 5);
        });

        return $statuses;
    }

    /**
     * Получить списки для фильтров
     *
     * @return array
     */
    public function getFilterOptions(): array
    {
        // Main аккаунты
        $mainAccountIds = SyncQueue::select(DB::raw("DISTINCT payload->>'main_account_id' as main_account_id"))
            ->whereNotNull(DB::raw("payload->>'main_account_id'"))
            ->pluck('main_account_id')
            ->filter()
            ->toArray();

        $mainAccounts = Account::whereIn('account_id', $mainAccountIds)
            ->select('account_id', 'account_name')
            ->orderBy('account_name')
            ->get();

        // Child аккаунты
        $childAccounts = Account::whereIn('account_id', function($query) {
                $query->select('account_id')
                    ->from('sync_queue')
                    ->distinct();
            })
            ->select('account_id', 'account_name')
            ->orderBy('account_name')
            ->get();

        // Типы сущностей
        $entityTypes = SyncQueue::select('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->pluck('entity_type');

        // Операции
        $operations = SyncQueue::select('operation')
            ->distinct()
            ->orderBy('operation')
            ->pluck('operation');

        return [
            'main_accounts' => $mainAccounts,
            'child_accounts' => $childAccounts,
            'entity_types' => $entityTypes,
            'operations' => $operations,
            'statuses' => ['pending', 'processing', 'completed', 'failed'],
            'priorities' => [10 => 'Высокий', 5 => 'Средний', 1 => 'Низкий'],
        ];
    }
}
