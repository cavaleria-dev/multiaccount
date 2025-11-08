<?php

namespace App\Services\Sync\Handlers\Traits;

use App\Models\Account;
use App\Models\EntityMapping;
use App\Models\SyncSetting;
use App\Services\Webhook\FieldClassifierService;
use App\Services\Webhook\UpdateStrategyService;
use App\Services\Webhook\PartialUpdateService;
use Illuminate\Support\Facades\Log;

/**
 * HandlesPartialUpdates Trait
 *
 * Provides intelligent partial update logic for sync handlers
 *
 * Usage in sync handlers:
 * ```php
 * class ProductSyncHandler implements SyncTaskHandlerInterface
 * {
 *     use HandlesPartialUpdates;
 *
 *     public function handle(SyncQueue $task): void
 *     {
 *         // Check if partial update is possible
 *         if ($this->shouldAttemptPartialUpdate($task)) {
 *             if ($this->handlePartialUpdate($task, 'product')) {
 *                 return; // Partial update successful
 *             }
 *             // Fall through to full sync
 *         }
 *
 *         // Full sync logic...
 *     }
 * }
 * ```
 */
trait HandlesPartialUpdates
{
    /**
     * Check if task should attempt partial update
     *
     * @param \App\Models\SyncQueue $task Sync task
     * @return bool True if partial update should be attempted
     */
    protected function shouldAttemptPartialUpdate($task): bool
    {
        // Only for sync operations (not create/delete/archive)
        if ($task->operation !== 'sync') {
            return false;
        }

        // Check if updated_fields is present in payload
        $updatedFields = $task->payload['updated_fields'] ?? null;

        if (empty($updatedFields) || !is_array($updatedFields)) {
            return false;
        }

        Log::debug('Partial update eligible', [
            'task_id' => $task->id,
            'entity_type' => $task->entity_type,
            'updated_fields' => $updatedFields,
        ]);

        return true;
    }

    /**
     * Handle partial update for a sync task
     *
     * @param \App\Models\SyncQueue $task Sync task
     * @param string $entityType Entity type (product, service, variant, bundle)
     * @return bool True if partial update was successful
     */
    protected function handlePartialUpdate($task, string $entityType): bool
    {
        try {
            $payload = $task->payload;
            $updatedFields = $payload['updated_fields'];
            $mainAccountId = $payload['main_account_id'];
            $childAccountId = $task->account_id;
            $mainEntityId = $task->entity_id;

            Log::info('Attempting partial update', [
                'task_id' => $task->id,
                'entity_type' => $entityType,
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'main_entity_id' => $mainEntityId,
                'updated_fields' => $updatedFields,
            ]);

            // 1. Get accounts
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            // 2. Get sync settings
            $settings = SyncSetting::where('account_id', $childAccountId)->firstOrFail();

            // 3. Find entity mapping (must exist for UPDATE)
            $mapping = EntityMapping::where([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'parent_entity_id' => $mainEntityId,
                'entity_type' => $entityType,
                'sync_direction' => 'main_to_child',
            ])->first();

            if (!$mapping) {
                Log::warning('Entity mapping not found - falling back to full sync', [
                    'task_id' => $task->id,
                    'entity_type' => $entityType,
                    'main_entity_id' => $mainEntityId,
                    'child_account_id' => $childAccountId,
                ]);
                return false; // Fall back to full sync
            }

            $childEntityId = $mapping->child_entity_id;

            // 4. Classify fields
            $classifier = app(FieldClassifierService::class);
            $classified = $classifier->classify($entityType, $updatedFields, $mainAccountId);

            // 5. Determine update strategy
            $strategyService = app(UpdateStrategyService::class);
            $strategyData = $strategyService->determine($classified, $settings, $mainAccountId);

            // 6. Check if we should skip
            if ($strategyData['strategy'] === UpdateStrategyService::STRATEGY_SKIP) {
                Log::info('Partial update skipped - all fields filtered', [
                    'task_id' => $task->id,
                    'entity_type' => $entityType,
                    'reason' => $strategyData['reason'] ?? 'unknown',
                ]);

                // Mark task as completed (nothing to sync)
                $task->markAsCompleted('Skipped - all fields filtered by sync_settings');
                return true; // Skip successful (no update needed)
            }

            // 7. Execute partial update
            $partialUpdateService = app(PartialUpdateService::class);
            $updateLog = $partialUpdateService->update(
                $entityType,
                $mainAccount,
                $childAccount,
                $mainEntityId,
                $childEntityId,
                $strategyData,
                $settings,
                $updatedFields
            );

            // 8. Check result
            if ($updateLog->isSuccessful()) {
                Log::info('Partial update completed successfully', [
                    'task_id' => $task->id,
                    'update_log_id' => $updateLog->id,
                    'strategy' => $strategyData['strategy'],
                    'fields_applied' => $updateLog->fields_applied,
                ]);

                $task->markAsCompleted("Partial update: {$strategyData['strategy']}");
                return true;
            } else {
                Log::warning('Partial update failed - falling back to full sync', [
                    'task_id' => $task->id,
                    'update_log_id' => $updateLog->id,
                    'error' => $updateLog->error_message,
                ]);
                return false; // Fall back to full sync
            }

        } catch (\Exception $e) {
            Log::error('Partial update exception - falling back to full sync', [
                'task_id' => $task->id,
                'entity_type' => $entityType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false; // Fall back to full sync
        }
    }
}
