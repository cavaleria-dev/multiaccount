<?php

namespace App\Services;

use App\Models\Account;
use App\Models\EntityMapping;
use App\Models\SyncSetting;
use App\Models\SyncQueue;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для batch синхронизации модификаций (variants)
 */
class BatchVariantSyncService
{
    public function __construct(
        protected MoySkladService $moySkladService,
        protected VariantSyncService $variantSyncService,
        protected CharacteristicSyncService $characteristicSyncService
    ) {}

    /**
     * Batch синхронизация модификаций
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param array $variants Массив variants из МойСклад (уже с expand)
     * @return array ['success' => int, 'failed' => int]
     */
    public function batchSyncVariants(
        string $mainAccountId,
        string $childAccountId,
        array $variants
    ): array {
        if (empty($variants)) {
            return ['success' => 0, 'failed' => 0];
        }

        // Получить accounts и settings
        $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
        $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
        $syncSettings = SyncSetting::where('account_id', $childAccountId)->firstOrFail();

        Log::info('Batch variant sync started', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'variants_count' => count($variants)
        ]);

        // PHASE 0: Cleanup stale characteristic mappings
        try {
            $cleanupResult = $this->characteristicSyncService->cleanupStaleMappings(
                $mainAccountId,
                $childAccountId
            );

            Log::info('Stale characteristic mappings cleaned up', [
                'checked' => $cleanupResult['checked'],
                'deleted' => $cleanupResult['deleted']
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cleanup stale characteristic mappings', [
                'error' => $e->getMessage()
            ]);
            // Не прерываем выполнение - продолжаем синхронизацию
        }

        // PHASE 1: Пре-синхронизация характеристик (один раз для всех variants)
        $this->preSyncCharacteristics($mainAccountId, $childAccountId, $variants);

        // PHASE 2: Проверить parent product mappings
        $validVariants = [];
        $skippedVariants = [];

        foreach ($variants as $variant) {
            $productId = $this->extractProductId($variant);

            if (!$productId) {
                Log::warning('Variant missing product reference', [
                    'variant_id' => $variant['id'] ?? 'unknown'
                ]);
                $skippedVariants[] = $variant;
                continue;
            }

            // Проверить существует ли маппинг parent product
            $productMapping = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_entity_id', $productId)
                ->where('entity_type', 'product')
                ->first();

            if (!$productMapping) {
                // Parent product еще не синхронизирован - создать retry задачу
                Log::info('Parent product not synced yet, creating retry task', [
                    'variant_id' => $variant['id'],
                    'product_id' => $productId
                ]);

                $this->createRetryTask($mainAccountId, $childAccountId, $variant);
                $skippedVariants[] = $variant;
                continue;
            }

            $validVariants[] = $variant;
        }

        if (empty($validVariants)) {
            Log::info('No valid variants to sync (all skipped)', [
                'total_variants' => count($variants),
                'skipped_count' => count($skippedVariants)
            ]);
            return ['success' => 0, 'failed' => count($skippedVariants)];
        }

        // PHASE 3: Подготовить данные для batch POST
        $preparedVariants = [];
        foreach ($validVariants as $variant) {
            try {
                $variantData = $this->variantSyncService->prepareVariantForBatch(
                    $variant,
                    $mainAccountId,
                    $childAccountId,
                    $syncSettings
                );

                if ($variantData) {
                    $preparedVariants[] = [
                        'original' => $variant,
                        'prepared' => $variantData
                    ];
                }
            } catch (\Exception $e) {
                Log::error('Failed to prepare variant for batch', [
                    'variant_id' => $variant['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        // PHASE 4: Batch POST (100 per request)
        $successCount = 0;
        $failedCount = 0;
        $batches = array_chunk($preparedVariants, 100);

        foreach ($batches as $batchIndex => $batch) {
            try {
                $batchData = array_column($batch, 'prepared');

                $result = $this->moySkladService
                    ->setAccessToken($childAccount->access_token)
                    ->post('/entity/variant', $batchData);

                $createdVariants = $result['data'] ?? [];

                // Создать маппинги для успешных
                foreach ($createdVariants as $index => $createdVariant) {
                    if (isset($createdVariant['id'])) {
                        $originalVariant = $batch[$index]['original'];

                        EntityMapping::firstOrCreate(
                            [
                                'parent_account_id' => $mainAccountId,
                                'child_account_id' => $childAccountId,
                                'entity_type' => 'variant',
                                'parent_entity_id' => $originalVariant['id'],
                                'sync_direction' => 'main_to_child',
                            ],
                            [
                                'child_entity_id' => $createdVariant['id'],
                            ]
                        );

                        $successCount++;

                        // Синхронизировать изображения
                        if ($syncSettings->sync_images || $syncSettings->sync_images_all) {
                            $this->queueImageSync(
                                $mainAccountId,
                                $childAccountId,
                                $originalVariant,
                                $createdVariant,
                                $syncSettings
                            );
                        }
                    }
                }

            } catch (\Exception $e) {
                Log::error('Batch variant POST failed', [
                    'batch_index' => $batchIndex,
                    'batch_size' => count($batch),
                    'error' => $e->getMessage()
                ]);

                // Создать индивидуальные retry задачи
                foreach ($batch as $item) {
                    $this->createRetryTask($mainAccountId, $childAccountId, $item['original']);
                    $failedCount++;
                }
            }
        }

        Log::info('Batch variant sync completed', [
            'total_variants' => count($variants),
            'valid_variants' => count($validVariants),
            'skipped_variants' => count($skippedVariants),
            'success_count' => $successCount,
            'failed_count' => $failedCount
        ]);

        return [
            'success' => $successCount,
            'failed' => $failedCount + count($skippedVariants)
        ];
    }

    /**
     * Пре-синхронизация всех уникальных характеристик
     */
    protected function preSyncCharacteristics(
        string $mainAccountId,
        string $childAccountId,
        array $variants
    ): void {
        $allCharacteristics = collect($variants)
            ->pluck('characteristics')
            ->flatten(1)
            ->unique('name')
            ->filter(fn($char) => !empty($char['name']))
            ->values()
            ->toArray();

        if (empty($allCharacteristics)) {
            return;
        }

        try {
            $stats = $this->characteristicSyncService->syncCharacteristics(
                $mainAccountId,
                $childAccountId,
                $allCharacteristics
            );

            Log::info('Characteristics pre-synced for batch variants', [
                'characteristics_count' => count($allCharacteristics),
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to pre-sync characteristics', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'error' => $e->getMessage()
            ]);
            // Не прерываем выполнение - характеристики будут синхронизированы через fallback
        }
    }

    /**
     * Извлечь product ID из variant
     */
    protected function extractProductId(array $variant): ?string
    {
        $href = $variant['product']['meta']['href'] ?? null;
        if (!$href) {
            return null;
        }

        if (preg_match('/\/([a-f0-9-]{36})$/', $href, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Создать индивидуальную retry задачу для variant
     */
    protected function createRetryTask(
        string $mainAccountId,
        string $childAccountId,
        array $variant
    ): void {
        SyncQueue::create([
            'account_id' => $childAccountId,
            'entity_type' => 'variant',
            'entity_id' => $variant['id'],
            'operation' => 'update',
            'priority' => EntityConfig::get('variant')['batch_priority'], // 6 - same as batch variants
            'scheduled_at' => now()->addMinutes(5), // Retry через 5 минут
            'status' => 'pending',
            'attempts' => 0,
            'payload' => [
                'main_account_id' => $mainAccountId,
                'batch_retry' => true
            ]
        ]);
    }

    /**
     * Поставить в очередь синхронизацию изображений
     */
    protected function queueImageSync(
        string $mainAccountId,
        string $childAccountId,
        array $originalVariant,
        array $createdVariant,
        SyncSetting $settings
    ): void {
        $images = $originalVariant['images']['rows'] ?? [];
        if (empty($images)) {
            return;
        }

        // Получить лимит изображений
        $imageSyncService = app(ImageSyncService::class);
        $limit = $imageSyncService->getImageLimit($settings);

        if ($limit === 0) {
            return;
        }

        $imagesToSync = array_slice($images, 0, $limit);

        SyncQueue::create([
            'account_id' => $childAccountId,
            'entity_type' => 'image_sync',
            'entity_id' => $originalVariant['id'],
            'operation' => 'sync',
            'priority' => 1, // Lowest priority - images sync after all entities
            'status' => 'pending',
            'scheduled_at' => now(),
            'payload' => [
                'main_account_id' => $mainAccountId,
                'parent_entity_type' => 'variant',
                'parent_entity_id' => $originalVariant['id'],
                'child_entity_id' => $createdVariant['id'],
                'images' => $imagesToSync
            ]
        ]);
    }
}
