<?php

namespace App\Services\Webhook;

use App\Models\Account;
use App\Models\Webhook;
use App\Models\WebhookHealthStat;
use App\Models\WebhookLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * WebhookHealthService
 *
 * Service for monitoring webhook health and triggering auto-healing
 *
 * Responsibilities:
 * - Aggregate health metrics from webhook_health_stats
 * - Identify problematic webhooks (high failure rate, missing, disabled)
 * - Trigger auto-healing for failed webhooks
 * - Generate health reports for monitoring
 */
class WebhookHealthService
{
    public function __construct(
        protected WebhookSetupService $setupService
    ) {}

    /**
     * Получить health report для аккаунта
     *
     * @param string $accountId UUID аккаунта
     * @return array Health report
     */
    public function getHealthReport(string $accountId): array
    {
        $account = Account::where('account_id', $accountId)->firstOrFail();

        // 1. Get all health stats
        $healthStats = WebhookHealthStat::where('account_id', $accountId)->get();

        // 2. Get webhooks with counters
        $webhooks = Webhook::where('account_id', $accountId)->get();

        // 3. Calculate metrics
        $totalWebhooks = $healthStats->count();
        $activeWebhooks = $healthStats->where('is_active', true)->count();
        $inactiveWebhooks = $healthStats->where('is_active', false)->count();

        $healthyWebhooks = $healthStats->filter(function($stat) {
            return $stat->health_status === 'healthy';
        })->count();

        $degradedWebhooks = $healthStats->filter(function($stat) {
            return $stat->health_status === 'degraded';
        })->count();

        $criticalWebhooks = $healthStats->filter(function($stat) {
            return $stat->health_status === 'critical';
        })->count();

        // 4. Calculate failure rate
        $totalReceived = $webhooks->sum('total_received');
        $totalFailed = $webhooks->sum('total_failed');
        $failureRate = $totalReceived > 0 ? round(($totalFailed / $totalReceived) * 100, 2) : 0;

        // 5. Get recent processing stats (last 24 hours)
        $since = now()->subHours(24);
        $recentLogs = WebhookLog::where('account_id', $accountId)
            ->where('created_at', '>=', $since)
            ->get();

        $recentTotal = $recentLogs->count();
        $recentCompleted = $recentLogs->where('status', 'completed')->count();
        $recentFailed = $recentLogs->where('status', 'failed')->count();
        $recentPending = $recentLogs->where('status', 'pending')->count();

        $recentSuccessRate = $recentTotal > 0 ? round(($recentCompleted / $recentTotal) * 100, 2) : 0;

        // 6. Identify problems
        $problems = $this->identifyProblems($healthStats, $webhooks);

        // 7. Overall health status
        $overallHealth = $this->calculateOverallHealth($criticalWebhooks, $degradedWebhooks, $totalWebhooks);

        return [
            'account_id' => $accountId,
            'account_type' => $account->account_type ?? 'main',
            'overall_health' => $overallHealth,
            'summary' => [
                'total_webhooks' => $totalWebhooks,
                'active' => $activeWebhooks,
                'inactive' => $inactiveWebhooks,
                'healthy' => $healthyWebhooks,
                'degraded' => $degradedWebhooks,
                'critical' => $criticalWebhooks,
            ],
            'metrics' => [
                'total_received' => $totalReceived,
                'total_failed' => $totalFailed,
                'failure_rate' => $failureRate,
            ],
            'recent_24h' => [
                'total' => $recentTotal,
                'completed' => $recentCompleted,
                'failed' => $recentFailed,
                'pending' => $recentPending,
                'success_rate' => $recentSuccessRate,
            ],
            'problems' => $problems,
            'webhooks' => $healthStats->map(function($stat) use ($webhooks) {
                $webhook = $webhooks->firstWhere('id', $stat->webhook_id);

                return [
                    'entity_type' => $stat->entity_type,
                    'action' => $stat->action,
                    'is_active' => $stat->is_active,
                    'health_status' => $stat->health_status,
                    'status_color' => $stat->status_color,
                    'total_received' => $webhook?->total_received ?? 0,
                    'total_failed' => $webhook?->total_failed ?? 0,
                    'last_triggered_at' => $webhook?->last_triggered_at,
                    'last_check_at' => $stat->last_check_at,
                    'last_success_at' => $stat->last_success_at,
                    'check_attempts' => $stat->check_attempts,
                    'error_message' => $stat->error_message,
                ];
            })->toArray()
        ];
    }

    /**
     * Идентифицировать проблемы с вебхуками
     *
     * @param Collection $healthStats Статистика здоровья
     * @param Collection $webhooks Вебхуки
     * @return array Список проблем
     */
    protected function identifyProblems(Collection $healthStats, Collection $webhooks): array
    {
        $problems = [];

        // 1. Critical webhooks (inactive + high check attempts)
        $critical = $healthStats->filter(function($stat) {
            return $stat->health_status === 'critical';
        });

        foreach ($critical as $stat) {
            $problems[] = [
                'severity' => 'critical',
                'type' => 'webhook_missing',
                'entity_type' => $stat->entity_type,
                'action' => $stat->action,
                'message' => "Webhook не найден в МойСклад после {$stat->check_attempts} проверок",
                'recommendation' => 'Требуется ручное переустановка или проверка прав доступа'
            ];
        }

        // 2. High failure rate webhooks
        foreach ($webhooks as $webhook) {
            if ($webhook->total_received > 10 && !$webhook->is_healthy) {
                $failureRate = round(($webhook->total_failed / $webhook->total_received) * 100, 2);

                $problems[] = [
                    'severity' => 'warning',
                    'type' => 'high_failure_rate',
                    'entity_type' => $webhook->entity_type,
                    'action' => $webhook->action,
                    'message' => "Высокий процент ошибок: {$failureRate}% ({$webhook->total_failed}/{$webhook->total_received})",
                    'recommendation' => 'Проверьте логи обработки для этого типа вебхука'
                ];
            }
        }

        // 3. Never triggered webhooks (inactive for > 7 days)
        $weekAgo = now()->subDays(7);
        $neverTriggered = $webhooks->filter(function($webhook) use ($weekAgo) {
            return $webhook->total_received === 0
                && $webhook->created_at < $weekAgo;
        });

        foreach ($neverTriggered as $webhook) {
            $problems[] = [
                'severity' => 'info',
                'type' => 'never_triggered',
                'entity_type' => $webhook->entity_type,
                'action' => $webhook->action,
                'message' => 'Webhook не получал события более 7 дней',
                'recommendation' => 'Возможно, в МойСклад нет изменений этого типа или webhook не работает'
            ];
        }

        // 4. Degraded webhooks (active but with check attempts)
        $degraded = $healthStats->filter(function($stat) {
            return $stat->health_status === 'degraded';
        });

        foreach ($degraded as $stat) {
            $problems[] = [
                'severity' => 'warning',
                'type' => 'degraded',
                'entity_type' => $stat->entity_type,
                'action' => $stat->action,
                'message' => "Webhook имеет {$stat->check_attempts} неудачных проверок",
                'recommendation' => 'Следите за этим webhook, может потребоваться переустановка'
            ];
        }

        return $problems;
    }

    /**
     * Рассчитать общее здоровье системы вебхуков
     *
     * @param int $critical Количество critical вебхуков
     * @param int $degraded Количество degraded вебхуков
     * @param int $total Общее количество вебхуков
     * @return string Статус: 'healthy', 'degraded', 'critical'
     */
    protected function calculateOverallHealth(int $critical, int $degraded, int $total): string
    {
        if ($total === 0) {
            return 'unknown';
        }

        // If any webhooks are critical, system is critical
        if ($critical > 0) {
            return 'critical';
        }

        // If more than 25% degraded, system is degraded
        if ($degraded > ($total * 0.25)) {
            return 'degraded';
        }

        // If any webhooks are degraded, system is degraded
        if ($degraded > 0) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Попытаться автоматически исправить проблемные вебхуки
     *
     * @param string $accountId UUID аккаунта
     * @return array Результат auto-healing
     */
    public function autoHeal(string $accountId): array
    {
        $result = [
            'healed' => [],
            'failed' => [],
        ];

        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            // 1. Find problematic webhooks that need attention
            $needsAttention = WebhookHealthStat::where('account_id', $accountId)
                ->where('is_active', false)
                ->where('check_attempts', '>=', 3)
                ->get();

            if ($needsAttention->isEmpty()) {
                Log::info('No webhooks need auto-healing', [
                    'account_id' => $accountId
                ]);
                return $result;
            }

            Log::info('Starting auto-heal for problematic webhooks', [
                'account_id' => $accountId,
                'count' => $needsAttention->count()
            ]);

            // 2. Try to recreate each problematic webhook
            $webhookUrl = config('moysklad.webhook_url');

            foreach ($needsAttention as $stat) {
                try {
                    // Delete old webhook if it exists (cleanup)
                    $this->setupService->cleanupOldWebhooks($accountId);

                    // Create new webhook
                    $webhook = $this->setupService->createWebhook(
                        $account,
                        $webhookUrl,
                        $stat->action,
                        $stat->entity_type
                    );

                    // Update health stat
                    $stat->update([
                        'webhook_id' => $webhook['id'],
                        'is_active' => true,
                        'check_attempts' => 0,
                        'error_message' => null,
                        'last_success_at' => now(),
                        'last_check_at' => now(),
                    ]);

                    $result['healed'][] = [
                        'entity_type' => $stat->entity_type,
                        'action' => $stat->action,
                        'webhook_id' => $webhook['id']
                    ];

                    Log::info('Webhook auto-healed', [
                        'account_id' => $accountId,
                        'entity_type' => $stat->entity_type,
                        'action' => $stat->action,
                        'new_webhook_id' => $webhook['id']
                    ]);

                } catch (\Exception $e) {
                    $result['failed'][] = [
                        'entity_type' => $stat->entity_type,
                        'action' => $stat->action,
                        'error' => $e->getMessage()
                    ];

                    Log::error('Auto-heal failed for webhook', [
                        'account_id' => $accountId,
                        'entity_type' => $stat->entity_type,
                        'action' => $stat->action,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('Auto-heal completed', [
                'account_id' => $accountId,
                'healed' => count($result['healed']),
                'failed' => count($result['failed'])
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-heal process failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }

        return $result;
    }

    /**
     * Получить список аккаунтов с проблемами вебхуков
     *
     * @return array Аккаунты с критическими проблемами
     */
    public function getAccountsWithProblems(): array
    {
        $accounts = Account::all();
        $problemAccounts = [];

        foreach ($accounts as $account) {
            $healthStats = WebhookHealthStat::where('account_id', $account->account_id)->get();

            $criticalCount = $healthStats->filter(function($stat) {
                return $stat->health_status === 'critical';
            })->count();

            $degradedCount = $healthStats->filter(function($stat) {
                return $stat->health_status === 'degraded';
            })->count();

            if ($criticalCount > 0 || $degradedCount > 0) {
                $problemAccounts[] = [
                    'account_id' => $account->account_id,
                    'account_name' => $account->accountName ?? 'Unknown',
                    'account_type' => $account->account_type ?? 'main',
                    'critical_webhooks' => $criticalCount,
                    'degraded_webhooks' => $degradedCount,
                    'total_webhooks' => $healthStats->count(),
                ];
            }
        }

        return $problemAccounts;
    }

    /**
     * Отправить уведомление о проблемах с вебхуками
     *
     * @param string $accountId UUID аккаунта
     * @return void
     */
    public function notifyProblems(string $accountId): void
    {
        $report = $this->getHealthReport($accountId);

        if (empty($report['problems'])) {
            return;
        }

        $criticalProblems = collect($report['problems'])->filter(function($problem) {
            return $problem['severity'] === 'critical';
        });

        if ($criticalProblems->isNotEmpty()) {
            Log::critical('Critical webhook problems detected', [
                'account_id' => $accountId,
                'problems_count' => $criticalProblems->count(),
                'problems' => $criticalProblems->toArray()
            ]);

            // TODO: Integrate with notification system (email, Slack, etc.)
            // For now, just log
        }
    }
}
