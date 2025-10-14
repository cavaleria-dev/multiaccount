<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы с юридическими лицами МойСклад
 */
class OrganizationService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Получить список юридических лиц
     *
     * @param string $accountId UUID аккаунта
     * @return array Список организаций
     */
    public function getOrganizations(string $accountId): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/organization');

            return $result['data']['rows'] ?? [];

        } catch (\Exception $e) {
            Log::error('Failed to get organizations', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить юридическое лицо по ID
     *
     * @param string $accountId UUID аккаунта
     * @param string $organizationId UUID организации
     * @return array Организация
     */
    public function getOrganization(string $accountId, string $organizationId): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get("entity/organization/{$organizationId}");

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to get organization', [
                'account_id' => $accountId,
                'organization_id' => $organizationId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить основное юридическое лицо (первое в списке)
     *
     * @param string $accountId UUID аккаунта
     * @return array|null Организация или null
     */
    public function getMainOrganization(string $accountId): ?array
    {
        $organizations = $this->getOrganizations($accountId);

        return $organizations[0] ?? null;
    }

    /**
     * Найти организацию по названию
     *
     * @param string $accountId UUID аккаунта
     * @param string $name Название организации
     * @return array|null Организация или null
     */
    public function findByName(string $accountId, string $name): ?array
    {
        $organizations = $this->getOrganizations($accountId);

        foreach ($organizations as $organization) {
            if ($organization['name'] === $name) {
                return $organization;
            }
        }

        return null;
    }

    /**
     * Найти организацию по ИНН
     *
     * @param string $accountId UUID аккаунта
     * @param string $inn ИНН организации
     * @return array|null Организация или null
     */
    public function findByInn(string $accountId, string $inn): ?array
    {
        $organizations = $this->getOrganizations($accountId);

        foreach ($organizations as $organization) {
            if (isset($organization['inn']) && $organization['inn'] === $inn) {
                return $organization;
            }
        }

        return null;
    }
}
