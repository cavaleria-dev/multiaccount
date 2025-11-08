<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\EntityMapping;
use App\Services\MoySkladService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan команда для очистки устаревших маппингов товаров/услуг
 *
 * Проверяет существование child_entity_id в дочернем аккаунте и удаляет
 * маппинги для несуществующих сущностей. Это исправляет ошибки 404
 * при попытке обновить несуществующий товар/услугу.
 */
class CleanupStaleProductMappings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:cleanup-stale-product-mappings
                            {--child-account= : Check only specific child account ID}
                            {--entity-type=product : Entity type to check (product, service, variant, bundle)}
                            {--dry-run : Show what would be cleaned without actually doing it}
                            {--limit=500 : Limit number of mappings to check per account}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup stale entity mappings (404 errors - entity not found in child account)';

    /**
     * Execute the console command.
     */
    public function handle(MoySkladService $moysklad): int
    {
        $isDryRun = $this->option('dry-run');
        $childAccountFilter = $this->option('child-account');
        $entityType = $this->option('entity-type');
        $limit = (int) $this->option('limit');

        // Validate entity type
        $validTypes = ['product', 'service', 'variant', 'bundle'];
        if (!in_array($entityType, $validTypes)) {
            $this->error("Invalid entity type. Must be one of: " . implode(', ', $validTypes));
            return Command::FAILURE;
        }

        $this->info("Searching for stale {$entityType} mappings...");
        $this->newLine();

        // Query entity mappings
        $query = EntityMapping::where('entity_type', $entityType)
            ->where('sync_direction', 'main_to_child');

        if ($childAccountFilter) {
            $query->where('child_account_id', $childAccountFilter);
        }

        // Group by child account
        $mappingsByAccount = $query->limit($limit)
            ->get()
            ->groupBy('child_account_id');

        if ($mappingsByAccount->isEmpty()) {
            $this->info("✓ No {$entityType} mappings found.");
            return Command::SUCCESS;
        }

        $totalChecked = 0;
        $totalStale = 0;
        $totalErrors = 0;
        $staleDetails = [];

        // Check each child account
        foreach ($mappingsByAccount as $childAccountId => $mappings) {
            $this->info("Checking {$mappings->count()} {$entityType} mapping(s) for child account: " . substr($childAccountId, 0, 8) . '...');

            $childAccount = Account::where('account_id', $childAccountId)->first();

            if (!$childAccount) {
                $this->warn("  ⚠ Child account not found, skipping");
                continue;
            }

            $staleCount = 0;
            $errorCount = 0;

            foreach ($mappings as $mapping) {
                $totalChecked++;

                try {
                    // Try to fetch entity from child account
                    $response = $moysklad->setAccessToken($childAccount->access_token)
                        ->get("entity/{$entityType}/{$mapping->child_entity_id}");

                    // Entity exists - mapping is valid
                    // $this->line("  ✓ {$mapping->child_entity_id}: exists");

                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();

                    // 404 = entity not found (stale mapping)
                    if (str_contains($errorMessage, '404') || str_contains($errorMessage, 'Not Found')) {
                        $staleCount++;
                        $totalStale++;

                        $staleDetails[] = [
                            'id' => $mapping->id,
                            'child_account_id' => substr($childAccountId, 0, 8) . '...',
                            'parent_entity_id' => substr($mapping->parent_entity_id, 0, 8) . '...',
                            'child_entity_id' => substr($mapping->child_entity_id, 0, 8) . '...',
                            'match_field' => $mapping->match_field ?? 'N/A',
                            'match_value' => $mapping->match_value ?? 'N/A',
                            'created_at' => $mapping->created_at->format('Y-m-d H:i:s')
                        ];

                        if (!$isDryRun) {
                            $mapping->delete();
                            Log::info("Deleted stale {$entityType} mapping", [
                                'mapping_id' => $mapping->id,
                                'child_account_id' => $childAccountId,
                                'parent_entity_id' => $mapping->parent_entity_id,
                                'child_entity_id' => $mapping->child_entity_id
                            ]);
                        }
                    } else {
                        // Other API error (rate limit, network, etc.)
                        $errorCount++;
                        $totalErrors++;
                        $this->warn("  ⚠ API error checking {$mapping->child_entity_id}: " . substr($errorMessage, 0, 50));
                    }
                }

                // Avoid rate limits - 100ms delay between API checks
                usleep(100000);
            }

            if ($staleCount > 0) {
                $this->warn("  Found {$staleCount} stale mapping(s)");
            } else {
                $this->info("  ✓ All mappings are valid");
            }
        }

        $this->newLine();
        $this->info("Checked: {$totalChecked} {$entityType} mapping(s)");
        $this->warn("Stale: {$totalStale} {$entityType} mapping(s)");

        if ($totalErrors > 0) {
            $this->warn("Errors: {$totalErrors} (API check failures)");
        }

        $this->newLine();

        if ($totalStale > 0) {
            // Show samples
            $samples = array_slice($staleDetails, 0, 10);
            $this->table(
                ['ID', 'Child Account', 'Parent Entity', 'Child Entity (Stale)', 'Match Field', 'Match Value', 'Created At'],
                $samples
            );

            if (count($staleDetails) > 10) {
                $this->line("... and " . (count($staleDetails) - 10) . " more");
            }

            $this->newLine();

            if ($isDryRun) {
                $this->info('DRY RUN: No changes made. Run without --dry-run to actually cleanup.');
            } else {
                $this->info("✓ Deleted {$totalStale} stale {$entityType} mapping(s)");
                $this->info("These mappings will be recreated on next sync (entity will be created in child account).");
            }
        } else {
            $this->info("✓ No stale {$entityType} mappings found. All mappings are valid.");
        }

        return Command::SUCCESS;
    }
}
