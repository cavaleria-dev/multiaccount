<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Artisan команда для очистки старых временных файлов изображений
 *
 * Удаляет временные файлы изображений старше заданного количества часов
 * Должна запускаться по расписанию (например, ежедневно)
 */
class CleanupTempImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:cleanup-temp-images
                            {--hours=24 : Delete files older than this many hours}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup old temporary image files from storage/temp_images';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $isDryRun = $this->option('dry-run');

        $this->info("Searching for temporary image files older than {$hours} hours...");
        $this->newLine();

        $tempPath = 'temp_images';

        // Проверить, что директория существует
        if (!Storage::exists($tempPath)) {
            $this->info('✓ No temp_images directory found. Nothing to cleanup.');
            return Command::SUCCESS;
        }

        // Получить все файлы
        $allFiles = Storage::files($tempPath);

        if (empty($allFiles)) {
            $this->info('✓ No temporary image files found. Directory is clean.');
            return Command::SUCCESS;
        }

        $this->info("Found " . count($allFiles) . " file(s) in {$tempPath}");

        // Фильтровать файлы по времени последнего изменения
        $cutoffTime = now()->subHours($hours)->timestamp;
        $filesToDelete = [];
        $totalSize = 0;

        foreach ($allFiles as $file) {
            $lastModified = Storage::lastModified($file);

            if ($lastModified < $cutoffTime) {
                $size = Storage::size($file);
                $filesToDelete[] = [
                    'path' => $file,
                    'size' => $size,
                    'age_hours' => round((time() - $lastModified) / 3600, 1),
                    'modified' => date('Y-m-d H:i:s', $lastModified)
                ];
                $totalSize += $size;
            }
        }

        if (empty($filesToDelete)) {
            $this->info("✓ No files older than {$hours} hours found. Nothing to cleanup.");
            return Command::SUCCESS;
        }

        $count = count($filesToDelete);
        $totalSizeMB = round($totalSize / 1024 / 1024, 2);

        $this->warn("Found {$count} file(s) to delete (total size: {$totalSizeMB} MB)");
        $this->newLine();

        // Показать примеры (до 10 файлов)
        $samples = array_slice($filesToDelete, 0, 10);

        $this->table(
            ['File', 'Size (KB)', 'Age (hours)', 'Modified'],
            array_map(function($file) {
                return [
                    basename($file['path']),
                    round($file['size'] / 1024, 2),
                    $file['age_hours'],
                    $file['modified']
                ];
            }, $samples)
        );

        if ($count > 10) {
            $this->line("... and " . ($count - 10) . " more");
        }

        $this->newLine();

        if ($isDryRun) {
            $this->info('DRY RUN: No files deleted. Run without --dry-run to actually delete.');
            return Command::SUCCESS;
        }

        if (!$this->confirm("Delete these {$count} file(s)?", true)) {
            $this->info('Cleanup cancelled.');
            return Command::SUCCESS;
        }

        // Удалить файлы
        $deletedCount = 0;
        $failedCount = 0;

        $progressBar = $this->output->createProgressBar($count);
        $progressBar->start();

        foreach ($filesToDelete as $file) {
            try {
                if (Storage::delete($file['path'])) {
                    $deletedCount++;
                } else {
                    $failedCount++;
                    Log::warning('Failed to delete temp image file', [
                        'file' => $file['path']
                    ]);
                }
            } catch (\Exception $e) {
                $failedCount++;
                Log::error('Error deleting temp image file', [
                    'file' => $file['path'],
                    'error' => $e->getMessage()
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($deletedCount > 0) {
            $this->info("✓ Deleted {$deletedCount} file(s) (freed {$totalSizeMB} MB)");
        }

        if ($failedCount > 0) {
            $this->warn("! Failed to delete {$failedCount} file(s). Check logs for details.");
        }

        Log::info('Temp images cleanup completed', [
            'hours' => $hours,
            'total_files' => count($allFiles),
            'files_to_delete' => $count,
            'deleted' => $deletedCount,
            'failed' => $failedCount,
            'freed_mb' => $totalSizeMB
        ]);

        return Command::SUCCESS;
    }
}
