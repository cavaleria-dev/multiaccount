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
     * Синхронизировать изображения для сущности
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

            if (!$settings || !$settings->sync_images) {
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

            // Download image with МойСклад authorization
            $response = Http::timeout(30)
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

            // Upload via МойСклад API
            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: null,
                    entityType: 'image',
                    entityId: $childEntityId,
                    operationType: 'upload_image'
                )
                ->uploadImage($entityType, $childEntityId, $base64Content, $filename);

            if (isset($result['data'])) {
                Log::info('Image uploaded to МойСклад', [
                    'child_account_id' => $childAccountId,
                    'entity_type' => $entityType,
                    'child_entity_id' => $childEntityId,
                    'filename' => $filename,
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
        if (!$settings->sync_images) {
            return 0; // Image sync disabled
        }

        if ($settings->sync_images_all) {
            return 10; // МойСклад maximum: 10 images per entity
        }

        return 1; // Only first image
    }
}
