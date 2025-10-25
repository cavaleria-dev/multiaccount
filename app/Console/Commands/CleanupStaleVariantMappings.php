<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\EntityMapping;
use App\Services\MoySkladService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan команда для очистки устаревших маппингов модификаций (variants)
 *
 * Проверяет существование child_entity_id в дочернем аккаунте и удаляет
 * маппинги для несуществующих модификаций. Это исправляет ошибки 404
 * при попытке обновить несуществующие модификации.
 */
class CleanupStaleVariantMappings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:cleanup-stale-variant-mappings
                            {--child-account= : Check only specific child account ID}
                            {--dry-run : Show what would be cleaned without actually doing it}
                            {--limit=100 : Limit number of mappings to check per account}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup stale variant mappings (404 Not Found in child accounts)';

    /**
     * Execute the console command.
     */
    public function handle(MoySkladService $moysklad): int
    {
        $isDryRun = $this->option('dry-run');
        $childAccountFilter = $this->option('child-account');
        $limit = (int) $this->option('limit');

        $this->info('Searching for stale variant mappings...');
        $this->newLine();

        // Построить запрос для поиска variant маппингов
        $query = EntityMapping::where('entity_type', 'variant')
            ->where('sync_direction', 'main_to_child');

        if ($childAccountFilter) {
            $query->where('child_account_id', $childAccountFilter);
        }

        // Сгруппировать по child_account_id для оптимизации запросов
        $mappingsByAccount = $query->limit($limit)
            ->get()
            ->groupBy('child_account_id');

        if ($mappingsByAccount->isEmpty()) {
            $this->info('✓ No variant mappings found.');
            return Command::SUCCESS;
        }

        $totalChecked = 0;
        $totalStale = 0;
        $totalErrors = 0;
        $staleDetails = [];

        // Проверить каждый дочерний аккаунт
        foreach ($mappingsByAccount as $childAccountId => $mappings) {
            $this->info("Checking {$mappings->count()} variant mapping(s) for child account: " . substr($childAccountId, 0, 8) . '...');

            // Получить child account
            $childAccount = Account::where('account_id', $childAccountId)->first();

            if (!$childAccount) {
                $this->warn("  ⚠ Child account not found, skipping");
                continue;
            }

            $staleCount = 0;
            $errorCount = 0;

            // Проверить каждый маппинг
            foreach ($mappings as $mapping) {
                $totalChecked++;

                try {
                    // Попытаться получить variant из child аккаунта
                    $moysklad->setAccessToken($childAccount->access_token)
                        ->get("entity/variant/{$mapping->child_entity_id}");

                    // Variant существует - маппинг валиден
                    // Ничего не делаем

                    // Задержка между запросами для избежания rate limits (100ms)
                    usleep(100000);

                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();

                    // Проверить, это 404 или другая ошибка
                    if (str_contains($errorMessage, '404') || str_contains($errorMessage, 'Not Found')) {
                        // Variant не существует - устаревший маппинг
                        $staleCount++;
                        $totalStale++;

                        $staleDetails[] = [
                            'id' => $mapping->id,
                            'child_account_id' => substr($childAccountId, 0, 8) . '...',
                            'parent_variant_id' => substr($mapping->parent_entity_id, 0, 8) . '...',
                            'child_variant_id' => substr($mapping->child_entity_id, 0, 8) . '...',
                            'created_at' => $mapping->created_at->format('Y-m-d H:i:s')
                        ];

                        if (!$isDryRun) {
                            $mapping->delete();
                            Log::info('Deleted stale variant mapping', [
                                'mapping_id' => $mapping->id,
                                'child_account_id' => $childAccountId,
                                'parent_variant_id' => $mapping->parent_entity_id,
                                'child_variant_id' => $mapping->child_entity_id
                            ]);
                        }
                    } elseif (str_contains($errorMessage, '502') || str_contains($errorMessage, '503') || str_contains($errorMessage, 'Bad Gateway') || str_contains($errorMessage, 'Service Unavailable')) {
                        // Временная ошибка сервера - пропускаем и продолжаем
                        $errorCount++;
                        $totalErrors++;
                        $this->warn("  ⚠ Temporary server error (502/503), skipping variant " . substr($mapping->child_entity_id, 0, 8) . '...');

                        // Небольшая задержка при ошибке сервера (500ms)
                        usleep(500000);
                    } else {
                        // Другая ошибка (rate limit, network, etc.) - пропускаем
                        $errorCount++;
                        $totalErrors++;
                        $this->warn("  ⚠ Error checking variant " . substr($mapping->child_entity_id, 0, 8) . "...: " . substr($errorMessage, 0, 100));

                        // Задержка при ошибке (300ms)
                        usleep(300000);
                    }
                }
            }

            if ($staleCount > 0) {
                $this->warn("  Found {$staleCount} stale mapping(s)");
            }
            if ($errorCount > 0) {
                $this->warn("  Encountered {$errorCount} error(s) (temporary server issues)");
            }
            if ($staleCount === 0 && $errorCount === 0) {
                $this->info("  ✓ All mappings are valid");
            }
        }

        $this->newLine();
        $this->info("Checked: {$totalChecked} variant mapping(s)");
        $this->warn("Stale: {$totalStale} variant mapping(s)");
        if ($totalErrors > 0) {
            $this->warn("Errors: {$totalErrors} (temporary server issues - 502/503)");
        }
        $this->newLine();

        if ($totalStale > 0) {
            // Показать примеры устаревших маппингов
            $samples = array_slice($staleDetails, 0, 10);
            $this->table(
                ['ID', 'Child Account', 'Parent Variant', 'Child Variant (404)', 'Created At'],
                $samples
            );

            if (count($staleDetails) > 10) {
                $this->line("... and " . (count($staleDetails) - 10) . " more");
            }

            $this->newLine();

            if ($isDryRun) {
                $this->info('DRY RUN: No changes made. Run without --dry-run to actually cleanup.');
            } else {
                $this->info("✓ Deleted {$totalStale} stale variant mapping(s)");
                $this->info('These mappings will be recreated on next variant sync.');
            }
        } else {
            $this->info('✓ No stale variant mappings found. All mappings are valid.');
        }

        return Command::SUCCESS;
    }
}
