<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Webhook\WebhookHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * CheckWebhookHealthJob
 *
 * Периодическая проверка здоровья вебхуков всех аккаунтов
 *
 * Вызывается по расписанию (например, каждый час)
 * Проверяет:
 * - Существуют ли вебхуки в МойСклад
 * - Активны ли они
 * - Высокий ли процент ошибок
 * - Запускает auto-healing для критических проблем
 */
class CheckWebhookHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток выполнения job
     */
    public int $tries = 1;

    /**
     * Таймаут выполнения в секундах
     */
    public int $timeout = 300;

    /**
     * ID аккаунта для проверки (опционально, если null - проверяются все)
     */
    protected ?string $accountId;

    /**
     * Выполнить auto-healing при обнаружении проблем
     */
    protected bool $autoHeal;

    /**
     * Create a new job instance.
     *
     * @param string|null $accountId UUID аккаунта (null = проверить все аккаунты)
     * @param bool $autoHeal Выполнить auto-healing для проблемных вебхуков
     */
    public function __construct(?string $accountId = null, bool $autoHeal = true)
    {
        $this->accountId = $accountId;
        $this->autoHeal = $autoHeal;
        $this->onQueue('default'); // Используем обычную очередь
    }

    /**
     * Execute the job.
     */
    public function handle(WebhookHealthService $healthService): void
    {
        try {
            Log::info('Check webhook health job started', [
                'job_id' => $this->job?->getJobId(),
                'account_id' => $this->accountId ?? 'all',
                'auto_heal' => $this->autoHeal
            ]);

            // 1. Определить какие аккаунты проверять
            if ($this->accountId) {
                $accounts = Account::where('account_id', $this->accountId)->get();
            } else {
                $accounts = Account::all();
            }

            if ($accounts->isEmpty()) {
                Log::warning('No accounts found for webhook health check');
                return;
            }

            $totalAccounts = $accounts->count();
            $healthyAccounts = 0;
            $degradedAccounts = 0;
            $criticalAccounts = 0;
            $healedWebhooks = 0;
            $failedHealing = 0;

            // 2. Проверить каждый аккаунт
            foreach ($accounts as $account) {
                try {
                    // Получить health report
                    $report = $healthService->getHealthReport($account->account_id);

                    Log::info('Account webhook health checked', [
                        'account_id' => $account->account_id,
                        'overall_health' => $report['overall_health'],
                        'active' => $report['summary']['active'],
                        'inactive' => $report['summary']['inactive'],
                        'critical' => $report['summary']['critical'],
                        'degraded' => $report['summary']['degraded']
                    ]);

                    // Подсчитать статистику
                    switch ($report['overall_health']) {
                        case 'healthy':
                            $healthyAccounts++;
                            break;
                        case 'degraded':
                            $degradedAccounts++;
                            break;
                        case 'critical':
                            $criticalAccounts++;
                            break;
                    }

                    // 3. Auto-healing для критических проблем
                    if ($this->autoHeal && $report['overall_health'] === 'critical') {
                        Log::info('Attempting auto-heal for critical account', [
                            'account_id' => $account->account_id
                        ]);

                        try {
                            $healResult = $healthService->autoHeal($account->account_id);
                            $healedWebhooks += count($healResult['healed']);
                            $failedHealing += count($healResult['failed']);

                            Log::info('Auto-heal completed', [
                                'account_id' => $account->account_id,
                                'healed' => count($healResult['healed']),
                                'failed' => count($healResult['failed'])
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Auto-heal failed', [
                                'account_id' => $account->account_id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    // 4. Уведомить о критических проблемах
                    if ($report['overall_health'] === 'critical') {
                        $healthService->notifyProblems($account->account_id);
                    }

                } catch (\Exception $e) {
                    Log::error('Failed to check webhook health for account', [
                        'account_id' => $account->account_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // 5. Итоговая статистика
            Log::info('Check webhook health job completed', [
                'job_id' => $this->job?->getJobId(),
                'total_accounts' => $totalAccounts,
                'healthy' => $healthyAccounts,
                'degraded' => $degradedAccounts,
                'critical' => $criticalAccounts,
                'healed_webhooks' => $healedWebhooks,
                'failed_healing' => $failedHealing
            ]);

            // 6. Логировать если есть критические аккаунты
            if ($criticalAccounts > 0) {
                Log::warning('Found accounts with critical webhook problems', [
                    'critical_accounts' => $criticalAccounts,
                    'total_accounts' => $totalAccounts
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Check webhook health job failed', [
                'job_id' => $this->job?->getJobId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Check webhook health job failed permanently', [
            'account_id' => $this->accountId ?? 'all',
            'error' => $exception->getMessage()
        ]);

        // TODO: Day 8 - Send critical alert to admin
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        $tags = ['webhook-health'];

        if ($this->accountId) {
            $tags[] = 'account:' . $this->accountId;
        } else {
            $tags[] = 'all-accounts';
        }

        if ($this->autoHeal) {
            $tags[] = 'auto-heal';
        }

        return $tags;
    }
}
