<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Webhook\WebhookHealthService;
use Illuminate\Console\Command;

/**
 * Artisan команда для проверки здоровья вебхуков
 *
 * Usage:
 * php artisan webhook:health-check                        # Проверить все аккаунты
 * php artisan webhook:health-check {accountId}            # Проверить конкретный аккаунт
 * php artisan webhook:health-check --auto-heal            # Проверить все + auto-heal
 * php artisan webhook:health-check {accountId} --auto-heal
 */
class WebhookHealthCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'webhook:health-check
                            {accountId? : UUID аккаунта (опционально, если не указан - проверяются все)}
                            {--auto-heal : Включить автоматическое исправление проблемных вебхуков}';

    /**
     * The console command description.
     */
    protected $description = 'Проверить здоровье вебхуков (одного аккаунта или всех)';

    /**
     * Execute the console command.
     */
    public function handle(WebhookHealthService $healthService): int
    {
        $accountId = $this->argument('accountId');
        $autoHeal = $this->option('auto-heal');

        if ($accountId) {
            // Check specific account
            return $this->checkAccountHealth($accountId, $autoHeal, $healthService);
        } else {
            // Check all accounts
            return $this->checkAllAccountsHealth($autoHeal, $healthService);
        }
    }

    /**
     * Проверить здоровье конкретного аккаунта
     */
    protected function checkAccountHealth(string $accountId, bool $autoHeal, WebhookHealthService $healthService): int
    {
        $this->info("Checking webhook health for account: {$accountId}");

        try {
            // Check if account exists
            $account = Account::where('account_id', $accountId)->first();

            if (!$account) {
                $this->error("Account not found: {$accountId}");
                return self::FAILURE;
            }

            // Get health report
            $report = $healthService->getHealthReport($accountId);

            $this->line('');
            $this->displayHealthReport($report);

            // Auto-heal if requested and needed
            if ($autoHeal && $report['overall_health'] === 'critical') {
                $this->line('');
                $this->warn('⚠ Critical issues detected, attempting auto-heal...');

                $healResult = $healthService->autoHeal($accountId);

                $this->line('');
                $this->info("✓ Auto-heal completed:");
                $this->line("  - Healed: " . count($healResult['healed']));
                $this->line("  - Failed: " . count($healResult['failed']));

                if (!empty($healResult['failed'])) {
                    $this->line('');
                    $this->warn('Failed to heal:');
                    foreach ($healResult['failed'] as $failed) {
                        $this->line("  - {$failed['entity_type']} ({$failed['action']}): {$failed['error']}");
                    }
                }
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->line('');
            $this->error('✗ Health check failed!');
            $this->error($e->getMessage());

            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Проверить здоровье всех аккаунтов
     */
    protected function checkAllAccountsHealth(bool $autoHeal, WebhookHealthService $healthService): int
    {
        $this->info('Checking webhook health for all accounts...');

        try {
            $accounts = Account::all();

            if ($accounts->isEmpty()) {
                $this->warn('No accounts found');
                return self::SUCCESS;
            }

            $this->line('');
            $this->info("Found {$accounts->count()} accounts");

            $healthyCount = 0;
            $degradedCount = 0;
            $criticalCount = 0;
            $totalHealed = 0;

            foreach ($accounts as $account) {
                try {
                    $report = $healthService->getHealthReport($account->account_id);

                    $statusIcon = match($report['overall_health']) {
                        'healthy' => '✓',
                        'degraded' => '⚠',
                        'critical' => '✗',
                        default => '?'
                    };

                    $accountName = $account->accountName ?? 'Unknown';
                    $this->line("{$statusIcon} {$accountName} ({$account->account_id}): {$report['overall_health']}");

                    // Count by status
                    match($report['overall_health']) {
                        'healthy' => $healthyCount++,
                        'degraded' => $degradedCount++,
                        'critical' => $criticalCount++,
                        default => null
                    };

                    // Auto-heal critical accounts
                    if ($autoHeal && $report['overall_health'] === 'critical') {
                        $healResult = $healthService->autoHeal($account->account_id);
                        $totalHealed += count($healResult['healed']);
                    }

                } catch (\Exception $e) {
                    $this->error("  ✗ Failed to check {$account->account_id}: {$e->getMessage()}");
                }
            }

            // Summary
            $this->line('');
            $this->info('=== Summary ===');
            $this->line("Total accounts: {$accounts->count()}");
            $this->line("✓ Healthy: {$healthyCount}");
            $this->line("⚠ Degraded: {$degradedCount}");
            $this->line("✗ Critical: {$criticalCount}");

            if ($autoHeal && $totalHealed > 0) {
                $this->line('');
                $this->info("Auto-healed {$totalHealed} webhooks");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->line('');
            $this->error('✗ Health check failed!');
            $this->error($e->getMessage());

            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Отобразить health report
     */
    protected function displayHealthReport(array $report): void
    {
        $statusColor = match($report['overall_health']) {
            'healthy' => 'info',
            'degraded' => 'warn',
            'critical' => 'error',
            default => 'line'
        };

        $this->$statusColor("Overall Health: " . strtoupper($report['overall_health']));

        $this->line('');
        $this->info('=== Summary ===');
        $this->line("Total webhooks: {$report['summary']['total_webhooks']}");
        $this->line("✓ Active: {$report['summary']['active']}");
        $this->line("✗ Inactive: {$report['summary']['inactive']}");
        $this->line("Healthy: {$report['summary']['healthy']}");
        $this->line("Degraded: {$report['summary']['degraded']}");
        $this->line("Critical: {$report['summary']['critical']}");

        $this->line('');
        $this->info('=== Metrics (All Time) ===');
        $this->line("Total received: {$report['metrics']['total_received']}");
        $this->line("Total failed: {$report['metrics']['total_failed']}");
        $this->line("Failure rate: {$report['metrics']['failure_rate']}%");

        $this->line('');
        $this->info('=== Recent Activity (24h) ===');
        $this->line("Total: {$report['recent_24h']['total']}");
        $this->line("Completed: {$report['recent_24h']['completed']}");
        $this->line("Failed: {$report['recent_24h']['failed']}");
        $this->line("Pending: {$report['recent_24h']['pending']}");
        $this->line("Success rate: {$report['recent_24h']['success_rate']}%");

        // Display problems if any
        if (!empty($report['problems'])) {
            $this->line('');
            $this->warn('=== Problems Detected ===');
            foreach ($report['problems'] as $problem) {
                $severityIcon = match($problem['severity']) {
                    'critical' => '✗',
                    'warning' => '⚠',
                    'info' => 'ℹ',
                    default => '-'
                };
                $this->line("{$severityIcon} [{$problem['type']}] {$problem['entity_type']} ({$problem['action']})");
                $this->line("   {$problem['message']}");
                $this->line("   → {$problem['recommendation']}");
            }
        }
    }
}
