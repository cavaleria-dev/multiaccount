<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Webhook\WebhookSetupService;
use Illuminate\Console\Command;

/**
 * Artisan команда для переустановки вебхуков
 *
 * Usage:
 * php artisan webhook:reinstall {accountId} {accountType}
 * php artisan webhook:reinstall 12345678-1234-1234-1234-123456789012 main
 */
class WebhookReinstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'webhook:reinstall
                            {accountId : UUID аккаунта}
                            {accountType : Тип аккаунта (main или child)}
                            {--force : Пропустить подтверждение}';

    /**
     * The console command description.
     */
    protected $description = 'Переустановить вебхуки для аккаунта (удалить старые + установить новые)';

    /**
     * Execute the console command.
     */
    public function handle(WebhookSetupService $setupService): int
    {
        $accountId = $this->argument('accountId');
        $accountType = $this->argument('accountType');
        $force = $this->option('force');

        // Validate account type
        if (!in_array($accountType, ['main', 'child'])) {
            $this->error('Account type must be "main" or "child"');
            return self::FAILURE;
        }

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

            // Confirmation
            if (!$force) {
                $accountName = $account->accountName ?? 'Unknown';

                $this->warn('⚠ WARNING: This will delete ALL existing webhooks for this account!');
                $this->line('');
                $this->line("Account: {$accountName}");
                $this->line("ID: {$accountId}");
                $this->line("Type: {$accountType}");
                $this->line('');

                if (!$this->confirm('Do you want to continue?', false)) {
                    $this->info('Reinstallation cancelled');
                    return self::SUCCESS;
                }
            }

            $this->line('');
            $this->info("Reinstalling webhooks for account: {$accountId} ({$accountType})");

            // Reinstall webhooks
            $result = $setupService->reinstallWebhooks($account, $accountType);

            $this->line('');

            if (!empty($result['created'])) {
                $this->info('✓ Webhooks reinstalled successfully!');
                $this->line('');
                $this->table(
                    ['Entity Type', 'Action', 'МойСклад ID'],
                    collect($result['created'])->map(function($webhook) {
                        return [
                            $webhook['entityType'] ?? 'N/A',
                            $webhook['action'] ?? 'N/A',
                            $webhook['id'] ?? 'N/A',
                        ];
                    })->toArray()
                );

                $this->line('');
                $this->info("Total webhooks created: " . count($result['created']));
            }

            if (!empty($result['errors'])) {
                $this->line('');
                $this->warn("⚠ Some webhooks failed to install:");
                foreach ($result['errors'] as $error) {
                    $this->line("  ✗ {$error['entity']} ({$error['action']}): {$error['error']}");
                }
            }

            // Run health check
            $this->line('');
            $this->info('Running post-reinstall health check...');
            $healthRecords = $setupService->checkWebhookHealth($accountId);

            $activeCount = collect($healthRecords)->where('is_active', true)->count();
            $totalCount = count($healthRecords);

            $this->info("✓ Health check: {$activeCount}/{$totalCount} webhooks active");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->line('');
            $this->error('✗ Webhook reinstallation failed!');
            $this->error($e->getMessage());
            $this->line('');

            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
