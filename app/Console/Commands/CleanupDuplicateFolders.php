<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\EntityMapping;
use App\Services\MoySkladService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan команда для поиска и очистки дублей групп товаров (productFolder)
 *
 * Находит группы с одинаковым именем и родителем в одном child account,
 * переносит товары из дублей в основную группу и удаляет пустые дубли.
 */
class CleanupDuplicateFolders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:cleanup-duplicate-folders
                            {--child-account= : Check only specific child account ID}
                            {--dry-run : Show what would be cleaned without actually doing it}
                            {--fix : Move products from duplicates to main folder and delete empty duplicates}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and cleanup duplicate product folders in child accounts';

    /**
     * Execute the console command.
     */
    public function handle(MoySkladService $moysklad): int
    {
        $isDryRun = $this->option('dry-run');
        $shouldFix = $this->option('fix');
        $childAccountFilter = $this->option('child-account');

        if (!$isDryRun && !$shouldFix) {
            $this->error('Please specify either --dry-run or --fix option');
            return Command::FAILURE;
        }

        $this->info('Searching for duplicate product folders...');
        $this->newLine();

        // Получить список child accounts
        $query = Account::whereNotNull('parent_account_id');

        if ($childAccountFilter) {
            $query->where('account_id', $childAccountFilter);
        }

        $childAccounts = $query->get();

        if ($childAccounts->isEmpty()) {
            $this->info('✓ No child accounts found.');
            return Command::SUCCESS;
        }

        $totalDuplicates = 0;
        $totalFoldersProcessed = 0;
        $totalProductsMoved = 0;
        $totalFoldersDeleted = 0;

        foreach ($childAccounts as $childAccount) {
            $this->info("Checking child account: {$childAccount->account_name} ({$childAccount->account_id})");

            try {
                // Загрузить все папки из child account
                $foldersResponse = $moysklad->setAccessToken($childAccount->access_token)
                    ->get('/entity/productfolder', ['limit' => 1000, 'expand' => 'productFolder']);

                $folders = $foldersResponse['data']['rows'] ?? [];

                if (empty($folders)) {
                    $this->line("  No folders found");
                    continue;
                }

                $this->line("  Found " . count($folders) . " folders");

                // Сгруппировать папки по имени и родителю
                $folderGroups = [];
                foreach ($folders as $folder) {
                    $parentId = isset($folder['productFolder']['id']) ? $folder['productFolder']['id'] : 'root';
                    $key = $parentId . '::' . $folder['name'];

                    if (!isset($folderGroups[$key])) {
                        $folderGroups[$key] = [];
                    }

                    $folderGroups[$key][] = $folder;
                }

                // Найти дубликаты (группы с больше чем 1 папкой)
                $duplicates = array_filter($folderGroups, fn($group) => count($group) > 1);

                if (empty($duplicates)) {
                    $this->line("  ✓ No duplicates found");
                    continue;
                }

                $this->warn("  ⚠ Found " . count($duplicates) . " duplicate folder group(s)");
                $this->newLine();

                // Обработать каждую группу дублей
                foreach ($duplicates as $key => $group) {
                    $totalDuplicates++;

                    [$parentId, $folderName] = explode('::', $key, 2);

                    $this->line("  Duplicate: '{$folderName}' (parent: " . ($parentId === 'root' ? 'ROOT' : substr($parentId, 0, 8)) . ')');
                    $this->line("  Found {count($group)} instances:");

                    // Определить "основную" папку (та, что в маппинге или первая по алфавиту)
                    $mainFolder = $this->selectMainFolder($group, $childAccount->account_id);
                    $duplicateFolders = array_filter($group, fn($f) => $f['id'] !== $mainFolder['id']);

                    // Показать информацию о каждой папке и товарах в ней
                    foreach ($group as $folder) {
                        $isMain = ($folder['id'] === $mainFolder['id']);

                        // Подсчитать товары/услуги в папке
                        $productsCount = $this->countEntitiesInFolder($moysklad, $childAccount, $folder['id']);

                        $marker = $isMain ? ' (MAIN)' : '';
                        $this->line("    - ID: " . substr($folder['id'], 0, 8) . "... | Products: {$productsCount}{$marker}");
                    }

                    // Если --fix указан, переместить товары и удалить дубли
                    if ($shouldFix && !$isDryRun) {
                        $this->newLine();
                        $this->line("  Fixing duplicates...");

                        foreach ($duplicateFolders as $duplicateFolder) {
                            // Переместить товары из дубля в основную папку
                            $movedCount = $this->moveProductsToFolder(
                                $moysklad,
                                $childAccount,
                                $duplicateFolder['id'],
                                $mainFolder['id']
                            );

                            $totalProductsMoved += $movedCount;

                            if ($movedCount > 0) {
                                $this->info("    ✓ Moved {$movedCount} products from duplicate to main folder");
                            }

                            // Удалить пустой дубль
                            try {
                                $moysklad->setAccessToken($childAccount->access_token)
                                    ->delete("/entity/productfolder/{$duplicateFolder['id']}");

                                $totalFoldersDeleted++;
                                $this->info("    ✓ Deleted empty duplicate folder: " . substr($duplicateFolder['id'], 0, 8));

                            } catch (\Exception $e) {
                                $this->error("    ✗ Failed to delete duplicate folder: {$e->getMessage()}");
                            }
                        }

                        $totalFoldersProcessed++;
                    }

                    $this->newLine();
                }

            } catch (\Exception $e) {
                $this->error("  ✗ Error processing account: {$e->getMessage()}");
                Log::error('Failed to check duplicates for child account', [
                    'account_id' => $childAccount->account_id,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        // Итоговая статистика
        $this->newLine();
        $this->info('=== Summary ===');
        $this->line("Duplicate folder groups found: {$totalDuplicates}");

        if ($shouldFix && !$isDryRun) {
            $this->line("Folder groups processed: {$totalFoldersProcessed}");
            $this->line("Products moved: {$totalProductsMoved}");
            $this->line("Duplicate folders deleted: {$totalFoldersDeleted}");
        } else {
            $this->warn("No changes made (dry-run mode). Use --fix to apply changes.");
        }

        return Command::SUCCESS;
    }

    /**
     * Выбрать "основную" папку из группы дублей
     */
    protected function selectMainFolder(array $folders, string $childAccountId): array
    {
        // 1. Проверить есть ли папка с маппингом
        foreach ($folders as $folder) {
            $hasMapping = EntityMapping::where('child_account_id', $childAccountId)
                ->where('entity_type', 'productfolder')
                ->where('child_entity_id', $folder['id'])
                ->exists();

            if ($hasMapping) {
                return $folder; // Папка с маппингом = основная
            }
        }

        // 2. Если маппинга нет - выбрать первую по ID (стабильная сортировка)
        usort($folders, fn($a, $b) => strcmp($a['id'], $b['id']));
        return $folders[0];
    }

    /**
     * Подсчитать количество товаров/услуг/комплектов в папке
     */
    protected function countEntitiesInFolder(MoySkladService $moysklad, Account $account, string $folderId): int
    {
        try {
            $count = 0;

            // Подсчитать товары
            $productsResponse = $moysklad->setAccessToken($account->access_token)
                ->get("/entity/product", [
                    'filter' => "productFolder=https://api.moysklad.ru/api/remap/1.2/entity/productfolder/{$folderId}",
                    'limit' => 1
                ]);
            $count += $productsResponse['data']['meta']['size'] ?? 0;

            // Подсчитать услуги
            $servicesResponse = $moysklad->setAccessToken($account->access_token)
                ->get("/entity/service", [
                    'filter' => "productFolder=https://api.moysklad.ru/api/remap/1.2/entity/productfolder/{$folderId}",
                    'limit' => 1
                ]);
            $count += $servicesResponse['data']['meta']['size'] ?? 0;

            // Подсчитать комплекты
            $bundlesResponse = $moysklad->setAccessToken($account->access_token)
                ->get("/entity/bundle", [
                    'filter' => "productFolder=https://api.moysklad.ru/api/remap/1.2/entity/productfolder/{$folderId}",
                    'limit' => 1
                ]);
            $count += $bundlesResponse['data']['meta']['size'] ?? 0;

            return $count;

        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Переместить все товары/услуги/комплекты из одной папки в другую
     */
    protected function moveProductsToFolder(
        MoySkladService $moysklad,
        Account $account,
        string $fromFolderId,
        string $toFolderId
    ): int {
        $movedCount = 0;

        try {
            $entityTypes = ['product', 'service', 'bundle'];

            foreach ($entityTypes as $entityType) {
                // Загрузить все сущности из исходной папки
                $response = $moysklad->setAccessToken($account->access_token)
                    ->get("/entity/{$entityType}", [
                        'filter' => "productFolder=https://api.moysklad.ru/api/remap/1.2/entity/productfolder/{$fromFolderId}",
                        'limit' => 1000
                    ]);

                $entities = $response['data']['rows'] ?? [];

                if (empty($entities)) {
                    continue;
                }

                // Переместить каждую сущность
                foreach ($entities as $entity) {
                    try {
                        $moysklad->setAccessToken($account->access_token)
                            ->put("/entity/{$entityType}/{$entity['id']}", [
                                'productFolder' => [
                                    'meta' => [
                                        'href' => "https://api.moysklad.ru/api/remap/1.2/entity/productfolder/{$toFolderId}",
                                        'type' => 'productfolder',
                                        'mediaType' => 'application/json'
                                    ]
                                ]
                            ]);

                        $movedCount++;

                    } catch (\Exception $e) {
                        Log::error("Failed to move {$entityType}", [
                            'entity_id' => $entity['id'],
                            'from_folder' => $fromFolderId,
                            'to_folder' => $toFolderId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to move products', [
                'from_folder' => $fromFolderId,
                'to_folder' => $toFolderId,
                'error' => $e->getMessage()
            ]);
        }

        return $movedCount;
    }
}
