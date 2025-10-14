<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для массовой синхронизации с очередями
 */
class BatchSyncService
{
    protected MoySkladService $moySkladService;
    protected ProductSyncService $productSyncService;

    public function __construct(
        MoySkladService $moySkladService,
        ProductSyncService $productSyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->productSyncService = $productSyncService;
    }

    /**
     * Массовая синхронизация товара на все дочерние аккаунты
     * Использует очередь для избежания rate limits
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $productId UUID товара
     * @return int Количество добавленных задач в очередь
     */
    public function batchSyncProduct(string $mainAccountId, string $productId): int
    {
        try {
            // Получить все активные дочерние аккаунты
            $childAccounts = DB::table('child_accounts')
                ->join('sync_settings', 'child_accounts.child_account_id', '=', 'sync_settings.account_id')
                ->where('child_accounts.parent_account_id', $mainAccountId)
                ->where('child_accounts.status', 'active')
                ->where('sync_settings.sync_products', true)
                ->orderBy('sync_settings.sync_priority', 'desc')
                ->select('child_accounts.*', 'sync_settings.sync_delay_seconds', 'sync_settings.sync_priority')
                ->get();

            Log::info('Batch sync product started', [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'child_accounts_count' => $childAccounts->count()
            ]);

            // Распределить задачи с задержками
            $baseDelay = 0;
            $queuedCount = 0;

            foreach ($childAccounts as $index => $childAccount) {
                // Вычислить scheduled_at с учетом приоритета и задержки
                $delay = $baseDelay + ($childAccount->sync_delay_seconds ?? 0);
                $scheduledAt = now()->addSeconds($delay);

                // Добавить в очередь
                SyncQueue::create([
                    'account_id' => $childAccount->child_account_id,
                    'entity_type' => 'product',
                    'entity_id' => $productId,
                    'operation' => 'sync',
                    'priority' => $childAccount->sync_priority ?? 5,
                    'status' => 'pending',
                    'payload' => [
                        'main_account_id' => $mainAccountId,
                        'product_id' => $productId
                    ],
                    'scheduled_at' => $scheduledAt,
                ]);

                $queuedCount++;

                // Увеличить базовую задержку для следующих (распределить нагрузку)
                // Примерно 100-200ms на франшизу
                $baseDelay += 0.15; // seconds
            }

            Log::info('Batch sync product queued', [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'queued_count' => $queuedCount
            ]);

            return $queuedCount;

        } catch (\Exception $e) {
            Log::error('Batch sync product failed', [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Массовая синхронизация модификации (variant)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $variantId UUID модификации
     * @return int Количество добавленных задач
     */
    public function batchSyncVariant(string $mainAccountId, string $variantId): int
    {
        try {
            $childAccounts = DB::table('child_accounts')
                ->join('sync_settings', 'child_accounts.child_account_id', '=', 'sync_settings.account_id')
                ->where('child_accounts.parent_account_id', $mainAccountId)
                ->where('child_accounts.status', 'active')
                ->where('sync_settings.sync_variants', true)
                ->orderBy('sync_settings.sync_priority', 'desc')
                ->select('child_accounts.*', 'sync_settings.sync_delay_seconds', 'sync_settings.sync_priority')
                ->get();

            $baseDelay = 0;
            $queuedCount = 0;

            foreach ($childAccounts as $childAccount) {
                $delay = $baseDelay + ($childAccount->sync_delay_seconds ?? 0);

                SyncQueue::create([
                    'account_id' => $childAccount->child_account_id,
                    'entity_type' => 'variant',
                    'entity_id' => $variantId,
                    'operation' => 'sync',
                    'priority' => $childAccount->sync_priority ?? 5,
                    'status' => 'pending',
                    'payload' => [
                        'main_account_id' => $mainAccountId,
                        'variant_id' => $variantId
                    ],
                    'scheduled_at' => now()->addSeconds($delay),
                ]);

                $queuedCount++;
                $baseDelay += 0.15;
            }

            Log::info('Batch sync variant queued', [
                'main_account_id' => $mainAccountId,
                'variant_id' => $variantId,
                'queued_count' => $queuedCount
            ]);

            return $queuedCount;

        } catch (\Exception $e) {
            Log::error('Batch sync variant failed', [
                'main_account_id' => $mainAccountId,
                'variant_id' => $variantId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Массовая синхронизация комплекта (bundle)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $bundleId UUID комплекта
     * @return int Количество добавленных задач
     */
    public function batchSyncBundle(string $mainAccountId, string $bundleId): int
    {
        try {
            $childAccounts = DB::table('child_accounts')
                ->join('sync_settings', 'child_accounts.child_account_id', '=', 'sync_settings.account_id')
                ->where('child_accounts.parent_account_id', $mainAccountId)
                ->where('child_accounts.status', 'active')
                ->where('sync_settings.sync_bundles', true)
                ->orderBy('sync_settings.sync_priority', 'desc')
                ->select('child_accounts.*', 'sync_settings.sync_delay_seconds', 'sync_settings.sync_priority')
                ->get();

            $baseDelay = 0;
            $queuedCount = 0;

            foreach ($childAccounts as $childAccount) {
                $delay = $baseDelay + ($childAccount->sync_delay_seconds ?? 0);

                SyncQueue::create([
                    'account_id' => $childAccount->child_account_id,
                    'entity_type' => 'bundle',
                    'entity_id' => $bundleId,
                    'operation' => 'sync',
                    'priority' => $childAccount->sync_priority ?? 5,
                    'status' => 'pending',
                    'payload' => [
                        'main_account_id' => $mainAccountId,
                        'bundle_id' => $bundleId
                    ],
                    'scheduled_at' => now()->addSeconds($delay),
                ]);

                $queuedCount++;
                $baseDelay += 0.15;
            }

            Log::info('Batch sync bundle queued', [
                'main_account_id' => $mainAccountId,
                'bundle_id' => $bundleId,
                'queued_count' => $queuedCount
            ]);

            return $queuedCount;

        } catch (\Exception $e) {
            Log::error('Batch sync bundle failed', [
                'main_account_id' => $mainAccountId,
                'bundle_id' => $bundleId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Удалить товар из всех дочерних аккаунтов
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $productId UUID товара
     * @return int Количество добавленных задач
     */
    public function batchDeleteProduct(string $mainAccountId, string $productId): int
    {
        try {
            $childAccounts = DB::table('child_accounts')
                ->where('parent_account_id', $mainAccountId)
                ->where('status', 'active')
                ->get();

            $queuedCount = 0;

            foreach ($childAccounts as $childAccount) {
                SyncQueue::create([
                    'account_id' => $childAccount->child_account_id,
                    'entity_type' => 'product',
                    'entity_id' => $productId,
                    'operation' => 'delete',
                    'priority' => 3,
                    'status' => 'pending',
                    'payload' => [
                        'main_account_id' => $mainAccountId,
                        'product_id' => $productId
                    ],
                    'scheduled_at' => now(),
                ]);

                $queuedCount++;
            }

            Log::info('Batch delete product queued', [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'queued_count' => $queuedCount
            ]);

            return $queuedCount;

        } catch (\Exception $e) {
            Log::error('Batch delete product failed', [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Массовая проверка вебхуков для всех аккаунтов
     *
     * @param string|null $parentAccountId UUID родительского аккаунта (null = все)
     * @return int Количество добавленных задач
     */
    public function batchCheckWebhooks(?string $parentAccountId = null): int
    {
        try {
            $query = DB::table('accounts');

            if ($parentAccountId) {
                $query->where('account_id', $parentAccountId)
                      ->orWhereIn('account_id', function($q) use ($parentAccountId) {
                          $q->select('child_account_id')
                            ->from('child_accounts')
                            ->where('parent_account_id', $parentAccountId);
                      });
            }

            $accounts = $query->where('status', 'activated')->get();
            $queuedCount = 0;

            foreach ($accounts as $index => $account) {
                // Добавить в очередь проверки (низкий приоритет)
                SyncQueue::create([
                    'account_id' => $account->account_id,
                    'entity_type' => 'webhook',
                    'entity_id' => 'health_check',
                    'operation' => 'check',
                    'priority' => 1, // Низкий приоритет
                    'status' => 'pending',
                    'scheduled_at' => now()->addMinutes(rand(1, 15)), // Распределить по времени
                ]);

                $queuedCount++;
            }

            Log::info('Batch check webhooks queued', [
                'parent_account_id' => $parentAccountId,
                'queued_count' => $queuedCount
            ]);

            return $queuedCount;

        } catch (\Exception $e) {
            Log::error('Batch check webhooks failed', [
                'parent_account_id' => $parentAccountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить статистику очереди
     *
     * @param string|null $accountId UUID аккаунта
     * @return array Статистика очереди
     */
    public function getQueueStats(?string $accountId = null): array
    {
        $query = SyncQueue::query();

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        $stats = [
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'processing' => (clone $query)->where('status', 'processing')->count(),
            'completed' => (clone $query)->where('status', 'completed')->whereDate('completed_at', today())->count(),
            'failed' => (clone $query)->where('status', 'failed')->whereDate('updated_at', today())->count(),
            'total' => $query->count(),
        ];

        return $stats;
    }

    /**
     * Очистить старые выполненные задачи
     *
     * @param int $days Количество дней (по умолчанию 7)
     * @return int Количество удаленных задач
     */
    public function cleanupCompletedTasks(int $days = 7): int
    {
        $deleted = SyncQueue::where('status', 'completed')
            ->where('completed_at', '<', now()->subDays($days))
            ->delete();

        Log::info('Cleanup completed tasks', [
            'days' => $days,
            'deleted_count' => $deleted
        ]);

        return $deleted;
    }
}
