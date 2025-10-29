<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Webhook;
use App\Services\Webhook\WebhookProcessorService;
use Illuminate\Console\Command;

/**
 * Artisan команда для отображения статистики webhook обработки
 *
 * Usage:
 * php artisan webhook:stats {accountId}            # Статистика за 24 часа
 * php artisan webhook:stats {accountId} --hours=48 # Статистика за 48 часов
 * php artisan webhook:stats {accountId} --detailed # Детальная статистика по каждому webhook
 */
class WebhookStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'webhook:stats
                            {accountId : UUID аккаунта}
                            {--hours=24 : Количество часов для анализа}
                            {--detailed : Показать детальную статистику по каждому webhook}';

    /**
     * The console command description.
     */
    protected $description = 'Показать статистику обработки вебхуков для аккаунта';

    /**
     * Execute the console command.
     */
    public function handle(WebhookProcessorService $processorService): int
    {
        $accountId = $this->argument('accountId');
        $hours = (int) $this->option('hours');
        $detailed = $this->option('detailed');

        try {
            // Check if account exists
            $account = Account::where('account_id', $accountId)->first();

            if (!$account) {
                $this->error("Account not found: {$accountId}");
                return self::FAILURE;
            }

            $accountName = $account->accountName ?? 'Unknown';

            $this->info("Webhook Statistics for: {$accountName}");
            $this->line("Account ID: {$accountId}");
            $this->line("Period: Last {$hours} hours");
            $this->line('');

            // Get processing stats
            $stats = $processorService->getProcessingStats($accountId, $hours);

            $this->displayProcessingStats($stats);

            // Get webhook-specific stats if detailed
            if ($detailed) {
                $this->line('');
                $this->displayDetailedStats($accountId);
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->line('');
            $this->error('✗ Failed to fetch webhook stats!');
            $this->error($e->getMessage());

            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Отобразить статистику обработки
     */
    protected function displayProcessingStats(array $stats): void
    {
        $this->info('=== Processing Statistics ===');
        $this->line('');

        // Overall stats
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Received', $stats['total']],
                ['Pending', $stats['pending']],
                ['Processing', $stats['processing']],
                ['Completed', $stats['completed']],
                ['Failed', $stats['failed']],
                ['Success Rate', $stats['success_rate'] . '%'],
                ['Avg Processing Time', $stats['avg_processing_time_ms'] . ' ms'],
            ]
        );

        // Status indicators
        $this->line('');
        if ($stats['success_rate'] >= 95) {
            $this->info('✓ Performance: EXCELLENT');
        } elseif ($stats['success_rate'] >= 85) {
            $this->warn('⚠ Performance: GOOD');
        } elseif ($stats['success_rate'] >= 70) {
            $this->warn('⚠ Performance: DEGRADED');
        } else {
            $this->error('✗ Performance: POOR');
        }

        if ($stats['avg_processing_time_ms'] > 0) {
            if ($stats['avg_processing_time_ms'] < 100) {
                $this->info('✓ Speed: FAST');
            } elseif ($stats['avg_processing_time_ms'] < 500) {
                $this->info('✓ Speed: NORMAL');
            } else {
                $this->warn('⚠ Speed: SLOW');
            }
        }
    }

    /**
     * Отобразить детальную статистику по каждому webhook
     */
    protected function displayDetailedStats(string $accountId): void
    {
        $webhooks = Webhook::where('account_id', $accountId)
            ->orderBy('entity_type')
            ->orderBy('action')
            ->get();

        if ($webhooks->isEmpty()) {
            $this->warn('No webhooks found for this account');
            return;
        }

        $this->info('=== Webhook Details ===');
        $this->line('');

        $tableData = [];

        foreach ($webhooks as $webhook) {
            $failureRate = $webhook->total_received > 0
                ? round(($webhook->total_failed / $webhook->total_received) * 100, 2)
                : 0;

            $healthIcon = $webhook->is_healthy ? '✓' : '✗';
            $enabledIcon = $webhook->enabled ? '✓' : '✗';

            $lastTriggered = $webhook->last_triggered_at
                ? $webhook->last_triggered_at->diffForHumans()
                : 'Never';

            $tableData[] = [
                $webhook->entity_type,
                $webhook->action,
                $enabledIcon,
                $healthIcon,
                $webhook->total_received,
                $webhook->total_failed,
                $failureRate . '%',
                $lastTriggered,
            ];
        }

        $this->table(
            ['Entity', 'Action', 'Enabled', 'Healthy', 'Received', 'Failed', 'Failure %', 'Last Triggered'],
            $tableData
        );

        // Summary
        $this->line('');
        $this->info('Summary:');
        $totalWebhooks = $webhooks->count();
        $enabledWebhooks = $webhooks->where('enabled', true)->count();
        $healthyWebhooks = $webhooks->where('is_healthy', true)->count();
        $totalReceived = $webhooks->sum('total_received');
        $totalFailed = $webhooks->sum('total_failed');

        $this->line("Total Webhooks: {$totalWebhooks}");
        $this->line("Enabled: {$enabledWebhooks}/{$totalWebhooks}");
        $this->line("Healthy: {$healthyWebhooks}/{$totalWebhooks}");
        $this->line("Total Received (All Time): {$totalReceived}");
        $this->line("Total Failed (All Time): {$totalFailed}");

        $overallFailureRate = $totalReceived > 0
            ? round(($totalFailed / $totalReceived) * 100, 2)
            : 0;
        $this->line("Overall Failure Rate: {$overallFailureRate}%");
    }
}
