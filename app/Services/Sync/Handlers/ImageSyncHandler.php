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
        $childAccountId = $task->account_id;
        $entityType = $payload['entity_type'] ?? null;
        $entityId = $payload['entity_id'] ?? $task->entity_id;

        if (!$entityType) {
            throw new \Exception('Invalid payload: missing entity_type for image sync');
        }

        Log::info('Image sync started', [
            'task_id' => $task->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId
        ]);

        $this->imageSyncService->syncImages(
            $mainAccountId,
            $childAccountId,
            $entityType,
            $entityId
        );

        $this->logSuccess($task, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId
        ]);
    }
}
