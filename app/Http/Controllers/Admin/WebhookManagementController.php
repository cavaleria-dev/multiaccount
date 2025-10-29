<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CheckWebhookHealthJob;
use App\Jobs\SetupAccountWebhooksJob;
use App\Models\Account;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Services\Webhook\WebhookHealthService;
use App\Services\Webhook\WebhookProcessorService;
use App\Services\Webhook\WebhookSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin controller для управления вебхуками
 *
 * Endpoints:
 * - GET /admin/webhooks - Список всех вебхуков
 * - GET /admin/webhooks/accounts - Список аккаунтов с статусами вебхуков
 * - GET /admin/webhooks/{accountId} - Вебхуки конкретного аккаунта
 * - POST /admin/webhooks/{accountId}/reinstall - Переустановить вебхуки
 * - POST /admin/webhooks/{accountId}/health-check - Проверить здоровье
 * - GET /admin/webhooks/{accountId}/logs - Логи вебхуков
 * - GET /admin/webhooks/{accountId}/stats - Статистика обработки
 * - POST /admin/webhooks/health-check-all - Проверить все аккаунты
 */
class WebhookManagementController extends Controller
{
    public function __construct(
        protected WebhookSetupService $setupService,
        protected WebhookHealthService $healthService,
        protected WebhookProcessorService $processorService
    ) {}

    /**
     * Получить список всех вебхуков
     *
     * GET /admin/webhooks
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 50);

            $webhooks = Webhook::with(['account'])
                ->orderBy('account_id')
                ->orderBy('entity_type')
                ->orderBy('action')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $webhooks
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch webhooks list', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить список аккаунтов с статусами вебхуков
     *
     * GET /admin/webhooks/accounts
     */
    public function accounts(): JsonResponse
    {
        try {
            $accounts = Account::all();
            $accountsData = [];

            foreach ($accounts as $account) {
                $webhooks = Webhook::where('account_id', $account->account_id)->get();

                $totalWebhooks = $webhooks->count();
                $activeWebhooks = $webhooks->where('enabled', true)->count();
                $totalReceived = $webhooks->sum('total_received');
                $totalFailed = $webhooks->sum('total_failed');

                $failureRate = $totalReceived > 0
                    ? round(($totalFailed / $totalReceived) * 100, 2)
                    : 0;

                $accountsData[] = [
                    'account_id' => $account->account_id,
                    'account_name' => $account->accountName ?? 'Unknown',
                    'account_type' => $account->account_type ?? 'main',
                    'total_webhooks' => $totalWebhooks,
                    'active_webhooks' => $activeWebhooks,
                    'inactive_webhooks' => $totalWebhooks - $activeWebhooks,
                    'total_received' => $totalReceived,
                    'total_failed' => $totalFailed,
                    'failure_rate' => $failureRate,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $accountsData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch accounts webhook status', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить вебхуки конкретного аккаунта с health report
     *
     * GET /admin/webhooks/{accountId}
     */
    public function show(string $accountId): JsonResponse
    {
        try {
            // Get health report (includes all webhook data)
            $report = $this->healthService->getHealthReport($accountId);

            return response()->json([
                'success' => true,
                'data' => $report
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch account webhooks', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Переустановить вебхуки для аккаунта
     *
     * POST /admin/webhooks/{accountId}/reinstall
     */
    public function reinstall(Request $request, string $accountId): JsonResponse
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();
            $accountType = $request->input('account_type', $account->account_type ?? 'main');

            // Dispatch reinstall job
            SetupAccountWebhooksJob::dispatch($accountId, $accountType, 'reinstall');

            Log::info('Webhook reinstall job dispatched', [
                'account_id' => $accountId,
                'account_type' => $accountType
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook reinstallation job dispatched',
                'account_id' => $accountId,
                'account_type' => $accountType
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to dispatch webhook reinstall', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Проверить здоровье вебхуков аккаунта
     *
     * POST /admin/webhooks/{accountId}/health-check
     */
    public function healthCheck(Request $request, string $accountId): JsonResponse
    {
        try {
            $autoHeal = $request->input('auto_heal', false);

            // Dispatch health check job
            CheckWebhookHealthJob::dispatch($accountId, $autoHeal);

            Log::info('Webhook health check job dispatched', [
                'account_id' => $accountId,
                'auto_heal' => $autoHeal
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook health check job dispatched',
                'account_id' => $accountId,
                'auto_heal' => $autoHeal
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to dispatch webhook health check', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Проверить здоровье вебхуков всех аккаунтов
     *
     * POST /admin/webhooks/health-check-all
     */
    public function healthCheckAll(Request $request): JsonResponse
    {
        try {
            $autoHeal = $request->input('auto_heal', false);

            // Dispatch health check job for all accounts
            CheckWebhookHealthJob::dispatch(null, $autoHeal);

            Log::info('Webhook health check job dispatched for all accounts', [
                'auto_heal' => $autoHeal
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook health check job dispatched for all accounts',
                'auto_heal' => $autoHeal
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to dispatch webhook health check for all accounts', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить логи вебхуков аккаунта
     *
     * GET /admin/webhooks/{accountId}/logs
     */
    public function logs(Request $request, string $accountId): JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 50);
            $status = $request->input('status'); // pending, processing, completed, failed

            $query = WebhookLog::where('account_id', $accountId)
                ->with('webhook')
                ->orderBy('created_at', 'desc');

            if ($status) {
                $query->where('status', $status);
            }

            $logs = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $logs
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch webhook logs', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить статистику обработки вебхуков
     *
     * GET /admin/webhooks/{accountId}/stats
     */
    public function stats(Request $request, string $accountId): JsonResponse
    {
        try {
            $hours = $request->input('hours', 24);

            $stats = $this->processorService->getProcessingStats($accountId, $hours);

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch webhook stats', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить аккаунты с проблемами вебхуков
     *
     * GET /admin/webhooks/problems
     */
    public function problems(): JsonResponse
    {
        try {
            $problemAccounts = $this->healthService->getAccountsWithProblems();

            return response()->json([
                'success' => true,
                'data' => $problemAccounts
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch accounts with webhook problems', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
