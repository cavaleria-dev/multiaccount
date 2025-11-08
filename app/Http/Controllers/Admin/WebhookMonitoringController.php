<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookLog;
use App\Models\EntityUpdateLog;
use App\Models\SyncQueue;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Webhook Processing Monitoring Controller
 *
 * Comprehensive monitoring of webhook processing pipeline:
 * - Webhook reception (webhook_logs)
 * - Partial updates (entity_update_logs)
 * - Queue tasks (sync_queue)
 */
class WebhookMonitoringController extends Controller
{
    /**
     * Main monitoring page
     */
    public function index(Request $request)
    {
        // Parse filters
        $filters = $this->parseFilters($request);

        // Get summary metrics
        $summary = $this->getSummaryMetrics($filters);

        // Get webhook logs
        $webhookLogs = $this->getWebhookLogs($filters);

        // Get entity update logs (errors only)
        $updateErrors = $this->getUpdateErrors($filters);

        // Get related queue tasks
        $queueTasks = $this->getQueueTasks($filters);

        // Get accounts for filter dropdown
        $accounts = Account::select('account_id', 'account_name')
            ->orderBy('account_name')
            ->get();

        return view('admin.webhooks.monitoring', [
            'filters' => $filters,
            'summary' => $summary,
            'webhookLogs' => $webhookLogs,
            'updateErrors' => $updateErrors,
            'queueTasks' => $queueTasks,
            'accounts' => $accounts,
        ]);
    }

    /**
     * Parse and validate filters from request
     */
    protected function parseFilters(Request $request): array
    {
        $period = $request->input('period', 'last_24h');

        // Calculate date range
        switch ($period) {
            case 'last_24h':
                $dateFrom = now()->subDay();
                $dateTo = now();
                break;
            case 'last_7d':
                $dateFrom = now()->subDays(7);
                $dateTo = now();
                break;
            case 'last_30d':
                $dateFrom = now()->subDays(30);
                $dateTo = now();
                break;
            case 'custom':
                $dateFrom = $request->input('date_from')
                    ? Carbon::parse($request->input('date_from'))
                    : now()->subDays(7);
                $dateTo = $request->input('date_to')
                    ? Carbon::parse($request->input('date_to'))
                    : now();
                break;
            default:
                $dateFrom = now()->subDay();
                $dateTo = now();
        }

        return [
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'account_id' => $request->input('account_id'),
            'status' => $request->input('status'),
            'entity_type' => $request->input('entity_type'),
            'action' => $request->input('action'),
            'errors_only' => $request->boolean('errors_only'),
        ];
    }

    /**
     * Get summary metrics
     */
    protected function getSummaryMetrics(array $filters): array
    {
        $dateFrom = $filters['date_from'];
        $dateTo = $filters['date_to'];
        $accountId = $filters['account_id'];

        // Total webhooks
        $totalWebhooks = WebhookLog::whereBetween('created_at', [$dateFrom, $dateTo])
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->count();

        // Webhooks by status
        $webhooksByStatus = WebhookLog::whereBetween('created_at', [$dateFrom, $dateTo])
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $completed = $webhooksByStatus['completed'] ?? 0;
        $failed = $webhooksByStatus['failed'] ?? 0;
        $pending = $webhooksByStatus['pending'] ?? 0;
        $processing = $webhooksByStatus['processing'] ?? 0;

        // Success rate
        $successRate = $totalWebhooks > 0
            ? round(($completed / $totalWebhooks) * 100, 1)
            : 0;

        // Average processing time
        $avgProcessingTime = WebhookLog::whereBetween('created_at', [$dateFrom, $dateTo])
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->where('status', 'completed')
            ->whereNotNull('processing_time_ms')
            ->avg('processing_time_ms');

        // Pending queue tasks
        $pendingTasks = SyncQueue::whereBetween('created_at', [$dateFrom, $dateTo])
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->where('status', 'pending')
            ->count();

        // Partial updates stats
        $partialUpdates = EntityUpdateLog::whereBetween('created_at', [$dateFrom, $dateTo])
            ->when($accountId, fn($q) => $q->where('main_account_id', $accountId))
            ->count();

        $partialUpdatesFailed = EntityUpdateLog::whereBetween('created_at', [$dateFrom, $dateTo])
            ->when($accountId, fn($q) => $q->where('main_account_id', $accountId))
            ->where('status', 'failed')
            ->count();

        return [
            'total_webhooks' => $totalWebhooks,
            'completed' => $completed,
            'failed' => $failed,
            'pending' => $pending,
            'processing' => $processing,
            'success_rate' => $successRate,
            'avg_processing_time' => $avgProcessingTime ? round($avgProcessingTime, 0) : null,
            'pending_tasks' => $pendingTasks,
            'partial_updates' => $partialUpdates,
            'partial_updates_failed' => $partialUpdatesFailed,
        ];
    }

    /**
     * Get webhook logs with pagination
     */
    protected function getWebhookLogs(array $filters)
    {
        $query = WebhookLog::with('account:account_id,account_name')
            ->whereBetween('created_at', [$filters['date_from'], $filters['date_to']]);

        // Apply filters
        if ($filters['account_id']) {
            $query->where('account_id', $filters['account_id']);
        }

        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }

        if ($filters['entity_type']) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if ($filters['action']) {
            $query->where('action', $filters['action']);
        }

        if ($filters['errors_only']) {
            $query->where('status', 'failed');
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate(50)
            ->appends($filters);
    }

    /**
     * Get entity update logs (errors only)
     */
    protected function getUpdateErrors(array $filters)
    {
        return EntityUpdateLog::with([
            'mainAccount:account_id,account_name',
            'childAccount:account_id,account_name'
        ])
            ->whereBetween('created_at', [$filters['date_from'], $filters['date_to']])
            ->where('status', 'failed')
            ->when($filters['account_id'], fn($q) => $q->where(function($q2) use ($filters) {
                $q2->where('main_account_id', $filters['account_id'])
                   ->orWhere('child_account_id', $filters['account_id']);
            }))
            ->when($filters['entity_type'], fn($q) => $q->where('entity_type', $filters['entity_type']))
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
    }

    /**
     * Get sync queue tasks
     */
    protected function getQueueTasks(array $filters)
    {
        $query = SyncQueue::whereBetween('created_at', [$filters['date_from'], $filters['date_to']]);

        // Apply filters
        if ($filters['account_id']) {
            $query->where('account_id', $filters['account_id']);
        }

        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }

        if ($filters['entity_type']) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if ($filters['errors_only']) {
            $query->where('status', 'failed');
        }

        return $query->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
    }
}
