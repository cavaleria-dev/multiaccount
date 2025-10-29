<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\Webhook\WebhookSetupService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для проверки и настройки вебхуков
 *
 * Обрабатывает entity_type: 'webhook'
 */
class WebhookCheckHandler extends SyncTaskHandler
{
    public function __construct(
        protected WebhookSetupService $webhookService
    ) {}

    public function getEntityType(): string
    {
        return 'webhook';
    }

    /**
     * Webhook check не требует main_account_id
     */
    protected function requiresMainAccountId(): bool
    {
        return false;
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $accountId = $task->account_id;

        Log::info('Webhook check started', [
            'task_id' => $task->id,
            'account_id' => $accountId
        ]);

        // Проверить и настроить вебхуки для аккаунта
        $result = $this->webhookService->checkAndSetupWebhooks($accountId);

        $this->logSuccess($task, [
            'account_id' => $accountId,
            'webhooks_checked' => $result['checked'] ?? 0,
            'webhooks_created' => $result['created'] ?? 0,
            'webhooks_failed' => $result['failed'] ?? 0
        ]);
    }
}
