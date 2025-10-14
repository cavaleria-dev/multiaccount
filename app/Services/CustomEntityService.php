<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы с пользовательскими справочниками МойСклад (customentity)
 */
class CustomEntityService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Получить список справочников
     *
     * @param string $accountId UUID аккаунта
     * @return array Список справочников
     */
    public function getCustomEntities(string $accountId): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/customentity');

            return $result['data']['rows'] ?? [];

        } catch (\Exception $e) {
            Log::error('Failed to get custom entities', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить справочник по ID
     *
     * @param string $accountId UUID аккаунта
     * @param string $customEntityId UUID справочника
     * @return array Справочник
     */
    public function getCustomEntity(string $accountId, string $customEntityId): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get("entity/customentity/{$customEntityId}");

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to get custom entity', [
                'account_id' => $accountId,
                'custom_entity_id' => $customEntityId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Создать справочник
     *
     * @param string $accountId UUID аккаунта
     * @param string $name Название справочника
     * @return array Созданный справочник
     */
    public function createCustomEntity(string $accountId, string $name): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $data = [
                'name' => $name,
            ];

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->post('entity/customentity', $data);

            Log::info('Custom entity created', [
                'account_id' => $accountId,
                'custom_entity_id' => $result['data']['id'] ?? null,
                'name' => $name
            ]);

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to create custom entity', [
                'account_id' => $accountId,
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить элементы справочника
     *
     * @param string $accountId UUID аккаунта
     * @param string $customEntityId UUID справочника
     * @param array $params Параметры фильтрации
     * @return array Список элементов
     */
    public function getCustomEntityElements(string $accountId, string $customEntityId, array $params = []): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get("entity/customentity/{$customEntityId}", $params);

            return $result['data']['rows'] ?? [];

        } catch (\Exception $e) {
            Log::error('Failed to get custom entity elements', [
                'account_id' => $accountId,
                'custom_entity_id' => $customEntityId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Создать элемент справочника
     *
     * @param string $accountId UUID аккаунта
     * @param string $customEntityId UUID справочника
     * @param string $name Название элемента
     * @param array $additionalData Дополнительные данные
     * @return array Созданный элемент
     */
    public function createCustomEntityElement(
        string $accountId,
        string $customEntityId,
        string $name,
        array $additionalData = []
    ): array {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $data = array_merge(['name' => $name], $additionalData);

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->post("entity/customentity/{$customEntityId}", $data);

            Log::info('Custom entity element created', [
                'account_id' => $accountId,
                'custom_entity_id' => $customEntityId,
                'element_id' => $result['data']['id'] ?? null,
                'name' => $name
            ]);

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to create custom entity element', [
                'account_id' => $accountId,
                'custom_entity_id' => $customEntityId,
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Найти справочник по названию
     *
     * @param string $accountId UUID аккаунта
     * @param string $name Название справочника
     * @return array|null Справочник или null
     */
    public function findCustomEntityByName(string $accountId, string $name): ?array
    {
        $entities = $this->getCustomEntities($accountId);

        foreach ($entities as $entity) {
            if ($entity['name'] === $name) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * Найти элемент справочника по названию
     *
     * @param string $accountId UUID аккаунта
     * @param string $customEntityId UUID справочника
     * @param string $name Название элемента
     * @return array|null Элемент или null
     */
    public function findElementByName(string $accountId, string $customEntityId, string $name): ?array
    {
        $elements = $this->getCustomEntityElements($accountId, $customEntityId);

        foreach ($elements as $element) {
            if ($element['name'] === $name) {
                return $element;
            }
        }

        return null;
    }

    /**
     * Получить или создать справочник
     *
     * @param string $accountId UUID аккаунта
     * @param string $name Название справочника
     * @return array Справочник
     */
    public function getOrCreateCustomEntity(string $accountId, string $name): array
    {
        $existing = $this->findCustomEntityByName($accountId, $name);

        if ($existing) {
            Log::info('Custom entity already exists', [
                'account_id' => $accountId,
                'custom_entity_id' => $existing['id'],
                'name' => $name
            ]);
            return $existing;
        }

        return $this->createCustomEntity($accountId, $name);
    }

    /**
     * Получить или создать элемент справочника
     *
     * @param string $accountId UUID аккаунта
     * @param string $customEntityId UUID справочника
     * @param string $name Название элемента
     * @param array $additionalData Дополнительные данные
     * @return array Элемент справочника
     */
    public function getOrCreateElement(
        string $accountId,
        string $customEntityId,
        string $name,
        array $additionalData = []
    ): array {
        $existing = $this->findElementByName($accountId, $customEntityId, $name);

        if ($existing) {
            Log::info('Custom entity element already exists', [
                'account_id' => $accountId,
                'custom_entity_id' => $customEntityId,
                'element_id' => $existing['id'],
                'name' => $name
            ]);
            return $existing;
        }

        return $this->createCustomEntityElement($accountId, $customEntityId, $name, $additionalData);
    }
}
