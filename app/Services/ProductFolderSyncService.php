<?php

namespace App\Services;

use App\Models\Account;
use App\Models\EntityMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации групп товаров (productFolder)
 *
 * Рекурсивно создает всю иерархию групп от корня до конечной папки
 */
class ProductFolderSyncService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Синхронизировать группу товаров
     *
     * Рекурсивно создает всю иерархию групп
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $folderId UUID группы в главном аккаунте
     * @return string|null UUID созданной/найденной группы в дочернем аккаунте
     */
    public function syncProductFolder(string $mainAccountId, string $childAccountId, string $folderId): ?string
    {
        try {
            // Проверить маппинг группы
            $mapping = EntityMapping::where('parent_account_id', $mainAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_entity_id', $folderId)
                ->where('entity_type', 'productfolder')
                ->first();

            if ($mapping) {
                return $mapping->child_entity_id;
            }

            // Получить группу из главного аккаунта
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $folderResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->get("entity/productfolder/{$folderId}");

            $folder = $folderResult['data'];

            // Подготовить данные для создания
            $folderData = [
                'name' => $folder['name'],
                'externalCode' => $folder['externalCode'] ?? null,
            ];

            // Если у группы есть родитель - синхронизировать его сначала (рекурсия)
            if (isset($folder['productFolder'])) {
                $parentFolderHref = $folder['productFolder']['meta']['href'] ?? null;
                if ($parentFolderHref) {
                    $parentFolderId = $this->extractEntityId($parentFolderHref);
                    if ($parentFolderId) {
                        $childParentFolderId = $this->syncProductFolder($mainAccountId, $childAccountId, $parentFolderId);
                        if ($childParentFolderId) {
                            $folderData['productFolder'] = [
                                'meta' => [
                                    'href' => config('moysklad.api_url') . "/entity/productfolder/{$childParentFolderId}",
                                    'type' => 'productfolder',
                                    'mediaType' => 'application/json'
                                ]
                            ];
                        }
                    }
                }
            }

            // Создать группу в дочернем аккаунте
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
            $newFolderResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->post('entity/productfolder', $folderData);

            $newFolder = $newFolderResult['data'];

            // Сохранить маппинг
            EntityMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'entity_type' => 'productfolder',
                'parent_entity_id' => $folderId,
                'child_entity_id' => $newFolder['id'],
                'sync_direction' => 'main_to_child',
            ]);

            Log::info('Product folder created in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'main_folder_id' => $folderId,
                'child_folder_id' => $newFolder['id'],
                'folder_name' => $folder['name']
            ]);

            return $newFolder['id'];

        } catch (\Exception $e) {
            Log::error('Failed to sync product folder', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'folder_id' => $folderId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Извлечь ID сущности из href
     */
    protected function extractEntityId(string $href): ?string
    {
        if (empty($href)) {
            return null;
        }

        $parts = explode('/', $href);
        return end($parts) ?: null;
    }
}
