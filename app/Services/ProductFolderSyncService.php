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
                // Проверить что папка существует в child через GET
                try {
                    $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
                    $this->moySkladService
                        ->setAccessToken($childAccount->access_token)
                        ->get("entity/productfolder/{$mapping->child_entity_id}");

                    // Папка существует
                    return $mapping->child_entity_id;

                } catch (\Exception $e) {
                    // Папка удалена (404) - удалить stale mapping и создать заново
                    Log::warning('ProductFolder mapping exists but entity deleted in child, recreating', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'parent_folder_id' => $folderId,
                        'stale_child_id' => $mapping->child_entity_id,
                        'error' => $e->getMessage()
                    ]);

                    $mapping->delete();
                    // Продолжить выполнение - создать папку заново (код ниже)
                }
            }

            // Получить группу из главного аккаунта
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $folderResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->get("entity/productfolder/{$folderId}");

            $folder = $folderResult['data'];

            // Синхронизировать родительскую папку (если есть) перед поиском/созданием текущей
            $childParentFolderId = null;
            if (isset($folder['productFolder'])) {
                $parentFolderHref = $folder['productFolder']['meta']['href'] ?? null;
                if ($parentFolderHref) {
                    $parentFolderId = $this->extractEntityId($parentFolderHref);
                    if ($parentFolderId) {
                        $childParentFolderId = $this->syncProductFolder($mainAccountId, $childAccountId, $parentFolderId);
                    }
                }
            }

            // Попытаться найти существующую папку по имени и родителю
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
            $existingFolder = $this->findExistingFolder($childAccount, $folder['name'], $childParentFolderId);

            if ($existingFolder) {
                // Папка уже существует - создать/обновить маппинг
                EntityMapping::updateOrCreate(
                    [
                        'parent_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'entity_type' => 'productfolder',
                        'parent_entity_id' => $folderId,
                    ],
                    [
                        'child_entity_id' => $existingFolder['id'],
                        'sync_direction' => 'main_to_child',
                        'match_field' => 'name',
                        'match_value' => $folder['name'],
                    ]
                );

                Log::info('Found existing product folder in child account', [
                    'main_account_id' => $mainAccountId,
                    'child_account_id' => $childAccountId,
                    'main_folder_id' => $folderId,
                    'child_folder_id' => $existingFolder['id'],
                    'folder_name' => $folder['name']
                ]);

                return $existingFolder['id'];
            }

            // Папка не найдена - создать новую
            $folderData = [
                'name' => $folder['name'],
                'externalCode' => $folder['externalCode'] ?? null,
            ];

            // Добавить ссылку на родительскую папку (если есть)
            if ($childParentFolderId) {
                $folderData['productFolder'] = [
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/productfolder/{$childParentFolderId}",
                        'type' => 'productfolder',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            $newFolderResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->post('entity/productfolder', $folderData);

            $newFolder = $newFolderResult['data'];

            // Сохранить маппинг (используем updateOrCreate для корректного обновления)
            EntityMapping::updateOrCreate(
                [
                    'parent_account_id' => $mainAccountId,
                    'child_account_id' => $childAccountId,
                    'entity_type' => 'productfolder',
                    'parent_entity_id' => $folderId,
                ],
                [
                    'child_entity_id' => $newFolder['id'],
                    'sync_direction' => 'main_to_child',
                    'match_field' => 'name',
                    'match_value' => $folder['name'],
                ]
            );

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
     * Синхронизировать группы товаров для набора сущностей (batch-оптимизация)
     *
     * Собирает уникальные productFolder из всех сущностей, строит дерево зависимостей
     * и синхронизирует группы в правильном порядке (родители → дети)
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param array $entities Массив товаров/комплектов/услуг с productFolder
     * @return array Маппинги [mainFolderId => childFolderId]
     */
    public function syncFoldersForEntities(string $mainAccountId, string $childAccountId, array $entities): array
    {
        try {
            // 1. Собрать уникальные productFolder hrefs из всех сущностей
            $folderHrefs = [];
            foreach ($entities as $entity) {
                if (!empty($entity['productFolder']['meta']['href'])) {
                    $folderHrefs[] = $entity['productFolder']['meta']['href'];
                }
            }

            if (empty($folderHrefs)) {
                Log::debug('No product folders found in entities', [
                    'main_account_id' => $mainAccountId,
                    'child_account_id' => $childAccountId,
                    'entities_count' => count($entities)
                ]);
                return [];
            }

            // Удалить дубликаты
            $folderHrefs = array_unique($folderHrefs);

            // 2. Извлечь folder IDs из hrefs
            $folderIds = [];
            foreach ($folderHrefs as $href) {
                $folderId = $this->extractEntityId($href);
                if ($folderId) {
                    $folderIds[] = $folderId;
                }
            }

            if (empty($folderIds)) {
                return [];
            }

            Log::info('Syncing product folders for entities', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'unique_folders_count' => count($folderIds),
                'total_entities' => count($entities)
            ]);

            // 3. Загрузить все папки из главного аккаунта
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();
            $folders = [];

            foreach ($folderIds as $folderId) {
                try {
                    $folderResult = $this->moySkladService
                        ->setAccessToken($mainAccount->access_token)
                        ->get("entity/productfolder/{$folderId}");

                    $folders[$folderId] = $folderResult['data'];
                } catch (\Exception $e) {
                    Log::warning('Failed to load product folder, skipping', [
                        'folder_id' => $folderId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if (empty($folders)) {
                return [];
            }

            // 4. Построить дерево зависимостей (найти родительские папки)
            $allFolderIds = array_keys($folders);
            $parentFolderIds = [];

            foreach ($folders as $folderId => $folder) {
                if (!empty($folder['productFolder']['meta']['href'])) {
                    $parentId = $this->extractEntityId($folder['productFolder']['meta']['href']);
                    if ($parentId && !in_array($parentId, $allFolderIds)) {
                        $parentFolderIds[] = $parentId;
                    }
                }
            }

            // Рекурсивно загрузить родительские папки (если они не в списке)
            if (!empty($parentFolderIds)) {
                foreach (array_unique($parentFolderIds) as $parentId) {
                    $this->loadFolderWithParents($mainAccount, $parentId, $folders);
                }
            }

            // 5. Отсортировать папки: сначала родители, потом дети
            $sortedFolders = $this->sortFoldersByHierarchy($folders);

            // 6. Синхронизировать папки в правильном порядке
            $mappings = [];
            foreach ($sortedFolders as $folderId => $folder) {
                $childFolderId = $this->syncProductFolder($mainAccountId, $childAccountId, $folderId);
                if ($childFolderId) {
                    $mappings[$folderId] = $childFolderId;
                }
            }

            Log::info('Product folders synced successfully', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'synced_count' => count($mappings)
            ]);

            return $mappings;

        } catch (\Exception $e) {
            Log::error('Failed to sync folders for entities', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Рекурсивно загрузить папку и всех её родителей
     */
    protected function loadFolderWithParents(Account $mainAccount, string $folderId, array &$folders): void
    {
        // Если уже загружена - пропустить
        if (isset($folders[$folderId])) {
            return;
        }

        try {
            $folderResult = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->get("entity/productfolder/{$folderId}");

            $folder = $folderResult['data'];
            $folders[$folderId] = $folder;

            // Если у папки есть родитель - загрузить его тоже
            if (!empty($folder['productFolder']['meta']['href'])) {
                $parentId = $this->extractEntityId($folder['productFolder']['meta']['href']);
                if ($parentId) {
                    $this->loadFolderWithParents($mainAccount, $parentId, $folders);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to load parent folder, skipping', [
                'folder_id' => $folderId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Отсортировать папки по иерархии (родители → дети)
     *
     * @param array $folders Ассоциативный массив [folderId => folderData]
     * @return array Отсортированный массив [folderId => folderData]
     */
    protected function sortFoldersByHierarchy(array $folders): array
    {
        $sorted = [];
        $processed = [];

        // Функция для рекурсивной обработки папки и её родителей
        $addWithParents = function($folderId) use ($folders, &$sorted, &$processed, &$addWithParents) {
            // Если уже обработана - пропустить
            if (isset($processed[$folderId])) {
                return;
            }

            // Если папки нет в списке - пропустить
            if (!isset($folders[$folderId])) {
                return;
            }

            $folder = $folders[$folderId];

            // Сначала добавить родителя (если есть)
            if (!empty($folder['productFolder']['meta']['href'])) {
                $parentId = $this->extractEntityId($folder['productFolder']['meta']['href']);
                if ($parentId) {
                    $addWithParents($parentId);
                }
            }

            // Затем добавить текущую папку
            $sorted[$folderId] = $folder;
            $processed[$folderId] = true;
        };

        // Обработать все папки
        foreach (array_keys($folders) as $folderId) {
            $addWithParents($folderId);
        }

        return $sorted;
    }

    /**
     * Найти существующую папку в child account по имени и родителю
     *
     * @param Account $childAccount Аккаунт для поиска
     * @param string $name Имя папки
     * @param string|null $parentFolderId UUID родительской папки в child account (null = корень)
     * @return array|null Найденная папка или null
     */
    protected function findExistingFolder(Account $childAccount, string $name, ?string $parentFolderId): ?array
    {
        try {
            // Построить фильтр: name + productFolder
            $filters = ['name=' . urlencode($name)];

            if ($parentFolderId) {
                // Поиск по родительской папке
                $filters[] = 'productFolder=' . config('moysklad.api_url') . '/entity/productfolder/' . $parentFolderId;
            } else {
                // Поиск в корне (папки без родителя)
                // МойСклад не поддерживает filter=productFolder= для поиска корневых папок
                // Поэтому загружаем все папки с таким именем и фильтруем вручную
            }

            $filter = implode(';', $filters);

            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->get("entity/productfolder?filter={$filter}&limit=100");

            $folders = $result['data']['rows'] ?? [];

            if (empty($folders)) {
                return null;
            }

            // Если ищем корневую папку (без родителя)
            if ($parentFolderId === null) {
                // Найти папку без поля productFolder
                foreach ($folders as $folder) {
                    if (!isset($folder['productFolder'])) {
                        return $folder;
                    }
                }
                return null;
            }

            // Если родитель указан - вернуть первую найденную (фильтр уже проверил parent)
            return $folders[0];

        } catch (\Exception $e) {
            Log::debug('Failed to search for existing folder', [
                'child_account_id' => $childAccount->account_id,
                'folder_name' => $name,
                'parent_folder_id' => $parentFolderId,
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

        // Удалить query string если есть (?expand=..., ?filter=..., etc)
        $href = strtok($href, '?');

        $parts = explode('/', $href);
        return end($parts) ?: null;
    }
}
