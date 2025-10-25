<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\QueueMonitorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class QueueController extends Controller
{
    protected QueueMonitorService $queueMonitorService;

    public function __construct(QueueMonitorService $queueMonitorService)
    {
        $this->queueMonitorService = $queueMonitorService;
    }

    /**
     * Dashboard со сводной статистикой
     */
    public function dashboard()
    {
        try {
            $statistics = $this->queueMonitorService->getStatistics();

            return view('admin.queue.dashboard', [
                'statistics' => $statistics
            ]);

        } catch (\Exception $e) {
            Log::error('Error loading queue dashboard', [
                'error' => $e->getMessage()
            ]);

            return view('admin.queue.dashboard', [
                'statistics' => null,
                'error' => 'Ошибка загрузки статистики: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Список задач с фильтрами
     */
    public function index(Request $request)
    {
        try {
            $filters = $this->getFiltersFromRequest($request);

            $tasks = $this->queueMonitorService->getTasksWithFilters($filters, 50);
            $filterOptions = $this->queueMonitorService->getFilterOptions();

            return view('admin.queue.index', [
                'tasks' => $tasks,
                'filters' => $filters,
                'filterOptions' => $filterOptions
            ]);

        } catch (\Exception $e) {
            Log::error('Error loading queue tasks', [
                'error' => $e->getMessage()
            ]);

            return view('admin.queue.index', [
                'tasks' => null,
                'error' => 'Ошибка загрузки задач: ' . $e->getMessage(),
                'filters' => [],
                'filterOptions' => []
            ]);
        }
    }

    /**
     * Детали конкретной задачи
     */
    public function show(int $id)
    {
        try {
            $task = $this->queueMonitorService->getTaskById($id);

            if (!$task) {
                return redirect()->route('admin.queue.tasks')
                    ->with('error', 'Задача не найдена');
            }

            return view('admin.queue.show', [
                'task' => $task
            ]);

        } catch (\Exception $e) {
            Log::error('Error loading queue task', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('admin.queue.tasks')
                ->with('error', 'Ошибка загрузки задачи: ' . $e->getMessage());
        }
    }

    /**
     * Перезапустить failed задачу
     */
    public function retry(int $id)
    {
        try {
            $newTask = $this->queueMonitorService->retryTask($id);

            if (!$newTask) {
                return redirect()->back()
                    ->with('error', 'Невозможно перезапустить задачу. Проверьте что задача имеет статус "failed".');
            }

            return redirect()->route('admin.queue.tasks.show', $newTask->id)
                ->with('success', "Задача перезапущена. Новая задача ID: {$newTask->id}");

        } catch (\Exception $e) {
            Log::error('Error retrying queue task', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()
                ->with('error', 'Ошибка перезапуска задачи: ' . $e->getMessage());
        }
    }

    /**
     * Удалить задачу
     */
    public function delete(int $id)
    {
        try {
            $success = $this->queueMonitorService->deleteTask($id);

            if (!$success) {
                return redirect()->back()
                    ->with('error', 'Невозможно удалить задачу. Можно удалять только pending и failed задачи.');
            }

            return redirect()->route('admin.queue.tasks')
                ->with('success', 'Задача удалена');

        } catch (\Exception $e) {
            Log::error('Error deleting queue task', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()
                ->with('error', 'Ошибка удаления задачи: ' . $e->getMessage());
        }
    }

    /**
     * Мониторинг rate limits
     */
    public function rateLimits()
    {
        try {
            $statuses = $this->queueMonitorService->getRateLimitStatus();

            return view('admin.queue.rate-limits', [
                'statuses' => $statuses
            ]);

        } catch (\Exception $e) {
            Log::error('Error loading rate limits', [
                'error' => $e->getMessage()
            ]);

            return view('admin.queue.rate-limits', [
                'statuses' => [],
                'error' => 'Ошибка загрузки rate limits: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Извлечь фильтры из запроса
     */
    protected function getFiltersFromRequest(Request $request): array
    {
        $filters = [];

        if ($request->filled('main_account_id')) {
            $filters['main_account_id'] = $request->input('main_account_id');
        }

        if ($request->filled('account_id')) {
            $filters['account_id'] = $request->input('account_id');
        }

        if ($request->filled('status')) {
            $filters['status'] = $request->input('status');
        }

        if ($request->filled('entity_type')) {
            $filters['entity_type'] = $request->input('entity_type');
        }

        if ($request->filled('operation')) {
            $filters['operation'] = $request->input('operation');
        }

        if ($request->filled('priority')) {
            $filters['priority'] = $request->input('priority');
        }

        if ($request->boolean('scheduled_only')) {
            $filters['scheduled_only'] = true;
        }

        if ($request->boolean('errors_only')) {
            $filters['errors_only'] = true;
        }

        if ($request->filled('start_date')) {
            $filters['start_date'] = $request->input('start_date') . ' 00:00:00';
        }

        if ($request->filled('end_date')) {
            $filters['end_date'] = $request->input('end_date') . ' 23:59:59';
        }

        if ($request->filled('sort_by')) {
            $filters['sort_by'] = $request->input('sort_by');
        }

        if ($request->filled('sort_order')) {
            $filters['sort_order'] = $request->input('sort_order');
        }

        return $filters;
    }
}
