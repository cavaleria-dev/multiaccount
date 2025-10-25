<?php

namespace App\Services\Sync;

use App\Models\SyncQueue;
use App\Services\Sync\Handlers\SyncTaskHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Диспетчер задач синхронизации
 *
 * Маршрутизирует задачи к соответствующим handlers на основе entity_type.
 * Реализует паттерн Strategy для обработки разных типов сущностей.
 */
class TaskDispatcher
{
    /**
     * @var array<string, SyncTaskHandler> Мапа entity_type → handler
     */
    protected array $handlers = [];

    /**
     * Зарегистрировать handler для конкретного entity_type
     *
     * @param SyncTaskHandler $handler
     * @return void
     */
    public function registerHandler(SyncTaskHandler $handler): void
    {
        $entityType = $handler->getEntityType();
        $this->handlers[$entityType] = $handler;

        Log::debug('Registered sync handler', [
            'entity_type' => $entityType,
            'handler_class' => get_class($handler)
        ]);
    }

    /**
     * Зарегистрировать несколько handlers
     *
     * @param array<SyncTaskHandler> $handlers
     * @return void
     */
    public function registerHandlers(array $handlers): void
    {
        foreach ($handlers as $handler) {
            $this->registerHandler($handler);
        }
    }

    /**
     * Обработать задачу синхронизации
     *
     * Находит соответствующий handler и делегирует ему обработку.
     *
     * @param SyncQueue $task
     * @param Collection $accountsCache
     * @param Collection $settingsCache
     * @return void
     * @throws \Exception Если handler не найден или обработка failed
     */
    public function dispatch(
        SyncQueue $task,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $handler = $this->getHandler($task->entity_type);

        if (!$handler) {
            Log::warning('No handler registered for entity type', [
                'entity_type' => $task->entity_type,
                'task_id' => $task->id
            ]);
            throw new \Exception("Unknown entity type: {$task->entity_type}");
        }

        Log::debug('Dispatching task to handler', [
            'task_id' => $task->id,
            'entity_type' => $task->entity_type,
            'handler_class' => get_class($handler)
        ]);

        // Делегировать обработку handler-у
        $handler->handle($task, $accountsCache, $settingsCache);
    }

    /**
     * Получить handler для entity_type
     *
     * @param string $entityType
     * @return SyncTaskHandler|null
     */
    public function getHandler(string $entityType): ?SyncTaskHandler
    {
        return $this->handlers[$entityType] ?? null;
    }

    /**
     * Проверить, зарегистрирован ли handler для entity_type
     *
     * @param string $entityType
     * @return bool
     */
    public function hasHandler(string $entityType): bool
    {
        return isset($this->handlers[$entityType]);
    }

    /**
     * Получить список всех зарегистрированных entity types
     *
     * @return array<string>
     */
    public function getRegisteredEntityTypes(): array
    {
        return array_keys($this->handlers);
    }
}
