<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Webhook\WebhookSetupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SetupAccountWebhooksJob
 *
 * Установка или переустановка вебхуков для аккаунта
 *
 * Вызывается:
 * - При создании нового аккаунта
 * - При ручной переустановке через админку
 * - При восстановлении после критических ошибок
 */
class SetupAccountWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток выполнения job
     */
    public int $tries = 2;

    /**
     * Таймаут выполнения в секундах
     */
    public int $timeout = 180;

    /**
     * ID аккаунта
     */
    protected string $accountId;

    /**
     * Тип аккаунта (main/child)
     */
    protected string $accountType;

    /**
     * Режим установки (setup или reinstall)
     */
    protected string $mode;

    /**
     * Create a new job instance.
     *
     * @param string $accountId UUID аккаунта
     * @param string $accountType Тип аккаунта (main/child)
     * @param string $mode Режим: 'setup' (первичная установка) или 'reinstall' (переустановка)
     */
    public function __construct(string $accountId, string $accountType, string $mode = 'setup')
    {
        $this->accountId = $accountId;
        $this->accountType = $accountType;
        $this->mode = $mode;
        $this->onQueue('default'); // Используем обычную очередь
    }

    /**
     * Execute the job.
     */
    public function handle(WebhookSetupService $setupService): void
    {
        try {
            Log::info('Setup account webhooks job started', [
                'job_id' => $this->job?->getJobId(),
                'account_id' => $this->accountId,
                'account_type' => $this->accountType,
                'mode' => $this->mode,
                'attempt' => $this->attempts()
            ]);

            // 1. Загрузить аккаунт
            $account = Account::where('account_id', $this->accountId)->firstOrFail();

            if (!$account->access_token) {
                throw new \Exception('Account has no access token');
            }

            // 2. Выполнить setup или reinstall в зависимости от режима
            if ($this->mode === 'reinstall') {
                $result = $setupService->reinstallWebhooks($account, $this->accountType);

                Log::info('Webhooks reinstallation completed', [
                    'job_id' => $this->job?->getJobId(),
                    'account_id' => $this->accountId,
                    'created' => count($result['created']),
                    'errors' => count($result['errors'])
                ]);

                if (!empty($result['errors'])) {
                    Log::warning('Webhooks reinstallation had errors', [
                        'account_id' => $this->accountId,
                        'errors' => $result['errors']
                    ]);
                }
            } else {
                $webhooks = $setupService->setupWebhooks($this->accountId, $this->accountType);

                Log::info('Webhooks setup completed', [
                    'job_id' => $this->job?->getJobId(),
                    'account_id' => $this->accountId,
                    'webhooks_count' => count($webhooks)
                ]);
            }

            // 3. После установки, выполнить health check
            try {
                $setupService->checkWebhookHealth($this->accountId);
            } catch (\Exception $e) {
                Log::warning('Post-setup health check failed', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage()
                ]);
            }

            Log::info('Setup account webhooks job completed', [
                'job_id' => $this->job?->getJobId(),
                'account_id' => $this->accountId
            ]);

        } catch (\Exception $e) {
            Log::error('Setup account webhooks job failed', [
                'job_id' => $this->job?->getJobId(),
                'account_id' => $this->accountId,
                'account_type' => $this->accountType,
                'mode' => $this->mode,
                'attempt' => $this->attempts(),
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
        Log::critical('Setup account webhooks job failed permanently', [
            'account_id' => $this->accountId,
            'account_type' => $this->accountType,
            'mode' => $this->mode,
            'attempts' => $this->tries,
            'error' => $exception->getMessage()
        ]);

        // TODO: Day 8 - Send notification to admin
        // TODO: Day 8 - Update account status to indicate webhook setup failed
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['webhook-setup', 'account:' . $this->accountId, 'mode:' . $this->mode];
    }
}
