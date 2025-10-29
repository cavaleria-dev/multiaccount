<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QueueMemoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MemoryLogsController extends Controller
{
    /**
     * Показать список логов памяти с фильтрами
     */
    public function index(Request $request)
    {
        try {
            $query = QueueMemoryLog::query();

            // Фильтры по датам
            if ($request->filled('start_date')) {
                $query->where('logged_at', '>=', $request->input('start_date') . ' 00:00:00');
            }

            if ($request->filled('end_date')) {
                $query->where('logged_at', '<=', $request->input('end_date') . ' 23:59:59');
            }

            // Фильтр по job_id
            if ($request->filled('job_id')) {
                $query->where('job_id', 'like', '%' . $request->input('job_id') . '%');
            }

            // Фильтр по превышению памяти
            if ($request->boolean('high_memory_only')) {
                $query->where('memory_peak_mb', '>=', 300.0);
            }

            $logs = $query->orderBy('logged_at', 'desc')->paginate(50);

            // Статистика
            $stats = [
                'avg_memory' => round(QueueMemoryLog::avg('memory_current_mb'), 2),
                'max_memory' => round(QueueMemoryLog::max('memory_peak_mb'), 2),
                'total_logs' => QueueMemoryLog::count(),
                'critical_count' => QueueMemoryLog::where('memory_peak_mb', '>=', 400)->count(),
                'warning_count' => QueueMemoryLog::whereBetween('memory_peak_mb', [300, 400])->count(),
            ];

            return view('admin.memory.index', [
                'logs' => $logs,
                'stats' => $stats,
                'filters' => $request->only(['start_date', 'end_date', 'job_id', 'high_memory_only']),
            ]);

        } catch (\Exception $e) {
            Log::error('Error loading memory logs', [
                'error' => $e->getMessage()
            ]);

            return view('admin.memory.index', [
                'logs' => null,
                'error' => 'Ошибка загрузки логов памяти: ' . $e->getMessage(),
                'stats' => null,
                'filters' => [],
            ]);
        }
    }

    /**
     * Показать детали конкретного job (все логи для job_id)
     */
    public function show(Request $request, string $jobId)
    {
        try {
            $logs = QueueMemoryLog::where('job_id', $jobId)
                ->orderBy('batch_index')
                ->get();

            if ($logs->isEmpty()) {
                return redirect()->route('admin.memory.index')
                    ->with('error', 'Логи для job_id не найдены');
            }

            // Вычислить метрики для этого job
            $stats = [
                'total_tasks' => $logs->where('batch_index', 0)->first()->task_count ?? 0,
                'avg_memory' => round($logs->avg('memory_current_mb'), 2),
                'max_memory' => round($logs->max('memory_peak_mb'), 2),
                'min_memory' => round($logs->min('memory_current_mb'), 2),
                'checkpoints' => $logs->where('batch_index', '>', 0)->where('batch_index', '<', 0)->count(),
                'started_at' => $logs->min('logged_at'),
                'completed_at' => $logs->max('logged_at'),
            ];

            return view('admin.memory.show', [
                'logs' => $logs,
                'jobId' => $jobId,
                'stats' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Error loading memory log details', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('admin.memory.index')
                ->with('error' => 'Ошибка загрузки деталей: ' . $e->getMessage());
        }
    }

    /**
     * API endpoint для графиков (возвращает JSON для Chart.js)
     */
    public function chart(Request $request)
    {
        try {
            $period = $request->input('period', '24h'); // 24h, 7d, 30d

            // Определить начало периода
            $startDate = match($period) {
                '24h' => now()->subHours(24),
                '7d' => now()->subDays(7),
                '30d' => now()->subDays(30),
                default => now()->subHours(24),
            };

            $data = QueueMemoryLog::where('logged_at', '>=', $startDate)
                ->orderBy('logged_at')
                ->get(['logged_at', 'memory_current_mb', 'memory_peak_mb'])
                ->groupBy(function($log) use ($period) {
                    // Группировка по времени в зависимости от периода
                    if ($period === '24h') {
                        return $log->logged_at->format('Y-m-d H:00'); // По часам
                    } elseif ($period === '7d') {
                        return $log->logged_at->format('Y-m-d H:00'); // По часам
                    } else {
                        return $log->logged_at->format('Y-m-d'); // По дням
                    }
                })
                ->map(function($group) {
                    return [
                        'timestamp' => $group->first()->logged_at->format('Y-m-d H:i'),
                        'avg_memory' => round($group->avg('memory_current_mb'), 2),
                        'max_memory' => round($group->max('memory_peak_mb'), 2),
                        'count' => $group->count(),
                    ];
                })
                ->values();

            return response()->json($data);

        } catch (\Exception $e) {
            Log::error('Error generating memory chart', [
                'period' => $request->input('period'),
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to generate chart'], 500);
        }
    }
}
