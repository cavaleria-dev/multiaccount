<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Абстрактный базовый класс для обработчиков задач синхронизации
 *
 * Определяет общий интерфейс и базовую логику для всех sync handlers.
 * Каждый конкретный handler отвечает за синхронизацию одного типа сущности.
 */
abstract class SyncTaskHandler
{
    /**
     * Обработать задачу синхронизации
     *
     * @param SyncQueue $task Задача из очереди
     * @param Collection $accountsCache Кеш аккаунтов (для оптимизации N+1)
     * @param Collection $settingsCache Кеш настроек синхронизации
     * @return void
     * @throws \Exception При ошибке синхронизации
     */
    public function handle(
        SyncQueue $task,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $payload = $task->payload ?? [];

        // Валидация payload
        $this->validatePayload($task, $payload);

        // Обработка операции удаления (если поддерживается)
        if ($task->operation === 'delete') {
            $this->handleDelete($task, $payload, $accountsCache, $settingsCache);
            return;
        }

        // Основная синхронизация
        $this->handleSync($task, $payload, $accountsCache, $settingsCache);
    }

    /**
     * Валидация payload задачи
     *
     * @param SyncQueue $task
     * @param array $payload
     * @return void
     * @throws \Exception Если payload невалиден
     */
    protected function validatePayload(SyncQueue $task, array $payload): void
    {
        if (empty($payload)) {
            throw new \Exception('Invalid payload: empty payload');
        }

        // Для большинства задач требуется main_account_id
        if ($this->requiresMainAccountId() && !isset($payload['main_account_id'])) {
            Log::warning('Task skipped: missing main_account_id in payload', [
                'task_id' => $task->id,
                'entity_type' => $task->entity_type,
                'entity_id' => $task->entity_id,
                'payload' => $payload
            ]);
            throw new \Exception('Invalid payload: missing main_account_id');
        }
    }

    /**
     * Требуется ли main_account_id в payload?
     *
     * Override в дочернем классе если main_account_id не нужен (например, webhook check)
     *
     * @return bool
     */
    protected function requiresMainAccountId(): bool
    {
        return true;
    }

    /**
     * Обработать операцию удаления/архивации
     *
     * @param SyncQueue $task
     * @param array $payload
     * @param Collection $accountsCache
     * @param Collection $settingsCache
     * @return void
     */
    protected function handleDelete(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        // По умолчанию - не поддерживается
        Log::warning('Delete operation not supported for this entity type', [
            'task_id' => $task->id,
            'entity_type' => $task->entity_type
        ]);
    }

    /**
     * Выполнить основную синхронизацию
     *
     * Должен быть реализован в каждом конкретном handler
     *
     * @param SyncQueue $task
     * @param array $payload
     * @param Collection $accountsCache
     * @param Collection $settingsCache
     * @return void
     */
    abstract protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void;

    /**
     * Получить тип сущности, который обрабатывает этот handler
     *
     * @return string
     */
    abstract public function getEntityType(): string;

    /**
     * Логировать успешное выполнение задачи
     *
     * @param SyncQueue $task
     * @param array $context Дополнительный контекст для логирования
     * @return void
     */
    protected function logSuccess(SyncQueue $task, array $context = []): void
    {
        Log::info('Task processed successfully', array_merge([
            'task_id' => $task->id,
            'entity_type' => $task->entity_type,
            'entity_id' => $task->entity_id,
        ], $context));
    }
}
