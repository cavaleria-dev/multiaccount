<?php

namespace App\Console\Commands;

use App\Models\SyncQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Artisan команда для очистки некорректных задач синхронизации
 *
 * Помечает как failed все задачи с отсутствующим или некорректным payload
 */
class CleanupInvalidSyncTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:cleanup-invalid-tasks
                            {--dry-run : Show what would be cleaned without actually doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup invalid sync tasks (missing main_account_id in payload)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        $this->info('Searching for invalid sync tasks...');
        $this->newLine();

        // Найти задачи для product, variant, bundle, service где payload некорректный
        $query = SyncQueue::whereIn('status', ['pending', 'processing'])
            ->whereIn('entity_type', ['product', 'variant', 'bundle', 'service'])
            ->where(function($q) {
                $q->whereNull('payload')
                  ->orWhereRaw("payload IS NOT NULL AND (payload->>'main_account_id') IS NULL");
            });

        $count = $query->count();

        if ($count === 0) {
            $this->info('✓ No invalid tasks found. All tasks have valid payload.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$count} invalid task(s)");
        $this->newLine();

        // Показать примеры
        $samples = $query->limit(5)->get(['id', 'account_id', 'entity_type', 'entity_id', 'payload', 'created_at']);

        $this->table(
            ['ID', 'Account ID', 'Entity Type', 'Entity ID', 'Payload', 'Created At'],
            $samples->map(function($task) {
                return [
                    $task->id,
                    substr($task->account_id, 0, 8) . '...',
                    $task->entity_type,
                    substr($task->entity_id, 0, 8) . '...',
                    $task->payload ? json_encode($task->payload) : 'NULL',
                    $task->created_at->format('Y-m-d H:i:s')
                ];
            })
        );

        if ($count > 5) {
            $this->line("... and " . ($count - 5) . " more");
        }

        $this->newLine();

        if ($isDryRun) {
            $this->info('DRY RUN: No changes made. Run without --dry-run to actually cleanup.');
            return Command::SUCCESS;
        }

        if (!$this->confirm("Mark these {$count} task(s) as failed?", true)) {
            $this->info('Cleanup cancelled.');
            return Command::SUCCESS;
        }

        // Пометить задачи как failed
        $updated = $query->update([
            'status' => 'failed',
            'error' => 'Invalid payload: missing main_account_id (cleaned up automatically)',
            'completed_at' => now()
        ]);

        $this->info("✓ Marked {$updated} task(s) as failed");
        $this->newLine();
        $this->info('Done! You can now create new sync tasks with correct payload.');

        return Command::SUCCESS;
    }
}
