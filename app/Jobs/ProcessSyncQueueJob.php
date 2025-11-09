<?php

namespace App\Jobs;

use App\Models\SyncQueue;
use App\Models\QueueMemoryLog;
use App\Services\Sync\TaskDispatcher;
use App\Services\SyncStatisticsService;
use App\Exceptions\RateLimitException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для обработки очереди синхронизации
 */
class ProcessSyncQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;

    /**
     * Execute the job.
     */
    public function handle(
        TaskDispatcher $taskDispatcher,
        SyncStatisticsService $statisticsService
    ): void {
        // Генерация уникального job_id для отслеживания памяти
        $jobId = uniqid('job_', true);
        $memoryLimit = 400 * 1024 * 1024;  // 400MB soft limit

        // Детальное логирование для диагностики
        $totalPending = \DB::table('sync_queue')->where('status', 'pending')->count();
        $currentTime = now()->toDateTimeString();

        Log::info('ProcessSyncQueueJob START', [
            'job_id' => $jobId,
            'current_time' => $currentTime,
            'total_pending_in_db' => $totalPending,
            'memory_initial_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_limit_mb' => round($memoryLimit / 1024 / 1024, 2)
        ]);

        // Обработать задачи из очереди (порциями по 50)
        // Используем короткую транзакцию с lockForUpdate для защиты от race conditions
        $tasks = \DB::transaction(function() {
            $selectedTasks = SyncQueue::where('status', 'pending')
                ->where(function($query) {
                    $query->whereNull('scheduled_at')
                          ->orWhere('scheduled_at', '<=', now());
                })
                ->orderBy('priority', 'desc')
                ->orderByRaw("payload->>'main_account_id'")  // Группировать по main account для балансировки
                ->orderBy('scheduled_at', 'asc')
                ->orderBy('id', 'asc')  // Tie-breaker для детерминированного порядка
                ->limit(50)
                ->lockForUpdate()  // Блокировка для предотвращения дублирования
                ->get();

            // Обновить статус ВНУТРИ транзакции (пока держим блокировку)
            foreach ($selectedTasks as $task) {
                $task->update([
                    'status' => 'processing',
                    'started_at' => now(),
                ]);
            }

            return $selectedTasks;
        }); // Транзакция завершается, блокировка снимается (~100ms)

        if ($tasks->isEmpty()) {
            Log::warning('ProcessSyncQueueJob: No tasks ready to process', [
                'total_pending_in_db' => $totalPending,
                'tasks_found_by_query' => 0,
                'reason' => 'Either all tasks have scheduled_at in future, or no pending tasks exist'
            ]);
            return;
        }

        Log::info('Processing sync queue', [
            'tasks_count' => $tasks->count()
        ]);

        // Балансировка задач по main accounts для равномерного использования rate limits
        $balancedTasks = $this->balanceTasksByMainAccount($tasks, 50);

        // Предзагрузка accounts и settings для оптимизации (N+1 fix)
        $accountIds = $balancedTasks->pluck('account_id')->unique();
        $mainAccountIds = $balancedTasks->map(function($task) {
            return $task->payload['main_account_id'] ?? null;
        })->filter()->unique();

        $allAccountIds = $accountIds->merge($mainAccountIds)->unique();

        $accountsCache = \App\Models\Account::whereIn('account_id', $allAccountIds)
            ->get()
            ->keyBy('account_id');

        $settingsCache = \App\Models\SyncSetting::whereIn('account_id', $accountIds)
            ->get()
            ->keyBy('account_id');

        Log::info('Pre-loaded accounts and settings', [
            'accounts_count' => $accountsCache->count(),
            'settings_count' => $settingsCache->count(),
            'tasks_count' => $balancedTasks->count()
        ]);

        // Начальное логирование памяти в БД
        QueueMemoryLog::create([
            'job_id' => $jobId,
            'batch_index' => 0,
            'task_count' => $balancedTasks->count(),
            'entity_type' => null,
            'memory_current_mb' => memory_get_usage(true) / 1024 / 1024,
            'memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
            'memory_limit_mb' => (float) ini_get('memory_limit') ?: 512.0,
            'logged_at' => now(),
        ]);

        // Кеш исчерпанных main accounts в пределах текущего batch
        $exhaustedMainAccounts = [];

        foreach ($balancedTasks as $index => $task) {
            try {
                // Проверка памяти ПЕРЕД обработкой задачи
                $currentMemory = memory_get_usage(true);

                if ($currentMemory > $memoryLimit) {
                    Log::warning('Memory limit approaching, stopping batch processing', [
                        'job_id' => $jobId,
                        'current_memory_mb' => round($currentMemory / 1024 / 1024, 2),
                        'limit_mb' => round($memoryLimit / 1024 / 1024, 2),
                        'processed_tasks' => $index,
                        'remaining_tasks' => $balancedTasks->count() - $index,
                        'stopped_at_task_id' => $task->id
                    ]);

                    // Вернуть оставшиеся задачи обратно в pending
                    $balancedTasks->slice($index)->each(function($remainingTask) {
                        $remainingTask->update(['status' => 'pending']);
                    });

                    break; // Остановить обработку
                }

                // Проверить: не исчерпан ли main account для этой задачи?
                $payload = $task->payload;
                $mainAccountId = $payload['main_account_id'] ?? null;

                if ($mainAccountId && isset($exhaustedMainAccounts[$mainAccountId])) {
                    $retryAfter = $exhaustedMainAccounts[$mainAccountId];

                    Log::debug('Skipping task for exhausted main account', [
                        'task_id' => $task->id,
                        'main_account_id' => substr($mainAccountId, 0, 8) . '...',
                        'retry_after' => $retryAfter
                    ]);

                    $task->update([
                        'status' => 'pending',
                        'scheduled_at' => now()->addSeconds($retryAfter),
                        'error' => 'Main account rate limit exhausted (batch skipped)'
                    ]);

                    continue;
                }

                $startTime = microtime(true);

                // Обработать задачу через TaskDispatcher (передаём cache для оптимизации)
                $taskDispatcher->dispatch($task, $accountsCache, $settingsCache);

                $duration = (int)((microtime(true) - $startTime) * 1000); // ms

                // Отметить как выполненное
                $task->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                // Записать статистику
                if (in_array($task->entity_type, ['product', 'variant', 'product_variants', 'batch_products', 'bundle', 'batch_bundles', 'service', 'batch_services'])) {
                    $payload = $task->payload;
                    if (isset($payload['main_account_id'])) {
                        $statisticsService->recordSync(
                            $payload['main_account_id'],
                            $task->account_id,
                            'product',
                            true,
                            $duration
                        );
                    }
                } elseif (in_array($task->entity_type, ['customerorder', 'retaildemand', 'purchaseorder'])) {
                    // Для заказов нужно получить parent_account_id из child_accounts
                    $parentAccountId = $this->getParentAccountId($task->account_id);
                    if ($parentAccountId) {
                        $statisticsService->recordSync(
                            $parentAccountId,
                            $task->account_id,
                            'order',
                            true,
                            $duration
                        );
                    }
                }

            } catch (RateLimitException $e) {
                // Rate limit превышен - отложить задачу
                $retryAfterSeconds = $e->getRetryAfterSeconds();
                $rateLimitInfo = $e->getRateLimitInfo();

                $task->update([
                    'status' => 'pending',
                    'error' => 'Rate limit exceeded',
                    'rate_limit_info' => $rateLimitInfo,
                    'scheduled_at' => now()->addSeconds($retryAfterSeconds),
                ]);

                Log::warning('Task postponed due to rate limit', [
                    'task_id' => $task->id,
                    'retry_after_seconds' => $retryAfterSeconds,
                    'rate_limit_info' => $rateLimitInfo
                ]);

                // Определить scope rate limit и добавить в кеш если это main account
                $payload = $task->payload;
                $mainAccountId = $payload['main_account_id'] ?? null;
                $isGlobalRateLimit = ($rateLimitInfo['remaining'] ?? 0) <= 1;

                if ($isGlobalRateLimit && $mainAccountId) {
                    // Глобальный rate limit на main account
                    // Добавить в кеш исчерпанных accounts
                    $exhaustedMainAccounts[$mainAccountId] = $retryAfterSeconds;

                    Log::warning('Global rate limit detected on main account', [
                        'main_account_id' => substr($mainAccountId, 0, 8) . '...',
                        'retry_after' => $retryAfterSeconds
                    ]);

                    // Отложить ВСЕ задачи для этого main account
                    $this->postponeAllMainAccountTasks($mainAccountId, $retryAfterSeconds, $task->id);

                    // Прервать обработку текущего batch (все задачи для main account отложены)
                    break;
                } else {
                    // Endpoint-specific rate limit или child account rate limit
                    // Продолжить обработку других задач
                    Log::info('Endpoint-specific rate limit, continuing with other tasks', [
                        'task_id' => $task->id
                    ]);

                    continue;
                }

            } catch (\Throwable $e) {
                // Ловим и Exception, и Error (включая TypeError)
                $task->increment('attempts');

                // Специальная обработка для batch задач (batch_services, batch_products, batch_bundles)
                // Если batch POST упал целиком → создать индивидуальные retry задачи для всех сущностей
                if (in_array($task->entity_type, ['batch_services', 'batch_products', 'batch_bundles'])) {
                    $this->handleBatchTaskFailure($task, $e, $statisticsService);
                    continue; // Переходим к следующей задаче
                }

                // Проверить, стоит ли повторять задачу
                $shouldRetry = $this->isRetryableError($e->getMessage());

                if (!$shouldRetry || $task->attempts >= $task->max_attempts) {
                    // Постоянная ошибка (4xx) или исчерпаны попытки - сразу failed
                    $task->update([
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ]);

                    // Записать статистику о неудаче
                    if (in_array($task->entity_type, ['product', 'variant', 'product_variants', 'batch_products', 'bundle', 'batch_bundles', 'service', 'batch_services'])) {
                        $payload = $task->payload;
                        if (isset($payload['main_account_id'])) {
                            $statisticsService->recordSync(
                                $payload['main_account_id'],
                                $task->account_id,
                                'product',
                                false,
                                0
                            );
                        }
                    }

                    Log::error('Task failed permanently', [
                        'task_id' => $task->id,
                        'entity_type' => $task->entity_type,
                        'entity_id' => $task->entity_id,
                        'attempts' => $task->attempts,
                        'retryable' => $shouldRetry,
                        'error' => $e->getMessage()
                    ]);

                } else {
                    // Временная ошибка (5xx, network) - retry с exponential backoff
                    $task->update([
                        'status' => 'pending',
                        'error' => $e->getMessage(),
                        'scheduled_at' => now()->addMinutes(5 * $task->attempts), // 5мин, 10мин, 15мин
                    ]);

                    Log::warning('Task failed, will retry (retryable error)', [
                        'task_id' => $task->id,
                        'attempts' => $task->attempts,
                        'max_attempts' => $task->max_attempts,
                        'retry_at' => now()->addMinutes(5 * $task->attempts)->toDateTimeString(),
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Освободить память после обработки задачи
            unset($task, $payload);

            // Принудительная сборка мусора и логирование памяти каждые 10 задач
            if (($index + 1) % 10 === 0) {
                gc_collect_cycles();

                // Логировать в БД
                QueueMemoryLog::create([
                    'job_id' => $jobId,
                    'batch_index' => $index + 1,
                    'task_count' => 10,
                    'entity_type' => null,
                    'memory_current_mb' => memory_get_usage(true) / 1024 / 1024,
                    'memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
                    'memory_limit_mb' => (float) ini_get('memory_limit') ?: 512.0,
                    'duration_ms' => null,
                    'logged_at' => now(),
                ]);

                Log::channel('memory')->info('Queue memory checkpoint', [
                    'job_id' => $jobId,
                    'tasks_processed' => $index + 1,
                    'memory_current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
                ]);
            }
        }

        // Финальная очистка памяти после завершения всех задач
        unset($balancedTasks, $accountsCache, $settingsCache, $exhaustedMainAccounts);
        gc_collect_cycles();

        // Финальное логирование памяти
        QueueMemoryLog::create([
            'job_id' => $jobId,
            'batch_index' => -1,  // -1 означает финальный лог
            'task_count' => $tasks->count(),
            'entity_type' => 'final',
            'memory_current_mb' => memory_get_usage(true) / 1024 / 1024,
            'memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
            'memory_limit_mb' => (float) ini_get('memory_limit') ?: 512.0,
            'logged_at' => now(),
        ]);

        Log::info('ProcessSyncQueueJob COMPLETED', [
            'job_id' => $jobId,
            'final_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ]);
    }

    // ========================================================================
    // ПРИМЕЧАНИЕ: Методы processXXXSync() были перенесены в отдельные Handlers
    // См. app/Services/Sync/Handlers/ для деталей
    // Маршрутизация осуществляется через TaskDispatcher
    // ========================================================================

    protected function getParentAccountId(string $childAccountId): ?string
    {
        $link = \DB::table('child_accounts')
            ->where('child_account_id', $childAccountId)
            ->first();

        return $link?->parent_account_id;
    }

    /**
     * Обработать полное падение batch задачи
     *
     * Когда batch POST запрос падает целиком (404, 500, etc.),
     * создаём индивидуальные retry задачи для ВСЕХ сущностей из payload.
     * Это позволяет не потерять сущности и попытаться синхронизировать их по одной.
     */
    protected function handleBatchTaskFailure(
        SyncQueue $task,
        \Throwable $e,
        SyncStatisticsService $statisticsService
    ): void {
        $payload = $task->payload ?? [];

        Log::error('Batch task failed completely, creating individual retry tasks', [
            'task_id' => $task->id,
            'entity_type' => $task->entity_type,
            'attempts' => $task->attempts,
            'error' => $e->getMessage()
        ]);

        // Извлечь сущности из payload
        $entities = [];
        $entityTypeSingular = '';

        if ($task->entity_type === 'batch_services') {
            $entities = $payload['services'] ?? [];
            $entityTypeSingular = 'service';
        } elseif ($task->entity_type === 'batch_products') {
            $entities = $payload['products'] ?? [];
            $entityTypeSingular = 'product';
        } elseif ($task->entity_type === 'batch_bundles') {
            $entities = $payload['bundles'] ?? [];
            $entityTypeSingular = 'bundle';
        }

        if (empty($entities)) {
            Log::warning('Batch task has no entities in payload', [
                'task_id' => $task->id,
                'payload_keys' => array_keys($payload)
            ]);

            // Пометить как failed если нет данных
            $task->update([
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);
            return;
        }

        $mainAccountId = $payload['main_account_id'] ?? null;
        if (!$mainAccountId) {
            Log::warning('Batch task missing main_account_id', [
                'task_id' => $task->id
            ]);

            $task->update([
                'status' => 'failed',
                'error' => 'Missing main_account_id in payload'
            ]);
            return;
        }

        // Создать индивидуальные retry задачи для ВСЕХ сущностей
        $createdRetryTasks = 0;
        $deletedMappingsCount = 0;
        $updateRetryTasks = 0;

        // Получить child account для проверки существующих сущностей
        $childAccount = \App\Models\Account::where('account_id', $task->account_id)->first();

        foreach ($entities as $entity) {
            $entityId = $entity['id'] ?? null;

            if (!$entityId) {
                Log::warning('Entity missing id, skipping', [
                    'task_id' => $task->id,
                    'entity_name' => $entity['name'] ?? 'unknown'
                ]);
                continue;
            }

            // Проверить существующий mapping
            $existingMapping = \App\Models\EntityMapping::where([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $task->account_id,
                'entity_type' => $entityTypeSingular,
                'parent_entity_id' => $entityId
            ])->first();

            // Флаг: существует ли сущность в child account
            $entityExistsInChild = false;

            if ($existingMapping && $childAccount) {
                // Mapping существует - проверить существует ли сущность в child account
                try {
                    $moysklad = app(\App\Services\MoySkladService::class);
                    $moysklad->setAccessToken($childAccount->access_token)
                             ->setAccountId($task->account_id);

                    // Определить endpoint для проверки
                    $endpoint = match($entityTypeSingular) {
                        'product' => "entity/product/{$existingMapping->child_entity_id}",
                        'service' => "entity/service/{$existingMapping->child_entity_id}",
                        'bundle' => "entity/bundle/{$existingMapping->child_entity_id}",
                        default => null
                    };

                    if ($endpoint) {
                        $existingEntity = $moysklad->get($endpoint);

                        if ($existingEntity && !($existingEntity['archived'] ?? false)) {
                            // ✅ Сущность существует и не архивирована - создадим UPDATE retry
                            $entityExistsInChild = true;

                            Log::info('Entity exists in child account, will create UPDATE retry', [
                                'task_id' => $task->id,
                                'entity_type' => $entityTypeSingular,
                                'parent_entity_id' => $entityId,
                                'child_entity_id' => $existingMapping->child_entity_id
                            ]);
                        }
                    }
                } catch (\Throwable $checkError) {
                    // Ошибка при проверке (404, network, etc.) - создадим CREATE retry
                    Log::warning('Failed to check existing entity in child account', [
                        'task_id' => $task->id,
                        'entity_type' => $entityTypeSingular,
                        'entity_id' => $entityId,
                        'error' => $checkError->getMessage()
                    ]);
                }

                // Удалить stale mapping ТОЛЬКО если сущность НЕ существует
                if (!$entityExistsInChild) {
                    $existingMapping->delete();
                    $deletedMappingsCount++;

                    Log::info('Deleted stale mapping (entity not found in child)', [
                        'task_id' => $task->id,
                        'entity_type' => $entityTypeSingular,
                        'parent_entity_id' => $entityId,
                        'child_entity_id' => $existingMapping->child_entity_id
                    ]);
                }
            }

            // Создать retry задачу с операцией UPDATE (если существует) или CREATE (если нет)
            $operation = $entityExistsInChild ? 'update' : 'create';

            SyncQueue::create([
                'account_id' => $task->account_id,
                'entity_type' => $entityTypeSingular,  // 'service' или 'product'
                'entity_id' => $entityId,
                'operation' => $operation,  // ⭐ UPDATE для существующих, CREATE для новых
                'priority' => 5,
                'scheduled_at' => now()->addMinute(),  // 1 минута
                'status' => 'pending',
                'attempts' => 0,
                'payload' => [
                    'main_account_id' => $mainAccountId,
                    'batch_retry' => true,
                    'original_batch_task_id' => $task->id,
                    'batch_failure_reason' => substr($e->getMessage(), 0, 200)
                ]
            ]);

            $createdRetryTasks++;

            if ($operation === 'update') {
                $updateRetryTasks++;
            }
        }

        Log::info('Created individual retry tasks for failed batch', [
            'original_task_id' => $task->id,
            'entity_type' => $task->entity_type,
            'entities_count' => count($entities),
            'deleted_stale_mappings' => $deletedMappingsCount,
            'update_retry_tasks' => $updateRetryTasks,
            'create_retry_tasks' => $createdRetryTasks - $updateRetryTasks,
            'total_retry_tasks' => $createdRetryTasks
        ]);

        // Пометить batch задачу как failed (уже создали retry задачи)
        $task->update([
            'status' => 'failed',
            'error' => $e->getMessage()
        ]);

        // Записать статистику о неудаче (batch упал, но retry задачи созданы)
        if (isset($payload['main_account_id'])) {
            $statisticsService->recordSync(
                $payload['main_account_id'],
                $task->account_id,
                'product',
                false,
                0
            );
        }
    }

    /**
     * Проверить, стоит ли повторять задачу при данной ошибке
     *
     * Retry имеет смысл ТОЛЬКО для:
     * - 5xx Server Errors (500, 502, 503, 504) - временные проблемы МойСклад
     * - Network errors (timeout, connection refused)
     *
     * Все 4xx ошибки (404, 400, 403, etc.) - постоянные, retry бессмыслен
     */
    protected function isRetryableError(string $errorMessage): bool
    {
        // Извлечь HTTP статус из сообщения (формат: "[HTTP 404 Not Found] ...")
        if (preg_match('/\[HTTP (\d{3})/i', $errorMessage, $matches)) {
            $httpStatus = (int)$matches[1];

            // Retry только для 5xx серверных ошибок
            if ($httpStatus >= 500 && $httpStatus < 600) {
                return true;
            }

            // Все 4xx (400, 404, 403, etc.) - не retry
            if ($httpStatus >= 400 && $httpStatus < 500) {
                return false;
            }
        }

        // Network errors - retry
        $networkErrors = [
            'timeout',
            'connection refused',
            'connection timed out',
            'could not resolve host',
            'failed to connect',
            'network is unreachable',
            'dns',
        ];

        $lowerMessage = strtolower($errorMessage);
        foreach ($networkErrors as $pattern) {
            if (str_contains($lowerMessage, $pattern)) {
                return true;
            }
        }

        // По умолчанию - не retry (безопаснее)
        return false;
    }

    /**
     * Сбалансировать задачи по main accounts с сохранением порядка приоритетов
     *
     * ВАЖНО: Группируем сначала по priority, затем делаем интерливинг внутри каждого уровня.
     * Это гарантирует строгий порядок: все priority=10, затем все priority=8, и т.д.
     *
     * Вместо обработки 50 задач подряд для Main A,
     * чередуем задачи разных main accounts: Main A → Main B → Main C → Main A → ...
     * НО сохраняем приоритеты: сначала ВСЕ priority=10, потом ВСЕ priority=8, etc.
     *
     * Результат: все main accounts обрабатываются параллельно в одном цикле,
     * но задачи с высоким приоритетом ВСЕГДА выполняются раньше низкоприоритетных.
     *
     * @param Collection $tasks Исходные задачи (уже отсортированы по priority DESC)
     * @param int $limit Максимальное количество задач
     * @return Collection Сбалансированные задачи с сохранением приоритетов
     */
    protected function balanceTasksByMainAccount(\Illuminate\Support\Collection $tasks, int $limit): \Illuminate\Support\Collection
    {
        // Группировать по priority СНАЧАЛА (для сохранения строгого порядка)
        $byPriority = $tasks->groupBy('priority')->sortKeysDesc();

        Log::info('Balancing tasks by priority and main account', [
            'total_tasks' => $tasks->count(),
            'priorities' => $byPriority->keys()->toArray(),
            'tasks_per_priority' => $byPriority->map->count()->toArray()
        ]);

        $balanced = collect();

        // Обработать каждый уровень приоритета по порядку (от высокого к низкому)
        foreach ($byPriority as $priority => $priorityTasks) {
            // Группировать задачи этого приоритета по main_account_id
            $grouped = $priorityTasks->groupBy(function($task) {
                return $task->payload['main_account_id'] ?? 'unknown';
            });

            $accountCount = $grouped->count();

            Log::debug("Balancing priority level {$priority}", [
                'priority' => $priority,
                'tasks_in_priority' => $priorityTasks->count(),
                'main_accounts' => $accountCount,
                'tasks_per_account' => $grouped->map->count()->toArray()
            ]);

            // Если только один аккаунт на этом уровне приоритета - просто добавить все задачи
            if ($accountCount <= 1) {
                foreach ($priorityTasks as $task) {
                    $balanced->push($task);
                    if ($balanced->count() >= $limit) {
                        break 2; // Выйти из обоих циклов
                    }
                }
                continue;
            }

            // Интерливинг внутри этого уровня приоритета
            $maxIterations = (int)ceil($priorityTasks->count() / $accountCount);

            for ($i = 0; $i < $maxIterations && $balanced->count() < $limit; $i++) {
                foreach ($grouped as $mainAccountId => $accountTasks) {
                    if (isset($accountTasks[$i])) {
                        $balanced->push($accountTasks[$i]);

                        if ($balanced->count() >= $limit) {
                            break 3; // Выйти из всех циклов
                        }
                    }
                }
            }

            // Достигли лимита - прекратить обработку следующих приоритетов
            if ($balanced->count() >= $limit) {
                break;
            }
        }

        Log::info('Tasks balanced with priority preservation', [
            'balanced_count' => $balanced->count(),
            'priority_distribution' => $balanced->groupBy('priority')->map->count()->toArray(),
            'account_distribution' => $balanced->groupBy(function($task) {
                return $task->payload['main_account_id'] ?? 'unknown';
            })->map->count()->toArray()
        ]);

        return $balanced;
    }

    /**
     * Отложить ВСЕ pending задачи для main account из-за rate limit exhaustion
     *
     * Используется когда main account исчерпал свой rate limit,
     * чтобы избежать бесполезных попыток обработки других задач для этого account.
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param int $retryAfter Время задержки в секундах
     * @param int|null $excludeTaskId ID задачи которую НЕ нужно откладывать (уже обработана)
     */
    protected function postponeAllMainAccountTasks(string $mainAccountId, int $retryAfter, ?int $excludeTaskId = null): void
    {
        $query = SyncQueue::where('status', 'pending')
            ->whereRaw("payload->>'main_account_id' = ?", [$mainAccountId]);

        if ($excludeTaskId) {
            $query->where('id', '!=', $excludeTaskId);
        }

        $postponed = $query->update([
            'scheduled_at' => now()->addSeconds($retryAfter),
            'error' => 'Main account rate limit - batch postponed'
        ]);

        Log::warning('Postponed all tasks for main account due to rate limit', [
            'main_account_id' => substr($mainAccountId, 0, 8) . '...',
            'postponed_count' => $postponed,
            'retry_after_seconds' => $retryAfter
        ]);
    }

    /**
     * Разбить массив сущностей на sub-batches если JSON размер превышает лимит
     *
     * МойСклад API имеет лимит 20MB на batch запрос.
     * Этот метод разбивает большие batches на smaller chunks если нужно.
     *
     * @param array $entities Массив подготовленных сущностей
     * @param int $maxSizeBytes Максимальный размер в байтах (default: 18MB для безопасности)
     * @return array[] Массив sub-batches
     */
    protected function splitBatchIfNeeded(array $entities, int $maxSizeBytes = 18874368): array
    {
        // 18MB = 18 * 1024 * 1024 = 18874368 байт (оставляем запас)

        $jsonEncoded = json_encode($entities);
        $totalSize = strlen($jsonEncoded);

        if ($totalSize <= $maxSizeBytes) {
            // Размер в пределах нормы - возвращаем один batch
            Log::debug('Batch size within limits', [
                'entities_count' => count($entities),
                'size_bytes' => $totalSize,
                'size_mb' => round($totalSize / 1024 / 1024, 2)
            ]);
            return [$entities];
        }

        // Размер превышает лимит - нужно разбить
        Log::warning('Batch size exceeds limit, splitting into sub-batches', [
            'entities_count' => count($entities),
            'size_bytes' => $totalSize,
            'size_mb' => round($totalSize / 1024 / 1024, 2),
            'max_size_mb' => round($maxSizeBytes / 1024 / 1024, 2)
        ]);

        $subBatches = [];
        $currentBatch = [];
        $currentSize = 0;

        foreach ($entities as $entity) {
            $entityJson = json_encode($entity);
            $entitySize = strlen($entityJson);

            // Проверить: поместится ли entity в текущий batch?
            if ($currentSize + $entitySize > $maxSizeBytes && !empty($currentBatch)) {
                // Текущий batch заполнен - сохранить и начать новый
                $subBatches[] = $currentBatch;
                $currentBatch = [$entity];
                $currentSize = $entitySize + 2; // +2 для [] в JSON array
            } else {
                $currentBatch[] = $entity;
                $currentSize += $entitySize + 1; // +1 для запятой в JSON array
            }
        }

        // Добавить последний batch
        if (!empty($currentBatch)) {
            $subBatches[] = $currentBatch;
        }

        Log::info('Split batch into sub-batches', [
            'original_count' => count($entities),
            'sub_batches_count' => count($subBatches),
            'sub_batch_sizes' => array_map(fn($b) => count($b), $subBatches)
        ]);

        return $subBatches;
    }
}
