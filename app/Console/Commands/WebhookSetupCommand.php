<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Webhook\WebhookSetupService;
use Illuminate\Console\Command;

/**
 * Artisan команда для установки вебхуков для аккаунта
 *
 * Usage:
 * php artisan webhook:setup {accountId} {accountType}
 * php artisan webhook:setup 12345678-1234-1234-1234-123456789012 main
 */
class WebhookSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'webhook:setup
                            {accountId : UUID аккаунта}
                            {accountType : Тип аккаунта (main или child)}';

    /**
     * The console command description.
     */
    protected $description = 'Установить вебхуки для аккаунта МойСклад';

    /**
     * Execute the console command.
     */
    public function handle(WebhookSetupService $setupService): int
    {
        $accountId = $this->argument('accountId');
        $accountType = $this->argument('accountType');

        // Validate account type
        if (!in_array($accountType, ['main', 'child'])) {
            $this->error('Account type must be "main" or "child"');
            return self::FAILURE;
        }

        $this->info("Setting up webhooks for account: {$accountId} ({$accountType})");

        try {
            // Check if account exists
            $account = Account::where('account_id', $accountId)->first();

            if (!$account) {
                $this->error("Account not found: {$accountId}");
                return self::FAILURE;
            }

            if (!$account->access_token) {
                $this->error("Account has no access token");
                return self::FAILURE;
            }

            $this->line('');
            $this->info('Setting up webhooks...');

            // Setup webhooks
            $webhooks = $setupService->setupWebhooks($accountId, $accountType);

            $this->line('');
            $this->info('✓ Webhooks setup completed!');
            $this->line('');
            $this->table(
                ['Entity Type', 'Action', 'МойСклад ID'],
                collect($webhooks)->map(function($webhook) {
                    return [
                        $webhook['entityType'] ?? 'N/A',
                        $webhook['action'] ?? 'N/A',
                        $webhook['id'] ?? 'N/A',
                    ];
                })->toArray()
            );

            $this->line('');
            $this->info("Total webhooks created: " . count($webhooks));

            // Run health check
            $this->line('');
            $this->info('Running health check...');
            $healthRecords = $setupService->checkWebhookHealth($accountId);

            $activeCount = collect($healthRecords)->where('is_active', true)->count();
            $totalCount = count($healthRecords);

            $this->info("✓ Health check completed: {$activeCount}/{$totalCount} webhooks active");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->line('');
            $this->error('✗ Webhook setup failed!');
            $this->error($e->getMessage());
            $this->line('');

            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
