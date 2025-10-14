<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы со статусами документов МойСклад
 */
class StateService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Получить метаданные документа (включая states)
     *
     * @param string $accountId UUID аккаунта
     * @param string $entityType Тип документа (customerorder, purchaseorder и т.д.)
     * @return array Метаданные документа
     */
    public function getMetadata(string $accountId, string $entityType = 'customerorder'): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get("entity/{$entityType}/metadata");

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to get metadata', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить список статусов для типа документа
     *
     * @param string $accountId UUID аккаунта
     * @param string $entityType Тип документа
     * @return array Список статусов
     */
    public function getStates(string $accountId, string $entityType = 'customerorder'): array
    {
        $metadata = $this->getMetadata($accountId, $entityType);
        return $metadata['states'] ?? [];
    }

    /**
     * Создать статус
     *
     * @param string $accountId UUID аккаунта
     * @param string $entityType Тип документа
     * @param string $name Название статуса
     * @param string|null $color Цвет статуса (hex)
     * @return array Созданный статус
     */
    public function createState(string $accountId, string $entityType, string $name, ?string $color = null): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $data = [
                'name' => $name,
                'stateType' => 'Regular',
            ];

            if ($color) {
                $data['color'] = $color;
            }

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->post("entity/{$entityType}/metadata/states", $data);

            Log::info('State created', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'state_id' => $result['data']['id'] ?? null,
                'name' => $name
            ]);

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to create state', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Найти статус по названию
     *
     * @param string $accountId UUID аккаунта
     * @param string $entityType Тип документа
     * @param string $name Название статуса
     * @return array|null Статус или null если не найден
     */
    public function findStateByName(string $accountId, string $entityType, string $name): ?array
    {
        $states = $this->getStates($accountId, $entityType);

        foreach ($states as $state) {
            if ($state['name'] === $name) {
                return $state;
            }
        }

        return null;
    }

    /**
     * Получить или создать статус (helper метод)
     *
     * @param string $accountId UUID аккаунта
     * @param string $entityType Тип документа
     * @param string $name Название статуса
     * @param string|null $color Цвет статуса
     * @return array Статус
     */
    public function getOrCreateState(string $accountId, string $entityType, string $name, ?string $color = null): array
    {
        $existing = $this->findStateByName($accountId, $entityType, $name);

        if ($existing) {
            Log::info('State already exists', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'state_id' => $existing['id'],
                'name' => $name
            ]);
            return $existing;
        }

        return $this->createState($accountId, $entityType, $name, $color);
    }

    /**
     * Получить статус по ID
     *
     * @param string $accountId UUID аккаунта
     * @param string $entityType Тип документа
     * @param string $stateId UUID статуса
     * @return array Статус
     */
    public function getState(string $accountId, string $entityType, string $stateId): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get("entity/{$entityType}/metadata/states/{$stateId}");

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to get state', [
                'account_id' => $accountId,
                'entity_type' => $entityType,
                'state_id' => $stateId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
