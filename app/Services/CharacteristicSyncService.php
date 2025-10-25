<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CharacteristicMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации характеристик модификаций (variant characteristics)
 *
 * Обеспечивает проактивную синхронизацию характеристик перед синхронизацией модификаций,
 * предотвращая ошибку 10002 (duplicate characteristic name) при создании variants.
 *
 * Алгоритм:
 * 1. Получить все characteristics из child account
 * 2. Для каждой характеристики из main:
 *    - Найти в child по name
 *    - Если найдена → создать/обновить маппинг
 *    - Если не найдена → создать новую через POST → сохранить маппинг
 * 3. Результат: все маппинги готовы для использования в syncCharacteristics()
 */
class CharacteristicSyncService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Синхронизировать характеристики перед синхронизацией variants
     *
     * Получает все characteristics из main и child, сопоставляет их по name,
     * создает недостающие маппинги и characteristics.
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param array $mainCharacteristics Массив характеристик из main account (уже уникальные по name)
     * @return array Статистика синхронизации ['mapped' => int, 'created' => int, 'failed' => int]
     */
    public function syncCharacteristics(
        string $mainAccountId,
        string $childAccountId,
        array $mainCharacteristics
    ): array {
        if (empty($mainCharacteristics)) {
            Log::debug('No characteristics to sync');
            return ['mapped' => 0, 'created' => 0, 'failed' => 0];
        }

        $startTime = microtime(true);

        Log::info('Starting characteristics sync', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'characteristics_count' => count($mainCharacteristics)
        ]);

        try {
            // Получить child account для API запросов
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            // Получить все существующие характеристики из child account
            $existingCharacteristics = $this->fetchExistingCharacteristics($childAccount, $childAccountId, $mainAccountId);

            // Статистика
            $stats = [
                'mapped' => 0,
                'created' => 0,
                'failed' => 0
            ];

            // Обработать каждую характеристику из main
            foreach ($mainCharacteristics as $mainChar) {
                $charName = $mainChar['name'] ?? null;

                if (!$charName) {
                    Log::warning('Characteristic without name, skipping', [
                        'characteristic' => $mainChar
                    ]);
                    $stats['failed']++;
                    continue;
                }

                try {
                    // Проверить, есть ли уже маппинг в БД
                    $existingMapping = CharacteristicMapping::where('parent_account_id', $mainAccountId)
                        ->where('child_account_id', $childAccountId)
                        ->where('characteristic_name', $charName)
                        ->first();

                    if ($existingMapping) {
                        // Маппинг уже есть - проверить, валиден ли он
                        if (isset($existingCharacteristics[$charName])) {
                            // Характеристика существует в child - обновить маппинг если ID изменился
                            $childCharId = $existingCharacteristics[$charName];

                            if ($existingMapping->child_characteristic_id !== $childCharId) {
                                Log::info('Updating stale characteristic mapping', [
                                    'characteristic_name' => $charName,
                                    'old_child_id' => $existingMapping->child_characteristic_id,
                                    'new_child_id' => $childCharId
                                ]);

                                $existingMapping->update([
                                    'child_characteristic_id' => $childCharId,
                                    'parent_characteristic_id' => $mainChar['id']
                                ]);
                            }

                            $stats['mapped']++;
                            continue;
                        } else {
                            // Характеристика не существует в child - удалить stale маппинг
                            Log::warning('Deleting stale characteristic mapping (not found in child)', [
                                'characteristic_name' => $charName,
                                'stale_child_id' => $existingMapping->child_characteristic_id
                            ]);
                            $existingMapping->delete();
                            // Продолжить создание характеристики ниже
                        }
                    }

                    // Проверить, существует ли характеристика в child
                    if (isset($existingCharacteristics[$charName])) {
                        // Характеристика есть в child - создать маппинг
                        CharacteristicMapping::create([
                            'parent_account_id' => $mainAccountId,
                            'child_account_id' => $childAccountId,
                            'parent_characteristic_id' => $mainChar['id'],
                            'child_characteristic_id' => $existingCharacteristics[$charName],
                            'characteristic_name' => $charName,
                            'auto_created' => false // Не создавали, а нашли существующую
                        ]);

                        Log::debug('Characteristic mapped to existing in child', [
                            'characteristic_name' => $charName,
                            'child_characteristic_id' => $existingCharacteristics[$charName]
                        ]);

                        $stats['mapped']++;
                    } else {
                        // Характеристики нет в child - создать новую
                        $mapping = $this->createCharacteristicInChild(
                            $mainAccountId,
                            $childAccountId,
                            $childAccount,
                            $mainChar
                        );

                        if ($mapping) {
                            $stats['created']++;
                        } else {
                            $stats['failed']++;
                        }
                    }

                } catch (\Exception $e) {
                    Log::error('Failed to sync characteristic', [
                        'characteristic_name' => $charName,
                        'error' => $e->getMessage()
                    ]);
                    $stats['failed']++;
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000); // ms

            Log::info('Characteristics sync completed', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'duration_ms' => $duration,
                'stats' => $stats
            ]);

            return $stats;

        } catch (\Exception $e) {
            Log::error('Characteristics sync failed', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Получить все существующие характеристики из child account
     *
     * @param Account $childAccount Модель дочернего аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $mainAccountId UUID главного аккаунта (для логирования)
     * @return array Ассоциативный массив [name => id]
     */
    public function fetchExistingCharacteristics(
        Account $childAccount,
        string $childAccountId,
        string $mainAccountId
    ): array {
        try {
            $response = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $mainAccountId,
                    entityType: 'characteristic',
                    entityId: null
                )
                ->get('entity/variant/metadata/characteristics', [
                    'limit' => 1000 // МойСклад лимит для metadata
                ]);

            $characteristics = $response['data']['rows'] ?? [];

            // Преобразовать в ассоциативный массив [name => id] для быстрого поиска
            $characteristicsMap = [];
            foreach ($characteristics as $char) {
                $name = $char['name'] ?? null;
                $id = $char['id'] ?? null;

                if ($name && $id) {
                    $characteristicsMap[$name] = $id;
                }
            }

            Log::debug('Fetched existing characteristics from child', [
                'child_account_id' => $childAccountId,
                'count' => count($characteristicsMap)
            ]);

            return $characteristicsMap;

        } catch (\Exception $e) {
            Log::error('Failed to fetch existing characteristics from child', [
                'child_account_id' => $childAccountId,
                'error' => $e->getMessage()
            ]);

            // Вернуть пустой массив - характеристики будут созданы через POST
            return [];
        }
    }

    /**
     * Создать новую характеристику в child account через POST
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param Account $childAccount Модель дочернего аккаунта
     * @param array $characteristic Данные характеристики из main account
     * @return CharacteristicMapping|null Созданный маппинг или null при ошибке
     */
    protected function createCharacteristicInChild(
        string $mainAccountId,
        string $childAccountId,
        Account $childAccount,
        array $characteristic
    ): ?CharacteristicMapping {
        try {
            $charData = [
                'name' => $characteristic['name'],
                'type' => $characteristic['type'] ?? 'string',
                'required' => $characteristic['required'] ?? false,
            ];

            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->setLogContext(
                    accountId: $childAccountId,
                    direction: 'main_to_child',
                    relatedAccountId: $mainAccountId,
                    entityType: 'characteristic',
                    entityId: null
                )
                ->post('entity/variant/metadata/characteristics', $charData);

            $newChar = $result['data'];

            // Сохранить маппинг
            $mapping = CharacteristicMapping::create([
                'parent_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'parent_characteristic_id' => $characteristic['id'],
                'child_characteristic_id' => $newChar['id'],
                'characteristic_name' => $characteristic['name'],
                'auto_created' => true,
            ]);

            Log::info('Characteristic created in child account', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'characteristic_name' => $characteristic['name'],
                'child_characteristic_id' => $newChar['id']
            ]);

            return $mapping;

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // Fallback для ошибки 10002 (duplicate characteristic name)
            if (str_contains($errorMessage, '10002') || str_contains($errorMessage, 'уже существуют')) {
                Log::warning('Characteristic already exists (10002), attempting fallback with retry', [
                    'main_account_id' => $mainAccountId,
                    'child_account_id' => $childAccountId,
                    'characteristic_name' => $characteristic['name'],
                    'error_code' => '10002'
                ]);

                // Попытаться найти существующую характеристику и создать маппинг (с retry)
                $mapping = $this->findAndMapExistingCharacteristic(
                    $mainAccountId,
                    $childAccountId,
                    $childAccount,
                    $characteristic
                );

                if ($mapping) {
                    Log::info('10002 fallback succeeded - characteristic mapped', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'characteristic_name' => $characteristic['name'],
                        'child_characteristic_id' => $mapping->child_characteristic_id
                    ]);
                    return $mapping;
                } else {
                    Log::error('10002 fallback failed - characteristic not found after retries', [
                        'main_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'characteristic_name' => $characteristic['name'],
                        'original_error' => $errorMessage
                    ]);
                    return null;
                }
            }

            Log::error('Failed to create characteristic in child account (non-10002 error)', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'characteristic' => $characteristic,
                'error' => $errorMessage
            ]);

            return null;
        }
    }

    /**
     * Найти существующую характеристику в child и создать маппинг
     *
     * Используется как fallback при ошибке 10002 (race condition: характеристика
     * была создана между GET и POST запросами).
     *
     * Алгоритм с retry:
     * 1. Попытка 1: Получить свежий список характеристик, найти и смапить
     * 2. Если не найдена: подождать 200ms (характеристика может дорепликироваться)
     * 3. Попытка 2: Повторить GET запрос, найти и смапить
     * 4. Если не найдена: подождать 500ms
     * 5. Попытка 3: Финальная попытка
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param Account $childAccount Модель дочернего аккаунта
     * @param array $characteristic Данные характеристики из main account
     * @return CharacteristicMapping|null Созданный маппинг или null при ошибке
     */
    public function findAndMapExistingCharacteristic(
        string $mainAccountId,
        string $childAccountId,
        Account $childAccount,
        array $characteristic
    ): ?CharacteristicMapping {
        $charName = $characteristic['name'];

        // Note: Retries are now handled by the queue system (ProcessSyncQueueJob) with exponential backoff
        // to avoid blocking the worker thread with usleep(). Single attempt here.
        try {
            // Получить свежий список характеристик из child
            $existingCharacteristics = $this->fetchExistingCharacteristics(
                $childAccount,
                $childAccountId,
                $mainAccountId
            );

            // Найти характеристику по name
            if (isset($existingCharacteristics[$charName])) {
                $childCharId = $existingCharacteristics[$charName];

                // Создать маппинг (atomic operation)
                $mapping = CharacteristicMapping::firstOrCreate(
                    [
                        'parent_account_id' => $mainAccountId,
                        'child_account_id' => $childAccountId,
                        'parent_characteristic_id' => $characteristic['id'],
                        'characteristic_name' => $charName,
                    ],
                    [
                        'child_characteristic_id' => $childCharId,
                        'auto_created' => false // Нашли существующую, а не создали
                    ]
                );

                Log::info('Found and mapped existing characteristic after 10002 error', [
                    'main_account_id' => $mainAccountId,
                    'child_account_id' => $childAccountId,
                    'characteristic_name' => $charName,
                    'child_characteristic_id' => $childCharId
                ]);

                return $mapping;
            }

            // Характеристика не найдена
            Log::warning('Characteristic not found in child (10002 fallback failed)', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'characteristic_name' => $charName
            ]);

        } catch (\Exception $e) {
            Log::error('Error during characteristic search', [
                'main_account_id' => $mainAccountId,
                'child_account_id' => $childAccountId,
                'characteristic_name' => $charName,
                'error' => $e->getMessage()
            ]);
            return null;
        }

        // Характеристика не найдена - вернуть null, вызывающий код обработает
        // Queue system will retry if needed with exponential backoff
        Log::error('Characteristic not found in child (10002 fallback failed)', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'characteristic_name' => $charName
        ]);

        return null;
    }

    /**
     * Проверить и очистить stale маппинги характеристик
     *
     * Загружает все characteristics из child аккаунта и проверяет,
     * существуют ли child_characteristic_id из маппингов.
     * Удаляет stale маппинги (где характеристика удалена в child).
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @return array ['checked' => int, 'deleted' => int]
     */
    public function cleanupStaleMappings(
        string $mainAccountId,
        string $childAccountId
    ): array {
        Log::info('Starting characteristic stale mappings cleanup', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId
        ]);

        // 1. Загрузить ВСЕ characteristics из child аккаунта
        $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

        $childCharacteristics = $this->moySkladService
            ->setAccessToken($childAccount->access_token)
            ->get('/entity/product/metadata/characteristics');

        $childCharacteristicIds = collect($childCharacteristics['data']['rows'] ?? [])
            ->pluck('id')
            ->toArray();

        Log::debug('Loaded child characteristics', [
            'count' => count($childCharacteristicIds)
        ]);

        // 2. Получить все маппинги для этой пары аккаунтов
        $mappings = CharacteristicMapping::where('parent_account_id', $mainAccountId)
            ->where('child_account_id', $childAccountId)
            ->get();

        $checkedCount = $mappings->count();
        $deletedCount = 0;

        // 3. Проверить каждый маппинг
        foreach ($mappings as $mapping) {
            $childCharId = $mapping->child_characteristic_id;

            // Если child_characteristic_id НЕ существует в child аккаунте
            if (!in_array($childCharId, $childCharacteristicIds)) {
                Log::warning('Stale characteristic mapping detected', [
                    'mapping_id' => $mapping->id,
                    'parent_characteristic_id' => $mapping->parent_characteristic_id,
                    'child_characteristic_id' => $childCharId,
                    'characteristic_name' => $mapping->characteristic_name
                ]);

                // Удалить stale маппинг
                $mapping->delete();
                $deletedCount++;
            }
        }

        Log::info('Characteristic stale mappings cleanup completed', [
            'main_account_id' => $mainAccountId,
            'child_account_id' => $childAccountId,
            'checked_count' => $checkedCount,
            'deleted_count' => $deletedCount
        ]);

        return [
            'checked' => $checkedCount,
            'deleted' => $deletedCount
        ];
    }
}
