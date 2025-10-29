<?php

namespace App\Jobs;

use App\Models\WebhookLog;
use App\Services\Webhook\WebhookProcessorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessWebhookJob
 *
 * Асинхронная обработка webhook payload из очереди
 *
 * Вызывается WebhookController после быстрой валидации и сохранения в webhook_logs
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток выполнения job
     */
    public int $tries = 3;

    /**
     * Таймаут выполнения в секундах
     */
    public int $timeout = 120;

    /**
     * ID лога вебхука для обработки
     */
    protected int $webhookLogId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $webhookLogId)
    {
        $this->webhookLogId = $webhookLogId;
        $this->onQueue('webhooks'); // Используем отдельную очередь для вебхуков
    }

    /**
     * Execute the job.
     */
    public function handle(WebhookProcessorService $processorService): void
    {
        try {
            // 1. Загрузить webhook log
            $webhookLog = WebhookLog::findOrFail($this->webhookLogId);

            Log::info('Processing webhook job started', [
                'job_id' => $this->job?->getJobId(),
                'webhook_log_id' => $this->webhookLogId,
                'request_id' => $webhookLog->request_id,
                'entity_type' => $webhookLog->entity_type,
                'action' => $webhookLog->action,
                'attempt' => $this->attempts()
            ]);

            // 2. Проверить что лог ещё не обработан
            if ($webhookLog->status === 'completed') {
                Log::info('Webhook already processed, skipping', [
                    'webhook_log_id' => $this->webhookLogId,
                    'request_id' => $webhookLog->request_id
                ]);
                return;
            }

            // 3. Проверить что лог processable
            if (!$webhookLog->isProcessable()) {
                Log::warning('Webhook log is not processable', [
                    'webhook_log_id' => $this->webhookLogId,
                    'status' => $webhookLog->status
                ]);
                return;
            }

            // 4. Обработать webhook
            $processorService->process($webhookLog);

            Log::info('Processing webhook job completed', [
                'job_id' => $this->job?->getJobId(),
                'webhook_log_id' => $this->webhookLogId,
                'request_id' => $webhookLog->request_id
            ]);

        } catch (\Exception $e) {
            Log::error('Processing webhook job failed', [
                'job_id' => $this->job?->getJobId(),
                'webhook_log_id' => $this->webhookLogId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Если это последняя попытка, отметить webhook как failed
            if ($this->attempts() >= $this->tries) {
                try {
                    $webhookLog = WebhookLog::find($this->webhookLogId);
                    if ($webhookLog && $webhookLog->status !== 'completed') {
                        $webhookLog->markAsFailed(
                            'Job failed after ' . $this->tries . ' attempts: ' . $e->getMessage()
                        );

                        // Update webhook failure counter
                        if ($webhookLog->webhook) {
                            $webhookLog->webhook->incrementFailed();
                        }
                    }
                } catch (\Exception $markFailedError) {
                    Log::error('Failed to mark webhook as failed', [
                        'webhook_log_id' => $this->webhookLogId,
                        'error' => $markFailedError->getMessage()
                    ]);
                }
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Processing webhook job failed permanently', [
            'webhook_log_id' => $this->webhookLogId,
            'attempts' => $this->tries,
            'error' => $exception->getMessage()
        ]);

        // TODO: Day 8 - Send notification to admin
        // TODO: Day 8 - Add to failed webhooks dashboard
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['webhook', 'webhook_log:' . $this->webhookLogId];
    }
}
