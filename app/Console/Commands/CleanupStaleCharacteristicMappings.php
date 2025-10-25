<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\CharacteristicMapping;
use App\Services\MoySkladService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan команда для очистки устаревших маппингов характеристик (characteristics)
 *
 * Проверяет существование child_characteristic_id в дочернем аккаунте и удаляет
 * маппинги для несуществующих характеристик. Это исправляет ошибки 10001
 * при попытке использовать несуществующую характеристику в модификации.
 */
class CleanupStaleCharacteristicMappings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:cleanup-stale-characteristic-mappings
                            {--child-account= : Check only specific child account ID}
                            {--dry-run : Show what would be cleaned without actually doing it}
                            {--limit=500 : Limit number of mappings to check per account}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup stale characteristic mappings (10001 errors - characteristic not found)';

    /**
     * Execute the console command.
     */
    public function handle(MoySkladService $moysklad): int
    {
        $isDryRun = $this->option('dry-run');
        $childAccountFilter = $this->option('child-account');
        $limit = (int) $this->option('limit');

        $this->info('Searching for stale characteristic mappings...');
        $this->newLine();

        // Построить запрос для поиска characteristic маппингов
        $query = CharacteristicMapping::query();

        if ($childAccountFilter) {
            $query->where('child_account_id', $childAccountFilter);
        }

        // Сгруппировать по child_account_id для оптимизации запросов
        $mappingsByAccount = $query->limit($limit)
            ->get()
            ->groupBy('child_account_id');

        if ($mappingsByAccount->isEmpty()) {
            $this->info('✓ No characteristic mappings found.');
            return Command::SUCCESS;
        }

        $totalChecked = 0;
        $totalStale = 0;
        $totalErrors = 0;
        $staleDetails = [];

        // Проверить каждый дочерний аккаунт
        foreach ($mappingsByAccount as $childAccountId => $mappings) {
            $this->info("Checking {$mappings->count()} characteristic mapping(s) for child account: " . substr($childAccountId, 0, 8) . '...');

            // Получить child account
            $childAccount = Account::where('account_id', $childAccountId)->first();

            if (!$childAccount) {
                $this->warn("  ⚠ Child account not found, skipping");
                continue;
            }

            $staleCount = 0;
            $errorCount = 0;

            try {
                // Получить все характеристики из child account одним запросом
                $response = $moysklad->setAccessToken($childAccount->access_token)
                    ->get('/entity/product/metadata/characteristics', ['limit' => 1000]);

                $existingChars = $response['data']['rows'] ?? [];
                $existingCharIds = array_column($existingChars, 'id');

                $this->line("  Found " . count($existingChars) . " existing characteristics in child account");

                // Проверить каждый маппинг
                foreach ($mappings as $mapping) {
                    $totalChecked++;

                    // Проверить существует ли characteristic ID в child account
                    if (!in_array($mapping->child_characteristic_id, $existingCharIds)) {
                        // Stale mapping - характеристика не существует
                        $staleCount++;
                        $totalStale++;

                        $staleDetails[] = [
                            'id' => $mapping->id,
                            'child_account_id' => substr($childAccountId, 0, 8) . '...',
                            'characteristic_name' => $mapping->characteristic_name,
                            'child_char_id' => substr($mapping->child_characteristic_id, 0, 8) . '...',
                            'created_at' => $mapping->created_at->format('Y-m-d H:i:s')
                        ];

                        if (!$isDryRun) {
                            $mapping->delete();
                            Log::info('Deleted stale characteristic mapping', [
                                'mapping_id' => $mapping->id,
                                'child_account_id' => $childAccountId,
                                'characteristic_name' => $mapping->characteristic_name,
                                'child_characteristic_id' => $mapping->child_characteristic_id
                            ]);
                        }
                    }
                }

                if ($staleCount > 0) {
                    $this->warn("  Found {$staleCount} stale mapping(s)");
                } else {
                    $this->info("  ✓ All mappings are valid");
                }

            } catch (\Exception $e) {
                $errorCount++;
                $totalErrors++;
                $this->error("  ✗ Error checking account: " . $e->getMessage());

                Log::error('Failed to check characteristic mappings', [
                    'child_account_id' => $childAccountId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->newLine();
        $this->info("Checked: {$totalChecked} characteristic mapping(s)");
        $this->warn("Stale: {$totalStale} characteristic mapping(s)");

        if ($totalErrors > 0) {
            $this->warn("Errors: {$totalErrors} (account check failures)");
        }

        $this->newLine();

        if ($totalStale > 0) {
            // Показать примеры устаревших маппингов
            $samples = array_slice($staleDetails, 0, 10);
            $this->table(
                ['ID', 'Child Account', 'Characteristic Name', 'Child Char ID (Stale)', 'Created At'],
                $samples
            );

            if (count($staleDetails) > 10) {
                $this->line("... and " . (count($staleDetails) - 10) . " more");
            }

            $this->newLine();

            if ($isDryRun) {
                $this->info('DRY RUN: No changes made. Run without --dry-run to actually cleanup.');
            } else {
                $this->info("✓ Deleted {$totalStale} stale characteristic mapping(s)");
                $this->info('These mappings will be recreated on next variant sync.');
            }
        } else {
            $this->info('✓ No stale characteristic mappings found. All mappings are valid.');
        }

        return Command::SUCCESS;
    }
}
