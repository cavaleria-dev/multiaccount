<?php

namespace App\Services;

use App\Models\MoySkladApiLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для логирования API-запросов к МойСклад
 */
class ApiLogService
{
    /**
     * Записать лог API-запроса
     */
    public function logRequest(array $data): ?MoySkladApiLog
    {
        try {
            return MoySkladApiLog::create([
                'account_id' => $data['account_id'] ?? null,
                'direction' => $data['direction'] ?? null,
                'related_account_id' => $data['related_account_id'] ?? null,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'method' => $data['method'],
                'endpoint' => $data['endpoint'],
                'request_params' => $data['request_params'] ?? null,
                'request_payload' => $data['request_payload'] ?? null,
                'response_status' => $data['response_status'],
                'response_body' => $data['response_body'] ?? null,
                'error_message' => $data['error_message'] ?? null,
                'rate_limit_info' => $data['rate_limit_info'] ?? null,
                'duration_ms' => $data['duration_ms'] ?? null,
            ]);
        } catch (\Exception $e) {
            // Не допускаем, чтобы ошибка логирования сломала основной процесс
            Log::error('Failed to log API request', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return null;
        }
    }

    /**
     * Получить логи с ошибками (статус >= 400)
     */
    public function getErrorLogs(array $filters = [], int $perPage = 50)
    {
        $query = MoySkladApiLog::errors()
            ->with(['account', 'relatedAccount'])
            ->orderBy('created_at', 'desc');

        $query = $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Получить все логи с фильтрами
     */
    public function getLogs(array $filters = [], int $perPage = 50)
    {
        $query = MoySkladApiLog::with(['account', 'relatedAccount'])
            ->orderBy('created_at', 'desc');

        $query = $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Получить лог по ID
     */
    public function getLogById(int $id): ?MoySkladApiLog
    {
        return MoySkladApiLog::with(['account', 'relatedAccount'])->find($id);
    }

    /**
     * Получить логи конкретного аккаунта
     */
    public function getLogsByAccount(string $accountId, array $filters = [], int $perPage = 50)
    {
        $query = MoySkladApiLog::forAccount($accountId)
            ->with(['relatedAccount'])
            ->orderBy('created_at', 'desc');

        $query = $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Получить статистику ошибок
     */
    public function getStatistics(array $filters = []): array
    {
        $query = MoySkladApiLog::query();

        // Применить фильтры по датам, если есть
        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        $totalRequests = $query->count();
        $errorRequests = (clone $query)->where('response_status', '>=', 400)->count();
        $rateLimitErrors = (clone $query)->where('response_status', 429)->count();

        // Средняя длительность запросов
        $avgDuration = (clone $query)->whereNotNull('duration_ms')->avg('duration_ms');

        // Топ аккаунтов с ошибками
        $topAccountsWithErrors = (clone $query)
            ->select('account_id', DB::raw('COUNT(*) as error_count'))
            ->where('response_status', '>=', 400)
            ->groupBy('account_id')
            ->orderBy('error_count', 'desc')
            ->limit(10)
            ->with('account')
            ->get();

        // Распределение по типам ошибок
        $errorsByStatus = (clone $query)
            ->select('response_status', DB::raw('COUNT(*) as count'))
            ->where('response_status', '>=', 400)
            ->groupBy('response_status')
            ->orderBy('count', 'desc')
            ->get();

        // Распределение по типам сущностей
        $errorsByEntityType = (clone $query)
            ->select('entity_type', DB::raw('COUNT(*) as count'))
            ->where('response_status', '>=', 400)
            ->whereNotNull('entity_type')
            ->groupBy('entity_type')
            ->orderBy('count', 'desc')
            ->get();

        return [
            'total_requests' => $totalRequests,
            'error_requests' => $errorRequests,
            'success_requests' => $totalRequests - $errorRequests,
            'error_rate' => $totalRequests > 0 ? round(($errorRequests / $totalRequests) * 100, 2) : 0,
            'rate_limit_errors' => $rateLimitErrors,
            'avg_duration_ms' => round($avgDuration ?? 0, 2),
            'top_accounts_with_errors' => $topAccountsWithErrors,
            'errors_by_status' => $errorsByStatus,
            'errors_by_entity_type' => $errorsByEntityType,
        ];
    }

    /**
     * Применить фильтры к запросу
     */
    protected function applyFilters($query, array $filters)
    {
        // Фильтр по главной франшизе (parent account)
        if (isset($filters['parent_account_id'])) {
            $parentId = $filters['parent_account_id'];

            // Если также указан child_account_id - фильтруем по обоим (логика "И")
            if (isset($filters['child_account_id'])) {
                $childId = $filters['child_account_id'];

                // Логи между конкретными двумя аккаунтами
                $query->where(function($q) use ($parentId, $childId) {
                    // Запрос от parent к child
                    $q->where(function($subQ) use ($parentId, $childId) {
                        $subQ->where('account_id', $parentId)
                             ->where('related_account_id', $childId);
                    })
                    // ИЛИ запрос от child к parent
                    ->orWhere(function($subQ) use ($parentId, $childId) {
                        $subQ->where('account_id', $childId)
                             ->where('related_account_id', $parentId);
                    });
                });
            } else {
                // Только parent_account_id - все логи с участием этого аккаунта
                $query->where(function($q) use ($parentId) {
                    $q->where('account_id', $parentId)
                      ->orWhere('related_account_id', $parentId);
                });
            }
        }
        // Фильтр только по дочерней франшизе (если parent не указан)
        elseif (isset($filters['child_account_id'])) {
            $childId = $filters['child_account_id'];

            $query->where(function($q) use ($childId) {
                $q->where('account_id', $childId)
                  ->orWhere('related_account_id', $childId);
            });
        }

        if (isset($filters['account_id'])) {
            $query->where('account_id', $filters['account_id']);
        }

        if (isset($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (isset($filters['response_status'])) {
            $query->where('response_status', $filters['response_status']);
        }

        if (isset($filters['status_range'])) {
            // Например: '4xx', '5xx', '429'
            $range = $filters['status_range'];
            if ($range === '4xx') {
                $query->whereBetween('response_status', [400, 499]);
            } elseif ($range === '5xx') {
                $query->whereBetween('response_status', [500, 599]);
            } elseif ($range === '429') {
                $query->where('response_status', 429);
            }
        }

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['errors_only']) && $filters['errors_only']) {
            $query->where('response_status', '>=', 400);
        }

        if (isset($filters['method'])) {
            $query->where('method', $filters['method']);
        }

        if (isset($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        return $query;
    }

    /**
     * Удалить старые логи (старше указанного количества дней)
     */
    public function cleanup(int $daysToKeep = 30): int
    {
        $cutoffDate = now()->subDays($daysToKeep);

        $deleted = MoySkladApiLog::where('created_at', '<', $cutoffDate)->delete();

        Log::info('API logs cleanup completed', [
            'days_to_keep' => $daysToKeep,
            'cutoff_date' => $cutoffDate,
            'deleted_count' => $deleted
        ]);

        return $deleted;
    }
}
