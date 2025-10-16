<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApiLogsController extends Controller
{
    protected ApiLogService $apiLogService;

    public function __construct(ApiLogService $apiLogService)
    {
        $this->apiLogService = $apiLogService;
    }

    /**
     * Показать список логов с фильтрами
     */
    public function index(Request $request)
    {
        try {
            $filters = $this->getFiltersFromRequest($request);

            $logs = $this->apiLogService->getLogs($filters, 50);

            // Получить уникальные значения для фильтров
            $accounts = \App\Models\Account::select('account_id', 'account_name')->get();
            $entityTypes = \App\Models\MoySkladApiLog::select('entity_type')
                ->distinct()
                ->whereNotNull('entity_type')
                ->pluck('entity_type');

            return view('admin.logs.index', [
                'logs' => $logs,
                'filters' => $filters,
                'accounts' => $accounts,
                'entityTypes' => $entityTypes,
            ]);

        } catch (\Exception $e) {
            Log::error('Error loading API logs', [
                'error' => $e->getMessage()
            ]);

            return view('admin.logs.index', [
                'logs' => null,
                'error' => 'Ошибка загрузки логов: ' . $e->getMessage(),
                'filters' => [],
                'accounts' => [],
                'entityTypes' => [],
            ]);
        }
    }

    /**
     * Показать детали конкретного лога
     */
    public function show(Request $request, int $id)
    {
        try {
            $log = $this->apiLogService->getLogById($id);

            if (!$log) {
                return redirect()->route('admin.logs.index')
                    ->with('error', 'Лог не найден');
            }

            return view('admin.logs.show', [
                'log' => $log,
            ]);

        } catch (\Exception $e) {
            Log::error('Error loading API log', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('admin.logs.index')
                ->with('error', 'Ошибка загрузки лога: ' . $e->getMessage());
        }
    }

    /**
     * Показать страницу статистики
     */
    public function statistics(Request $request)
    {
        try {
            $filters = [];

            // Получить период из запроса
            // По умолчанию - за всё время (если логов мало)
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            if ($startDate) {
                $filters['start_date'] = $startDate . ' 00:00:00';
            }
            if ($endDate) {
                $filters['end_date'] = $endDate . ' 23:59:59';
            }

            $statistics = $this->apiLogService->getStatistics($filters);

            return view('admin.logs.statistics', [
                'statistics' => $statistics,
                'startDate' => $startDate,
                'endDate' => $endDate,
            ]);

        } catch (\Exception $e) {
            Log::error('Error loading API statistics', [
                'error' => $e->getMessage()
            ]);

            return view('admin.logs.statistics', [
                'statistics' => null,
                'error' => 'Ошибка загрузки статистики: ' . $e->getMessage(),
                'startDate' => null,
                'endDate' => null,
            ]);
        }
    }

    /**
     * Извлечь фильтры из запроса
     */
    protected function getFiltersFromRequest(Request $request): array
    {
        $filters = [];

        if ($request->filled('account_id')) {
            $filters['account_id'] = $request->input('account_id');
        }

        if ($request->filled('entity_type')) {
            $filters['entity_type'] = $request->input('entity_type');
        }

        if ($request->filled('response_status')) {
            $filters['response_status'] = $request->input('response_status');
        }

        if ($request->filled('status_range')) {
            $filters['status_range'] = $request->input('status_range');
        }

        if ($request->filled('start_date')) {
            $filters['start_date'] = $request->input('start_date');
        }

        if ($request->filled('end_date')) {
            $filters['end_date'] = $request->input('end_date') . ' 23:59:59';
        }

        if ($request->filled('method')) {
            $filters['method'] = $request->input('method');
        }

        if ($request->filled('direction')) {
            $filters['direction'] = $request->input('direction');
        }

        if ($request->boolean('errors_only')) {
            $filters['errors_only'] = true;
        }

        return $filters;
    }
}
