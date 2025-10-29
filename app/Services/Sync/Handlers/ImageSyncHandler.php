<?php

namespace App\Services\Sync\Handlers;

use App\Models\SyncQueue;
use App\Services\ImageSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler для синхронизации изображений
 *
 * Обрабатывает entity_type: 'image_sync'
 */
class ImageSyncHandler extends SyncTaskHandler
{
    public function __construct(
        protected ImageSyncService $imageSyncService
    ) {}

    public function getEntityType(): string
    {
        return 'image_sync';
    }

    protected function handleSync(
        SyncQueue $task,
        array $payload,
        Collection $accountsCache,
        Collection $settingsCache
    ): void {
        $mainAccountId = $payload['main_account_id'];
        $childAccountId = $payload['child_account_id'] ?? $task->account_id;

        // Читаем parent_entity_type (новый формат) с fallback на entity_type (старый формат)
        $entityType = $payload['parent_entity_type'] ?? $payload['entity_type'] ?? null;

        if (!$entityType) {
            throw new \Exception('Invalid payload: missing parent_entity_type for image sync');
        }

        // Проверяем формат payload: batch (с массивом images) или legacy (с image_url)
        if (isset($payload['images']) && is_array($payload['images'])) {
            // Batch формат: синхронизация всех изображений сущности
            $this->handleBatchImageSync($task, $payload, $mainAccountId, $childAccountId, $entityType);
        } else {
            // Legacy формат: синхронизация отдельных изображений
            $this->handleLegacyImageSync($task, $payload, $mainAccountId, $childAccountId, $entityType);
        }
    }

    /**
     * Обработка batch синхронизации (новый формат с массивом images)
     */
    protected function handleBatchImageSync(
        SyncQueue $task,
        array $payload,
        string $mainAccountId,
        string $childAccountId,
        string $entityType
    ): void {
        $parentEntityId = $payload['parent_entity_id'] ?? null;
        $childEntityId = $payload['child_entity_id'] ?? null;
        $images = $payload['images'] ?? [];

        if (!$parentEntityId || !$childEntityId) {
            throw new \Exception('Invalid payload: missing parent_entity_id or child_entity_id for batch image sync');
        }

        Log::info('Batch image sync started', [
            'task_id' => $task->id,
            'entity_type' => $entityType,
            'parent_entity_id' => $parentEntityId,
            'child_entity_id' => $childEntityId,
            'images_count' => count($images),
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId
        ]);

        $result = $this->imageSyncService->syncImagesForEntity(
            $mainAccountId,
            $childAccountId,
            $entityType,
            $parentEntityId,
            $childEntityId,
            $images
        );

        if (!$result) {
            throw new \Exception('Batch image sync failed - no images were successfully synced');
        }

        $this->logSuccess($task, [
            'entity_type' => $entityType,
            'parent_entity_id' => $parentEntityId,
            'child_entity_id' => $childEntityId,
            'images_count' => count($images),
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId
        ]);
    }

    /**
     * Обработка legacy синхронизации (старый формат с image_url)
     */
    protected function handleLegacyImageSync(
        SyncQueue $task,
        array $payload,
        string $mainAccountId,
        string $childAccountId,
        string $entityType
    ): void {
        $parentEntityId = $payload['parent_entity_id'] ?? null;
        $childEntityId = $payload['child_entity_id'] ?? null;
        $imageUrl = $payload['image_url'] ?? null;
        $filename = $payload['filename'] ?? null;

        if (!$parentEntityId || !$childEntityId || !$imageUrl || !$filename) {
            throw new \Exception('Invalid payload: missing parent_entity_id, child_entity_id, image_url or filename for legacy image sync');
        }

        Log::info('Legacy image sync started', [
            'task_id' => $task->id,
            'entity_type' => $entityType,
            'parent_entity_id' => $parentEntityId,
            'child_entity_id' => $childEntityId,
            'filename' => $filename,
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId
        ]);

        // Вызываем syncImages с single image as array (legacy формат)
        $result = $this->imageSyncService->syncImages(
            $mainAccountId,
            $childAccountId,
            $entityType,
            $parentEntityId,
            [$payload] // Pass single image as array
        );

        if (!$result) {
            throw new \Exception('Legacy image sync failed - no images were successfully synced');
        }

        $this->logSuccess($task, [
            'entity_type' => $entityType,
            'parent_entity_id' => $parentEntityId,
            'child_entity_id' => $childEntityId,
            'filename' => $filename,
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId
        ]);
    }
}
