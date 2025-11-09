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
    protected ServiceSyncService $serviceSyncService;
    protected BundleSyncService $bundleSyncService;

    public function __construct(
        MoySkladService $moySkladService,
        ProductSyncService $productSyncService,
        ServiceSyncService $serviceSyncService,
        BundleSyncService $bundleSyncService
    ) {
        $this->moySkladService = $moySkladService;
        $this->productSyncService = $productSyncService;
        $this->serviceSyncService = $serviceSyncService;
        $this->bundleSyncService = $bundleSyncService;
    }

    /**
     * Массовая синхронизация товара на все дочерние аккаунты
     * Использует очередь для избежания rate limits
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $productId UUID товара
     * @param array|null $updatedFields Updated fields for partial sync (UPDATE only)
     * @return int Количество добавленных задач в очередь
     */
    public function batchSyncProduct(string $mainAccountId, string $productId, ?array $updatedFields = null): int
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
                'child_accounts_count' => $childAccounts->count(),
                'updated_fields' => $updatedFields,
                'is_partial_sync' => !empty($updatedFields)
            ]);

            // Распределить задачи с задержками
            $baseDelay = 0;
            $queuedCount = 0;

            foreach ($childAccounts as $index => $childAccount) {
                // Вычислить scheduled_at с учетом приоритета и задержки
                $delay = $baseDelay + ($childAccount->sync_delay_seconds ?? 0);
                $scheduledAt = now()->addSeconds($delay);

                // Prepare payload with optional updatedFields for partial sync
                $payload = [
                    'main_account_id' => $mainAccountId,
                    'product_id' => $productId
                ];

                if ($updatedFields !== null) {
                    $payload['updated_fields'] = $updatedFields;
                }

                // Добавить в очередь
                SyncQueue::create([
                    'account_id' => $childAccount->child_account_id,
                    'entity_type' => 'product',
                    'entity_id' => $productId,
                    'operation' => 'sync',
                    'priority' => $childAccount->sync_priority ?? 5,
                    'status' => 'pending',
                    'payload' => $payload,
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
                'queued_count' => $queuedCount,
                'is_partial_sync' => !empty($updatedFields)
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
     * @param array|null $updatedFields Updated fields for partial sync (UPDATE only)
     * @return int Количество добавленных задач
     */
    public function batchSyncVariant(string $mainAccountId, string $variantId, ?array $updatedFields = null): int
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

                // Prepare payload with optional updatedFields for partial sync
                $payload = [
                    'main_account_id' => $mainAccountId,
                    'variant_id' => $variantId
                ];

                if ($updatedFields !== null) {
                    $payload['updated_fields'] = $updatedFields;
                }

                SyncQueue::create([
                    'account_id' => $childAccount->child_account_id,
                    'entity_type' => 'variant',
                    'entity_id' => $variantId,
                    'operation' => 'sync',
                    'priority' => $childAccount->sync_priority ?? 5,
                    'status' => 'pending',
                    'payload' => $payload,
                    'scheduled_at' => now()->addSeconds($delay),
                ]);

                $queuedCount++;
                $baseDelay += 0.15;
            }

            Log::info('Batch sync variant queued', [
                'main_account_id' => $mainAccountId,
                'variant_id' => $variantId,
                'queued_count' => $queuedCount,
                'is_partial_sync' => !empty($updatedFields)
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
     * @param array|null $updatedFields Updated fields for partial sync (UPDATE only)
     * @return int Количество добавленных задач
     */
    public function batchSyncBundle(string $mainAccountId, string $bundleId, ?array $updatedFields = null): int
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

                // Prepare payload with optional updatedFields for partial sync
                $payload = [
                    'main_account_id' => $mainAccountId,
                    'bundle_id' => $bundleId
                ];

                if ($updatedFields !== null) {
                    $payload['updated_fields'] = $updatedFields;
                }

                SyncQueue::create([
                    'account_id' => $childAccount->child_account_id,
                    'entity_type' => 'bundle',
                    'entity_id' => $bundleId,
                    'operation' => 'sync',
                    'priority' => $childAccount->sync_priority ?? 5,
                    'status' => 'pending',
                    'payload' => $payload,
                    'scheduled_at' => now()->addSeconds($delay),
                ]);

                $queuedCount++;
                $baseDelay += 0.15;
            }

            Log::info('Batch sync bundle queued', [
                'main_account_id' => $mainAccountId,
                'bundle_id' => $bundleId,
                'queued_count' => $queuedCount,
                'is_partial_sync' => !empty($updatedFields)
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
     * Массовая синхронизация услуги (service)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $serviceId UUID услуги
     * @param array|null $updatedFields Updated fields for partial sync (UPDATE only)
     * @return int Количество добавленных задач
     */
    public function batchSyncService(string $mainAccountId, string $serviceId, ?array $updatedFields = null): int
    {
        try {
            $childAccounts = DB::table('child_accounts')
                ->join('sync_settings', 'child_accounts.child_account_id', '=', 'sync_settings.account_id')
                ->where('child_accounts.parent_account_id', $mainAccountId)
                ->where('child_accounts.status', 'active')
                ->where('sync_settings.sync_services', true)
                ->orderBy('sync_settings.sync_priority', 'desc')
                ->select('child_accounts.*', 'sync_settings.sync_delay_seconds', 'sync_settings.sync_priority')
                ->get();

            Log::info('Batch sync service started', [
                'main_account_id' => $mainAccountId,
                'service_id' => $serviceId,
                'child_accounts_count' => $childAccounts->count(),
                'is_partial_sync' => !empty($updatedFields)
            ]);

            $baseDelay = 0;
            $queuedCount = 0;

            foreach ($childAccounts as $childAccount) {
                $delay = $baseDelay + ($childAccount->sync_delay_seconds ?? 0);

                // Prepare payload with optional updatedFields for partial sync
                $payload = [
                    'main_account_id' => $mainAccountId,
                    'service_id' => $serviceId
                ];

                if ($updatedFields !== null) {
                    $payload['updated_fields'] = $updatedFields;
                }

                SyncQueue::create([
                    'account_id' => $childAccount->child_account_id,
                    'entity_type' => 'service',
                    'entity_id' => $serviceId,
                    'operation' => 'sync',
                    'priority' => $childAccount->sync_priority ?? 5,
                    'status' => 'pending',
                    'payload' => $payload,
                    'scheduled_at' => now()->addSeconds($delay),
                ]);

                $queuedCount++;
                $baseDelay += 0.15;
            }

            Log::info('Batch sync service queued', [
                'main_account_id' => $mainAccountId,
                'service_id' => $serviceId,
                'queued_count' => $queuedCount,
                'is_partial_sync' => !empty($updatedFields)
            ]);

            return $queuedCount;

        } catch (\Exception $e) {
            Log::error('Batch sync service failed', [
                'main_account_id' => $mainAccountId,
                'service_id' => $serviceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Архивировать товар во всех дочерних аккаунтах
     * (при удалении или архивации в главном)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $productId UUID товара
     * @return int Количество добавленных задач
     */
    public function batchArchiveProduct(string $mainAccountId, string $productId): int
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
                    'operation' => 'delete', // delete = archive
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

            Log::info('Batch archive product queued', [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'queued_count' => $queuedCount
            ]);

            return $queuedCount;

        } catch (\Exception $e) {
            Log::error('Batch archive product failed', [
                'main_account_id' => $mainAccountId,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Архивировать модификацию во всех дочерних аккаунтах
     * (при удалении или архивации в главном)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $variantId UUID модификации
     * @return int Количество добавленных задач
     */
    public function batchArchiveVariant(string $mainAccountId, string $variantId): int
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
                    'entity_type' => 'variant',
                    'entity_id' => $variantId,
                    'operation' => 'delete', // delete = archive
                    'priority' => 3,
                    'status' => 'pending',
                    'payload' => [
                        'main_account_id' => $mainAccountId,
                        'variant_id' => $variantId
                    ],
                    'scheduled_at' => now(),
                ]);

                $queuedCount++;
            }

            Log::info('Batch archive variant queued', [
                'main_account_id' => $mainAccountId,
                'variant_id' => $variantId,
                'queued_count' => $queuedCount
            ]);

            return $queuedCount;

        } catch (\Exception $e) {
            Log::error('Batch archive variant failed', [
                'main_account_id' => $mainAccountId,
                'variant_id' => $variantId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Архивировать комплект во всех дочерних аккаунтах
     * (при удалении или архивации в главном)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $bundleId UUID комплекта
     * @return int Количество добавленных задач
     */
    public function batchArchiveBundle(string $mainAccountId, string $bundleId): int
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
                    'entity_type' => 'bundle',
                    'entity_id' => $bundleId,
                    'operation' => 'delete', // delete = archive
                    'priority' => 3,
                    'status' => 'pending',
                    'payload' => [
                        'main_account_id' => $mainAccountId,
                        'bundle_id' => $bundleId
                    ],
                    'scheduled_at' => now(),
                ]);

                $queuedCount++;
            }

            Log::info('Batch archive bundle queued', [
                'main_account_id' => $mainAccountId,
                'bundle_id' => $bundleId,
                'queued_count' => $queuedCount
            ]);

            return $queuedCount;

        } catch (\Exception $e) {
            Log::error('Batch archive bundle failed', [
                'main_account_id' => $mainAccountId,
                'bundle_id' => $bundleId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Архивировать услугу во всех дочерних аккаунтах
     * (при удалении или архивации в главном)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $serviceId UUID услуги
     * @return int Количество добавленных задач
     */
    public function batchArchiveService(string $mainAccountId, string $serviceId): int
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
                    'entity_type' => 'service',
                    'entity_id' => $serviceId,
                    'operation' => 'delete', // delete = archive
                    'priority' => 3,
                    'status' => 'pending',
                    'payload' => [
                        'main_account_id' => $mainAccountId,
                        'service_id' => $serviceId
                    ],
                    'scheduled_at' => now(),
                ]);

                $queuedCount++;
            }

            Log::info('Batch archive service queued', [
                'main_account_id' => $mainAccountId,
                'service_id' => $serviceId,
                'queued_count' => $queuedCount
            ]);

            return $queuedCount;

        } catch (\Exception $e) {
            Log::error('Batch archive service failed', [
                'main_account_id' => $mainAccountId,
                'service_id' => $serviceId,
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
            $query = Account::query();

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

    /**
     * Batch синхронизация массива товаров (products)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param array $products Массив products из МойСклад (уже с expand)
     * @return array ['success' => int, 'failed' => int]
     */
    public function batchSyncProducts(
        string $mainAccountId,
        string $childAccountId,
        array $products
    ): array {
        Log::info('batchSyncProducts started', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'products_count' => count($products),
            'memory_initial_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ]);

        $successCount = 0;
        $failedCount = 0;

        // Разбить на chunks по 10 товаров для предотвращения memory leaks
        $chunks = array_chunk($products, 10);

        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $product) {
                try {
                    $this->productSyncService->syncProduct(
                        $mainAccountId,
                        $childAccountId,
                        $product['id']
                    );
                    $successCount++;
                } catch (\Exception $e) {
                    Log::error('Failed to sync product in batch', [
                        'product_id' => $product['id'],
                        'error' => $e->getMessage()
                    ]);
                    $failedCount++;
                }

                // Освободить память после каждого товара
                unset($product);
            }

            // Освободить chunk
            unset($chunk);

            // Принудительная сборка мусора каждые 5 chunks (50 товаров)
            if (($chunkIndex + 1) % 5 === 0) {
                gc_collect_cycles();

                Log::channel('memory')->debug('Batch products memory checkpoint', [
                    'chunk' => $chunkIndex + 1,
                    'total_chunks' => count($chunks),
                    'processed' => ($chunkIndex + 1) * 10,
                    'memory_current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                ]);
            }
        }

        // Финальная очистка памяти
        unset($products, $chunks);
        gc_collect_cycles();

        Log::info('batchSyncProducts completed', [
            'success' => $successCount,
            'failed' => $failedCount,
            'memory_final_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ]);

        return ['success' => $successCount, 'failed' => $failedCount];
    }

    /**
     * Batch синхронизация массива услуг (services)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param array $services Массив services из МойСклад (уже с expand)
     * @return array ['success' => int, 'failed' => int]
     */
    public function batchSyncServices(
        string $mainAccountId,
        string $childAccountId,
        array $services
    ): array {
        Log::info('batchSyncServices started', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'services_count' => count($services),
            'memory_initial_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ]);

        $successCount = 0;
        $failedCount = 0;

        // Разбить на chunks по 10 услуг
        $chunks = array_chunk($services, 10);

        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $service) {
                try {
                    $this->serviceSyncService->syncService(
                        $mainAccountId,
                        $childAccountId,
                        $service['id']
                    );
                    $successCount++;
                } catch (\Exception $e) {
                    Log::error('Failed to sync service in batch', [
                        'service_id' => $service['id'],
                        'error' => $e->getMessage()
                    ]);
                    $failedCount++;
                }

                unset($service);
            }

            unset($chunk);

            // GC каждые 5 chunks
            if (($chunkIndex + 1) % 5 === 0) {
                gc_collect_cycles();

                Log::channel('memory')->debug('Batch services memory checkpoint', [
                    'chunk' => $chunkIndex + 1,
                    'memory_current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                ]);
            }
        }

        // Финальная очистка
        unset($services, $chunks);
        gc_collect_cycles();

        Log::info('batchSyncServices completed', [
            'success' => $successCount,
            'failed' => $failedCount,
            'memory_final_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ]);

        return ['success' => $successCount, 'failed' => $failedCount];
    }

    /**
     * Batch синхронизация массива комплектов (bundles)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param array $bundles Массив bundles из МойСклад (уже с expand)
     * @return array ['success' => int, 'failed' => int]
     */
    public function batchSyncBundles(
        string $mainAccountId,
        string $childAccountId,
        array $bundles
    ): array {
        Log::info('batchSyncBundles started', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'bundles_count' => count($bundles),
            'memory_initial_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ]);

        $successCount = 0;
        $failedCount = 0;

        // Разбить на chunks по 10 комплектов
        $chunks = array_chunk($bundles, 10);

        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $bundle) {
                try {
                    $this->bundleSyncService->syncBundle(
                        $mainAccountId,
                        $childAccountId,
                        $bundle['id']
                    );
                    $successCount++;
                } catch (\Exception $e) {
                    Log::error('Failed to sync bundle in batch', [
                        'bundle_id' => $bundle['id'],
                        'error' => $e->getMessage()
                    ]);
                    $failedCount++;
                }

                unset($bundle);
            }

            unset($chunk);

            // GC каждые 5 chunks
            if (($chunkIndex + 1) % 5 === 0) {
                gc_collect_cycles();

                Log::channel('memory')->debug('Batch bundles memory checkpoint', [
                    'chunk' => $chunkIndex + 1,
                    'memory_current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                ]);
            }
        }

        // Финальная очистка
        unset($bundles, $chunks);
        gc_collect_cycles();

        Log::info('batchSyncBundles completed', [
            'success' => $successCount,
            'failed' => $failedCount,
            'memory_final_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ]);

        return ['success' => $successCount, 'failed' => $failedCount];
    }
}
