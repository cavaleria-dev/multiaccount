<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы с сотрудниками МойСклад
 */
class EmployeeService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Получить список сотрудников
     *
     * @param string $accountId UUID аккаунта
     * @param array $params Параметры фильтрации
     * @return array Список сотрудников
     */
    public function getEmployees(string $accountId, array $params = []): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/employee', $params);

            return $result['data']['rows'] ?? [];

        } catch (\Exception $e) {
            Log::error('Failed to get employees', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить сотрудника по ID
     *
     * @param string $accountId UUID аккаунта
     * @param string $employeeId UUID сотрудника
     * @return array Сотрудник
     */
    public function getEmployee(string $accountId, string $employeeId): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get("entity/employee/{$employeeId}");

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to get employee', [
                'account_id' => $accountId,
                'employee_id' => $employeeId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Поиск сотрудника по имени/email
     *
     * @param string $accountId UUID аккаунта
     * @param string $query Поисковый запрос
     * @return array Список найденных сотрудников
     */
    public function searchEmployee(string $accountId, string $query): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/employee', ['search' => $query]);

            return $result['data']['rows'] ?? [];

        } catch (\Exception $e) {
            Log::error('Failed to search employees', [
                'account_id' => $accountId,
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить текущего пользователя (владельца токена)
     *
     * @param string $accountId UUID аккаунта
     * @return array Данные текущего пользователя
     */
    public function getCurrentEmployee(string $accountId): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('context/employee');

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to get current employee', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Найти сотрудника по email
     *
     * @param string $accountId UUID аккаунта
     * @param string $email Email сотрудника
     * @return array|null Сотрудник или null если не найден
     */
    public function findByEmail(string $accountId, string $email): ?array
    {
        $employees = $this->searchEmployee($accountId, $email);

        foreach ($employees as $employee) {
            if (isset($employee['email']) && $employee['email'] === $email) {
                return $employee;
            }
        }

        return null;
    }
}
