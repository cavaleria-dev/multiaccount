<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Artisan команда для шифрования plaintext access_tokens в БД
 *
 * Используется для экстренной проверки и шифрования незашифрованных токенов
 * которые могли попасть в БД из-за использования DB::table() вместо Account модели.
 *
 * ВАЖНО: Эта команда использует DB::table() напрямую, так как Account модель
 * с encrypted cast не сможет прочитать plaintext токены без ошибки.
 */
class EncryptAccessTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounts:encrypt-tokens
                            {--dry-run : Show what would be encrypted without actually doing it}
                            {--account= : Encrypt only specific account ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Encrypt plaintext access_tokens in accounts table';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $accountIdFilter = $this->option('account');

        $this->info('Checking for plaintext access_tokens...');
        $this->newLine();

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Use raw DB query to avoid Model casting (which would fail on plaintext tokens)
        $query = DB::table('accounts')
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '');

        if ($accountIdFilter) {
            $query->where('account_id', $accountIdFilter);
            $this->info("Filtering by account ID: {$accountIdFilter}");
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->info('✓ No accounts with access_tokens found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$accounts->count()} account(s) with access_token");
        $this->newLine();

        $encrypted = 0;
        $alreadyEncrypted = 0;
        $errors = 0;

        foreach ($accounts as $account) {
            try {
                $token = $account->access_token;
                $accountIdShort = substr($account->account_id, 0, 8) . '...';

                // Check if already encrypted
                // Encrypted strings start with "eyJpdiI6" (base64 of {"iv":"...)
                if (str_starts_with($token, 'eyJpdiI6')) {
                    $this->line("  {$accountIdShort}: <fg=yellow>Already encrypted</>, skipping");
                    $alreadyEncrypted++;
                    continue;
                }

                // Token looks like plaintext, try to encrypt it
                $this->line("  {$accountIdShort}: <fg=cyan>Plaintext detected</>, encrypting...");

                if (!$isDryRun) {
                    // Encrypt the plaintext token
                    $encryptedToken = Crypt::encryptString($token);

                    // Update using raw query (bypass Model to avoid double encryption)
                    DB::table('accounts')
                        ->where('id', $account->id)
                        ->update(['access_token' => $encryptedToken]);

                    $this->line("  {$accountIdShort}: <fg=green>✓ Encrypted successfully</>");

                    Log::info('Access token encrypted', [
                        'account_id' => $account->account_id,
                        'command' => 'accounts:encrypt-tokens'
                    ]);
                } else {
                    $this->line("  {$accountIdShort}: <fg=gray>Would encrypt (dry-run)</>");
                }

                $encrypted++;

            } catch (\Exception $e) {
                $accountIdShort = substr($account->account_id ?? 'unknown', 0, 8) . '...';
                $this->error("  {$accountIdShort}: ✗ FAILED - {$e->getMessage()}");
                $errors++;

                Log::error('Failed to encrypt access token', [
                    'account_id' => $account->account_id ?? null,
                    'error' => $e->getMessage(),
                    'command' => 'accounts:encrypt-tokens'
                ]);
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->line("  Total accounts checked: <fg=white>{$accounts->count()}</>");
        $this->line("  Already encrypted: <fg=yellow>{$alreadyEncrypted}</>");

        if ($isDryRun) {
            $this->line("  Would encrypt: <fg=gray>{$encrypted}</>");
        } else {
            $this->line("  Newly encrypted: <fg=green>{$encrypted}</>");
        }

        if ($errors > 0) {
            $this->line("  Errors: <fg=red>{$errors}</>");
        }

        $this->newLine();

        if ($isDryRun && $encrypted > 0) {
            $this->info('DRY RUN: No changes made. Run without --dry-run to actually encrypt.');
        } elseif (!$isDryRun && $encrypted > 0) {
            $this->info("✓ Successfully encrypted {$encrypted} access_token(s)");
            $this->info('All new tokens will be automatically encrypted by Account model.');
        } elseif ($alreadyEncrypted > 0 && $encrypted === 0) {
            $this->info('✓ All access_tokens are already encrypted. No action needed.');
        }

        if ($errors > 0) {
            $this->warn("⚠ {$errors} error(s) occurred. Check logs for details.");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
