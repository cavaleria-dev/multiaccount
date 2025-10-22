<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SyncSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Сервис для синхронизации изображений товаров, комплектов и модификаций
 *
 * Workflow:
 * 1. Download image from main account (using downloadHref)
 * 2. Save to temporary storage (storage/app/temp_images/)
 * 3. Convert to base64
 * 4. Upload to child account via МойСклад API
 * 5. Delete local file on success
 *
 * Settings:
 * - sync_images: boolean - enable/disable image sync
 * - sync_images_all: boolean - sync all (10 max) or just first image
 */
class ImageSyncService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Синхронизировать все изображения сущности (batch upload с Replace стратегией)
     *
     * Workflow:
     * 1. DELETE всех существующих изображений в child account
     * 2. Download всех изображений из main account
     * 3. Convert to base64
     * 4. Batch POST всех изображений за один запрос (или несколько если > 20MB)
     * 5. Cleanup локальных файлов
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $entityType Тип сущности (product, bundle, variant)
     * @param string $parentEntityId UUID сущности в главном аккаунте
     * @param string $childEntityId UUID сущности в дочернем аккаунте
     * @param array $images Массив изображений из МойСклад API (images.rows)
     * @return bool Успех операции
     */
    public function syncImagesForEntity(
        string $mainAccountId,
        string $childAccountId,
        string $entityType,
        string $parentEntityId,
        string $childEntityId,
        array $images
    ): bool {
        try {
            // Получить настройки синхронизации
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || (!$settings->sync_images && !$settings->sync_images_all)) {
                Log::debug('Image sync is disabled', [
                    'child_account_id' => $childAccountId,
                    'entity_type' => $entityType,
                    'parent_entity_id' => $parentEntityId
                ]);
                return false;
            }

            if (empty($images)) {
                Log::debug('No images to sync', [
                    'entity_type' => $entityType,
                    'parent_entity_id' => $parentEntityId
                ]);
                return true; // Not an error, just nothing to do
            }

            Log::info('Starting batch image sync for entity', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'parent_entity_id' => $parentEntityId,
                'child_entity_id' => $childEntityId,
                'images_count' => count($images)
            ]);

            // 1. DELETE всех существующих изображений (Replace стратегия)
            $this->deleteAllImagesFromEntity($childAccountId, $entityType, $childEntityId);

            // 2. Download & convert all images
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $base64Images = [];
            $localPaths = [];

            foreach ($images as $image) {
                try {
                    $downloadUrl = $image['meta']['downloadHref'] ?? null;
                    $filename = $image['filename'] ?? 'image_' . uniqid() . '.jpg';

                    if (!$downloadUrl) {
                        Log::warning('Image missing downloadHref, skipping', [
                            'entity_type' => $entityType,
                            'entity_id' => $parentEntityId,
                            'filename' => $filename
                        ]);
                        continue;
                    }

                    // Download
                    $localPath = $this->downloadImage($downloadUrl, $mainAccount->access_token, $filename);
                    if (!$localPath) {
                        Log::warning('Failed to download image, skipping', [
                            'download_url' => $downloadUrl,
                            'filename' => $filename
                        ]);
                        continue;
                    }

                    $localPaths[] = $localPath;

                    // Convert to base64
                    $base64Content = $this->convertToBase64($localPath);
                    if (!$base64Content) {
                        Log::warning('Failed to convert image to base64, skipping', [
                            'filename' => $filename
                        ]);
                        continue;
                    }

                    $base64Images[] = [
                        'filename' => uniqid() . '_' . $filename,
                        'content' => $base64Content
                    ];

                } catch (\Exception $e) {
                    Log::error('Failed to process image in batch', [
                        'entity_type' => $entityType,
                        'entity_id' => $parentEntityId,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }

            if (empty($base64Images)) {
                Log::warning('No images successfully processed for upload', [
                    'entity_type' => $entityType,
                    'parent_entity_id' => $parentEntityId
                ]);

                // Cleanup downloaded files
                foreach ($localPaths as $path) {
                    $this->deleteLocalImage($path);
                }

                return false;
            }

            // 3. Разбить на batches если превышен лимит 20MB
            $batches = $this->splitIntoBatches($base64Images, 20 * 1024 * 1024); // 20MB

            Log::info('Images prepared for upload', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'total_images' => count($base64Images),
                'batches_count' => count($batches)
            ]);

            // 4. Batch upload
            $uploadedCount = 0;
            foreach ($batches as $batchIndex => $batch) {
                try {
                    $result = $this->batchUploadImages(
                        $childAccountId,
                        $entityType,
                        $childEntityId,
                        $batch
                    );

                    if ($result) {
                        $uploadedCount += count($batch);
                        Log::debug('Batch uploaded successfully', [
                            'batch_index' => $batchIndex,
                            'images_in_batch' => count($batch)
                        ]);
                    } else {
                        Log::error('Batch upload failed', [
                            'batch_index' => $batchIndex,
                            'images_in_batch' => count($batch)
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Exception during batch upload', [
                        'batch_index' => $batchIndex,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // 5. Cleanup всех локальных файлов
            foreach ($localPaths as $path) {
                $this->deleteLocalImage($path);
            }

            Log::info('Batch image sync completed', [
                'entity_type' => $entityType,
                'parent_entity_id' => $parentEntityId,
                'child_entity_id' => $childEntityId,
                'uploaded_count' => $uploadedCount,
                'total_count' => count($base64Images)
            ]);

            return $uploadedCount > 0;

        } catch (\Exception $e) {
            Log::error('Batch image sync failed completely', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'parent_entity_id' => $parentEntityId,
                'child_entity_id' => $childEntityId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Синхронизировать изображения для сущности (старая версия для обратной совместимости)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $parentEntityType Тип родительской сущности (product, bundle, variant)
     * @param string $parentEntityId UUID родительской сущности в главном аккаунте
     * @param array $images Массив изображений из payload (обычно одно изображение за раз)
     * @return bool Успех операции
     */
    public function syncImages(
        string $mainAccountId,
        string $childAccountId,
        string $parentEntityType,
        string $parentEntityId,
        array $images
    ): bool {
        try {
            // Получить настройки синхронизации
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            if (!$settings || (!$settings->sync_images && !$settings->sync_images_all)) {
                Log::debug('Image sync is disabled', [
                    'child_account_id' => $childAccountId,
                    'parent_entity_type' => $parentEntityType,
                    'parent_entity_id' => $parentEntityId
                ]);
                return false;
            }

            // Получить токен главного аккаунта для скачивания
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();

            $successCount = 0;
            $failCount = 0;

            foreach ($images as $imageData) {
                try {
                    // Extract data from payload
                    $imageUrl = $imageData['image_url'] ?? null;
                    $filename = $imageData['filename'] ?? 'image.jpg';
                    $childEntityId = $imageData['child_entity_id'] ?? null;

                    if (!$imageUrl || !$childEntityId) {
                        Log::error('Missing required image data', [
                            'image_data' => $imageData,
                            'parent_entity_type' => $parentEntityType
                        ]);
                        $failCount++;
                        continue;
                    }

                    Log::info('Starting image sync', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'parent_entity_type' => $parentEntityType,
                        'parent_entity_id' => $parentEntityId,
                        'child_entity_id' => $childEntityId,
                        'filename' => $filename
                    ]);

                    // 1. Download image
                    $localPath = $this->downloadImage($imageUrl, $mainAccount->access_token, $filename);

                    if (!$localPath) {
                        Log::error('Failed to download image', [
                            'image_url' => $imageUrl,
                            'filename' => $filename
                        ]);
                        $failCount++;
                        continue;
                    }

                    // 2. Convert to base64
                    $base64Content = $this->convertToBase64($localPath);

                    if (!$base64Content) {
                        Log::error('Failed to convert image to base64', [
                            'local_path' => $localPath
                        ]);
                        $this->deleteLocalImage($localPath);
                        $failCount++;
                        continue;
                    }

                    // 3. Upload to child account
                    $uploadSuccess = $this->uploadImageToChild(
                        $childAccountId,
                        $parentEntityType,
                        $childEntityId,
                        $base64Content,
                        $filename
                    );

                    if ($uploadSuccess) {
                        Log::info('Image uploaded successfully', [
                            'child_account_id' => $childAccountId,
                            'parent_entity_type' => $parentEntityType,
                            'child_entity_id' => $childEntityId,
                            'filename' => $filename
                        ]);
                        $successCount++;
                    } else {
                        Log::error('Failed to upload image to child', [
                            'child_account_id' => $childAccountId,
                            'parent_entity_type' => $parentEntityType,
                            'child_entity_id' => $childEntityId,
                            'filename' => $filename
                        ]);
                        $failCount++;
                    }

                    // 4. Delete local file (always, even on failure)
                    $this->deleteLocalImage($localPath);

                } catch (\Exception $e) {
                    Log::error('Image sync failed for single image', [
                        'image_data' => $imageData,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $failCount++;
                }
            }

            Log::info('Image sync batch completed', [
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'parent_entity_type' => $parentEntityType,
                'parent_entity_id' => $parentEntityId
            ]);

            // Return true if at least one image succeeded
            return $successCount > 0;

        } catch (\Exception $e) {
            Log::error('Image sync failed completely', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'parent_entity_type' => $parentEntityType,
                'parent_entity_id' => $parentEntityId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Скачать изображение и сохранить локально
     *
     * @param string $downloadUrl Download href from МойСклад
     * @param string $accessToken Access token главного аккаунта
     * @param string $filename Имя файла
     * @return string|null Путь к сохраненному файлу или null при ошибке
     */
    protected function downloadImage(string $downloadUrl, string $accessToken, string $filename): ?string
    {
        try {
            Log::debug('Downloading image', [
                'url' => $downloadUrl,
                'filename' => $filename
            ]);

            // Проверить размер файла перед скачиванием (HEAD запрос)
            try {
                $headResponse = Http::timeout(10)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $accessToken,
                    ])
                    ->head($downloadUrl);

                if ($headResponse->successful()) {
                    $contentLength = $headResponse->header('Content-Length');
                    if ($contentLength && $contentLength > 10 * 1024 * 1024) { // 10MB limit
                        Log::warning('Image too large, skipping download', [
                            'url' => $downloadUrl,
                            'filename' => $filename,
                            'size_mb' => round($contentLength / 1024 / 1024, 2)
                        ]);
                        return null;
                    }
                }
            } catch (\Exception $e) {
                // HEAD request failed, продолжаем с обычной загрузкой
                Log::debug('HEAD request failed, proceeding with download', [
                    'error' => $e->getMessage()
                ]);
            }

            // Download image with МойСклад authorization (increased timeout to 60 seconds)
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept-Encoding' => 'gzip'
                ])
                ->get($downloadUrl);

            if (!$response->successful()) {
                Log::error('Failed to download image - HTTP error', [
                    'url' => $downloadUrl,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }

            // Проверить размер после скачивания
            $imageSize = strlen($response->body());
            if ($imageSize > 10 * 1024 * 1024) { // 10MB limit
                Log::warning('Downloaded image exceeds size limit, skipping', [
                    'url' => $downloadUrl,
                    'filename' => $filename,
                    'size_mb' => round($imageSize / 1024 / 1024, 2)
                ]);
                return null;
            }

            // Generate unique filename to avoid collisions
            $uniqueFilename = uniqid() . '_' . $filename;
            $relativePath = 'temp_images/' . $uniqueFilename;

            // Save to storage/app/temp_images/
            Storage::put($relativePath, $response->body());

            $fullPath = Storage::path($relativePath);

            Log::debug('Image downloaded successfully', [
                'filename' => $filename,
                'local_path' => $fullPath,
                'size_bytes' => strlen($response->body())
            ]);

            return $fullPath;

        } catch (\Exception $e) {
            Log::error('Exception while downloading image', [
                'url' => $downloadUrl,
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Конвертировать файл в base64
     *
     * @param string $filePath Путь к файлу
     * @return string|null Base64 строка или null при ошибке
     */
    protected function convertToBase64(string $filePath): ?string
    {
        try {
            if (!file_exists($filePath)) {
                Log::error('File not found for base64 conversion', [
                    'file_path' => $filePath
                ]);
                return null;
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                Log::error('Failed to read file for base64 conversion', [
                    'file_path' => $filePath
                ]);
                return null;
            }

            $base64 = base64_encode($content);

            Log::debug('Image converted to base64', [
                'file_path' => $filePath,
                'original_size' => strlen($content),
                'base64_size' => strlen($base64)
            ]);

            return $base64;

        } catch (\Exception $e) {
            Log::error('Exception while converting to base64', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Загрузить изображение в дочерний аккаунт
     *
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $entityType Тип сущности (product, bundle, variant)
     * @param string $childEntityId UUID сущности в дочернем аккаунте
     * @param string $base64Content Base64 encoded image
     * @param string $filename Имя файла
     * @return bool Успех операции
     */
    protected function uploadImageToChild(
        string $childAccountId,
        string $entityType,
        string $childEntityId,
        string $base64Content,
        string $filename
    ): bool {
        try {
            // Получить токен дочернего аккаунта
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            Log::debug('Uploading image to child account', [
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'filename' => $filename,
                'base64_size' => strlen($base64Content)
            ]);

            // Upload via МойСклад API (with unique prefix to prevent filename conflicts)
            $uniqueFilename = uniqid() . '_' . $filename;
            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: null,
                    entityType: 'image',
                    entityId: $childEntityId
                )
                ->uploadImage($entityType, $childEntityId, $base64Content, $uniqueFilename);

            if (isset($result['data'])) {
                Log::info('Image uploaded to МойСклад', [
                    'child_account_id' => $childAccountId,
                    'entity_type' => $entityType,
                    'child_entity_id' => $childEntityId,
                    'original_filename' => $filename,
                    'unique_filename' => $uniqueFilename,
                    'image_id' => $result['data']['filename'] ?? 'unknown'
                ]);
                return true;
            }

            Log::error('Image upload returned unexpected response', [
                'result' => $result
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('Failed to upload image to child account', [
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Удалить локальный файл изображения
     *
     * @param string $filePath Путь к файлу
     * @return bool Успех удаления
     */
    protected function deleteLocalImage(string $filePath): bool
    {
        try {
            if (file_exists($filePath)) {
                $deleted = unlink($filePath);
                if ($deleted) {
                    Log::debug('Local image deleted', [
                        'file_path' => $filePath
                    ]);
                    return true;
                } else {
                    Log::warning('Failed to delete local image', [
                        'file_path' => $filePath
                    ]);
                    return false;
                }
            }

            Log::debug('Local image already deleted or not found', [
                'file_path' => $filePath
            ]);
            return true;

        } catch (\Exception $e) {
            Log::error('Exception while deleting local image', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Получить лимит изображений на основе настроек
     *
     * @param SyncSetting $settings Настройки синхронизации
     * @return int 0 = не синхронизировать, 1 = только первое, 10 = все (макс в МойСклад)
     */
    public function getImageLimit(SyncSetting $settings): int
    {
        // Проверяем обе настройки (взаимоисключающие)
        if (!$settings->sync_images && !$settings->sync_images_all) {
            return 0; // Image sync disabled
        }

        if ($settings->sync_images_all) {
            return 10; // МойСклад maximum: 10 images per entity
        }

        return 1; // Only first image (sync_images = true)
    }

    /**
     * Удалить все изображения сущности в child account
     *
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $entityType Тип сущности (product, bundle, variant)
     * @param string $childEntityId UUID сущности в дочернем аккаунте
     * @return bool Успех операции
     */
    protected function deleteAllImagesFromEntity(
        string $childAccountId,
        string $entityType,
        string $childEntityId
    ): bool {
        try {
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            Log::debug('Deleting all images from entity', [
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId
            ]);

            // Get existing images
            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->get("entity/{$entityType}/{$childEntityId}/images");

            $existingImages = $result['data']['rows'] ?? [];

            if (empty($existingImages)) {
                Log::debug('No existing images to delete', [
                    'entity_type' => $entityType,
                    'child_entity_id' => $childEntityId
                ]);
                return true;
            }

            Log::info('Deleting existing images', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'images_count' => count($existingImages)
            ]);

            // Delete each image
            $deletedCount = 0;
            foreach ($existingImages as $image) {
                try {
                    $imageId = $image['id'] ?? null;
                    if (!$imageId) {
                        continue;
                    }

                    $this->moySkladService
                        ->setAccessToken($childAccount->access_token)
                        ->delete("entity/{$entityType}/{$childEntityId}/images/{$imageId}");

                    $deletedCount++;
                } catch (\Exception $e) {
                    Log::warning('Failed to delete image', [
                        'entity_type' => $entityType,
                        'child_entity_id' => $childEntityId,
                        'image_id' => $imageId ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('Images deleted', [
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'deleted_count' => $deletedCount,
                'total_count' => count($existingImages)
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to delete images from entity', [
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Batch upload изображений в child account
     *
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $entityType Тип сущности (product, bundle, variant)
     * @param string $childEntityId UUID сущности в дочернем аккаунте
     * @param array $images Массив изображений [{filename, content}, ...]
     * @return bool Успех операции
     */
    protected function batchUploadImages(
        string $childAccountId,
        string $entityType,
        string $childEntityId,
        array $images
    ): bool {
        try {
            if (empty($images)) {
                return true;
            }

            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            $totalSize = array_sum(array_map(fn($img) => strlen($img['content']), $images));

            Log::debug('Batch uploading images', [
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'images_count' => count($images),
                'total_size_mb' => round($totalSize / 1024 / 1024, 2)
            ]);

            // Batch upload via МойСклад API
            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: null,
                    entityType: 'image_batch',
                    entityId: $childEntityId
                )
                ->batchUploadImages($entityType, $childEntityId, $images);

            if (isset($result['data']) && is_array($result['data'])) {
                Log::info('Batch images uploaded successfully', [
                    'child_account_id' => $childAccountId,
                    'entity_type' => $entityType,
                    'child_entity_id' => $childEntityId,
                    'images_count' => count($images),
                    'uploaded_count' => count($result['data'])
                ]);
                return true;
            }

            Log::error('Batch image upload returned unexpected response', [
                'result' => $result
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('Failed to batch upload images', [
                'child_account_id' => $childAccountId,
                'entity_type' => $entityType,
                'child_entity_id' => $childEntityId,
                'images_count' => count($images),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Разбить массив изображений на batches по размеру
     *
     * @param array $images Массив изображений [{filename, content}, ...]
     * @param int $maxSizeBytes Максимальный размер одного batch (20MB)
     * @return array Массив batches
     */
    protected function splitIntoBatches(array $images, int $maxSizeBytes): array
    {
        $batches = [];
        $currentBatch = [];
        $currentSize = 0;

        foreach ($images as $image) {
            $imageSize = strlen($image['content']);

            // Если один файл больше лимита - добавляем его отдельным batch
            if ($imageSize > $maxSizeBytes) {
                Log::warning('Single image exceeds batch size limit', [
                    'filename' => $image['filename'],
                    'size_mb' => round($imageSize / 1024 / 1024, 2),
                    'limit_mb' => round($maxSizeBytes / 1024 / 1024, 2)
                ]);

                // Если текущий batch не пустой - сохраняем его
                if (!empty($currentBatch)) {
                    $batches[] = $currentBatch;
                    $currentBatch = [];
                    $currentSize = 0;
                }

                // Добавляем большой файл отдельным batch
                $batches[] = [$image];
                continue;
            }

            // Если добавление файла превысит лимит - начинаем новый batch
            if ($currentSize + $imageSize > $maxSizeBytes) {
                $batches[] = $currentBatch;
                $currentBatch = [$image];
                $currentSize = $imageSize;
            } else {
                $currentBatch[] = $image;
                $currentSize += $imageSize;
            }
        }

        // Добавить последний batch если не пустой
        if (!empty($currentBatch)) {
            $batches[] = $currentBatch;
        }

        return $batches;
    }
}
