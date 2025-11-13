<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CounterpartyMapping;
use App\Models\SyncSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для синхронизации контрагентов между аккаунтами
 */
class CounterpartySyncService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Создать контрагента франшизы в главном аккаунте
     *
     * @param string $mainAccountId UUID главного аккаунта
     * @param array $franchiseData Данные франшизы ['name' => ..., 'inn' => ..., ...]
     * @return array Созданный контрагент
     */
    public function createFranchiseCounterparty(string $mainAccountId, array $franchiseData): array
    {
        try {
            $mainAccount = Account::where('account_id', $mainAccountId)->firstOrFail();

            // Получить или создать группу "Франшизы"
            $franchiseGroup = $this->getOrCreateCounterpartyGroup($mainAccountId, 'Франшизы');

            $data = [
                'name' => $franchiseData['name'],
                'companyType' => 'legal',
                'group' => [
                    'meta' => $franchiseGroup['meta']
                ],
            ];

            // Добавить ИНН если есть
            if (isset($franchiseData['inn'])) {
                $data['inn'] = $franchiseData['inn'];
            }

            // Добавить дополнительные поля если есть
            if (isset($franchiseData['kpp'])) {
                $data['kpp'] = $franchiseData['kpp'];
            }

            $result = $this->moySkladService
                ->setAccessToken($mainAccount->access_token)
                ->post('entity/counterparty', $data);

            Log::info('Franchise counterparty created in main account', [
                'main_account_id' => $mainAccountId,
                'counterparty_id' => $result['data']['id'] ?? null,
                'name' => $franchiseData['name']
            ]);

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to create franchise counterparty', [
                'main_account_id' => $mainAccountId,
                'franchise_data' => $franchiseData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Создать контрагента-поставщика в дочернем аккаунте
     * (представляет главное юр.лицо)
     *
     * @param string $childAccountId UUID дочернего аккаунта
     * @param array $organizationData Данные организации главного аккаунта
     * @return array Созданный контрагент
     */
    public function createSupplierCounterparty(string $childAccountId, array $organizationData): array
    {
        try {
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();

            $data = [
                'name' => $organizationData['name'],
                'companyType' => 'legal',
            ];

            // Добавить ИНН/КПП если есть
            if (isset($organizationData['inn'])) {
                $data['inn'] = $organizationData['inn'];
            }
            if (isset($organizationData['kpp'])) {
                $data['kpp'] = $organizationData['kpp'];
            }

            $result = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->post('entity/counterparty', $data);

            Log::info('Supplier counterparty created in child account', [
                'child_account_id' => $childAccountId,
                'counterparty_id' => $result['data']['id'] ?? null,
                'name' => $organizationData['name']
            ]);

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to create supplier counterparty', [
                'child_account_id' => $childAccountId,
                'organization_data' => $organizationData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Синхронизировать контрагента или использовать stub
     *
     * @param string $parentAccountId UUID родительского аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $childCounterpartyId UUID контрагента в дочернем аккаунте
     * @return array Контрагент в родительском аккаунте ['id' => ..., 'meta' => ...]
     */
    public function syncCounterparty(
        string $parentAccountId,
        string $childAccountId,
        string $childCounterpartyId
    ): array {
        try {
            // Получить настройки синхронизации
            $settings = SyncSetting::where('account_id', $childAccountId)->first();

            // Если не синхронизируем реальных контрагентов - использовать stub
            if (!$settings || !$settings->sync_real_counterparties) {
                return $this->getStubCounterparty($parentAccountId, $settings);
            }

            // Проверить, есть ли уже маппинг
            $mapping = CounterpartyMapping::where('parent_account_id', $parentAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('child_counterparty_id', $childCounterpartyId)
                ->first();

            if ($mapping) {
                // Контрагент уже синхронизирован
                return [
                    'id' => $mapping->parent_counterparty_id,
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/counterparty/{$mapping->parent_counterparty_id}",
                        'type' => 'counterparty',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Получить контрагента из дочернего аккаунта
            $childAccount = Account::where('account_id', $childAccountId)->firstOrFail();
            $childCounterpartyResult = $this->moySkladService
                ->setAccessToken($childAccount->access_token)
                ->get("entity/counterparty/{$childCounterpartyId}");

            $childCounterparty = $childCounterpartyResult['data'];

            // Определить стратегию поиска по типу контрагента
            $parentAccount = Account::where('account_id', $parentAccountId)->firstOrFail();
            $existingCounterparty = null;
            $companyType = $childCounterparty['companyType'] ?? 'legal';

            if ($companyType === 'individual') {
                // Физ.лицо → искать по телефону и/или email
                Log::debug('Searching for individual counterparty', [
                    'phone' => $childCounterparty['phone'] ?? null,
                    'email' => $childCounterparty['email'] ?? null,
                    'name' => $childCounterparty['name'] ?? null
                ]);

                $existingCounterparty = $this->findIndividualCounterparty(
                    $parentAccountId,
                    $childCounterparty
                );
            } else {
                // Юр.лицо или ИП → искать по ИНН
                if (isset($childCounterparty['inn']) && $childCounterparty['inn']) {
                    Log::debug('Searching for legal/entrepreneur counterparty by INN', [
                        'inn' => $childCounterparty['inn'],
                        'company_type' => $companyType
                    ]);

                    $existingCounterparty = $this->findCounterpartyByInn(
                        $parentAccountId,
                        $childCounterparty['inn']
                    );
                }
            }

            // Если не нашли - создать
            if (!$existingCounterparty) {
                $newCounterpartyData = [
                    'name' => $childCounterparty['name'],
                    'companyType' => $childCounterparty['companyType'] ?? 'legal',
                ];

                // Для юр.лиц и ИП - добавить ИНН/КПП
                if (isset($childCounterparty['inn'])) {
                    $newCounterpartyData['inn'] = $childCounterparty['inn'];
                }
                if (isset($childCounterparty['kpp'])) {
                    $newCounterpartyData['kpp'] = $childCounterparty['kpp'];
                }

                // Для физ.лиц - добавить phone/email
                if ($companyType === 'individual') {
                    if (isset($childCounterparty['phone'])) {
                        $newCounterpartyData['phone'] = $childCounterparty['phone'];
                    }
                    if (isset($childCounterparty['email'])) {
                        $newCounterpartyData['email'] = $childCounterparty['email'];
                    }
                }

                $result = $this->moySkladService
                    ->setAccessToken($parentAccount->access_token)
                    ->post('entity/counterparty', $newCounterpartyData);

                $existingCounterparty = $result['data'];
            }

            // Создать маппинг
            CounterpartyMapping::create([
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'parent_counterparty_id' => $existingCounterparty['id'],
                'child_counterparty_id' => $childCounterpartyId,
                'counterparty_name' => $existingCounterparty['name'],
                'counterparty_inn' => $existingCounterparty['inn'] ?? null,
                'is_stub' => false,
            ]);

            Log::info('Counterparty synced', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'parent_counterparty_id' => $existingCounterparty['id'],
                'child_counterparty_id' => $childCounterpartyId
            ]);

            return [
                'id' => $existingCounterparty['id'],
                'meta' => $existingCounterparty['meta']
            ];

        } catch (\Exception $e) {
            Log::error('Failed to sync counterparty', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'child_counterparty_id' => $childCounterpartyId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить stub контрагента
     */
    protected function getStubCounterparty(string $parentAccountId, ?SyncSetting $settings): array
    {
        if ($settings && $settings->stub_counterparty_id) {
            return [
                'id' => $settings->stub_counterparty_id,
                'meta' => [
                    'href' => config('moysklad.api_url') . "/entity/counterparty/{$settings->stub_counterparty_id}",
                    'type' => 'counterparty',
                    'mediaType' => 'application/json'
                ]
            ];
        }

        throw new \Exception('Stub counterparty not configured');
    }

    /**
     * Найти контрагента по ИНН
     */
    protected function findCounterpartyByInn(string $accountId, string $inn): ?array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/counterparty', [
                    'filter' => "inn={$inn}"
                ]);

            $counterparties = $result['data']['rows'] ?? [];

            return $counterparties[0] ?? null;

        } catch (\Exception $e) {
            Log::warning('Failed to find counterparty by INN', [
                'account_id' => $accountId,
                'inn' => $inn,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Найти физ.лицо по телефону и/или email
     */
    protected function findIndividualCounterparty(string $accountId, array $counterparty): ?array
    {
        $phone = $counterparty['phone'] ?? null;
        $email = $counterparty['email'] ?? null;
        $name = $counterparty['name'] ?? null;

        // Если нет ни телефона, ни email → невозможно найти
        if (!$phone && !$email) {
            Log::debug('Individual counterparty has no phone or email, cannot search', [
                'name' => $name
            ]);
            return null;
        }

        $account = Account::where('account_id', $accountId)->firstOrFail();

        // Стратегия 1: Если заполнены оба поля → искать по обоим
        if ($phone && $email) {
            $candidates = $this->searchCounterpartiesByPhoneAndEmail($account, $phone, $email);

            if (count($candidates) === 1) {
                return $candidates[0];
            }

            if (count($candidates) > 1) {
                // Дополнительно фильтровать по имени если возможно
                return $this->filterByName($candidates, $name);
            }
        }

        // Стратегия 2: Искать по телефону
        if ($phone) {
            $candidates = $this->searchCounterpartiesByPhone($account, $phone);

            if (count($candidates) === 1) {
                return $candidates[0];
            }

            if (count($candidates) > 1 && $email) {
                // Фильтровать по email
                $filtered = array_filter($candidates, function($c) use ($email) {
                    return isset($c['email']) && $c['email'] === $email;
                });

                if (count($filtered) === 1) {
                    return array_values($filtered)[0];
                }

                if (count($filtered) > 1) {
                    return $this->filterByName($filtered, $name);
                }
            }

            // Если несколько найдено и нет email для фильтрации
            if (count($candidates) > 1) {
                return $this->filterByName($candidates, $name);
            }
        }

        // Стратегия 3: Искать по email (если телефона нет)
        if ($email && !$phone) {
            $candidates = $this->searchCounterpartiesByEmail($account, $email);

            if (count($candidates) === 1) {
                return $candidates[0];
            }

            if (count($candidates) > 1) {
                return $this->filterByName($candidates, $name);
            }
        }

        return null;
    }

    /**
     * Фильтровать кандидатов по имени
     */
    protected function filterByName(array $candidates, ?string $name): ?array
    {
        if (!$name) {
            // Если имени нет → вернуть первого кандидата
            Log::warning('Multiple counterparty candidates found, no name to filter, taking first', [
                'count' => count($candidates)
            ]);
            return $candidates[0];
        }

        $filtered = array_filter($candidates, function($c) use ($name) {
            return isset($c['name']) && $c['name'] === $name;
        });

        if (count($filtered) === 1) {
            return array_values($filtered)[0];
        }

        if (count($filtered) > 1) {
            // Несколько с одинаковым именем → взять первого
            Log::warning('Multiple counterparty candidates with same name, taking first', [
                'count' => count($filtered),
                'name' => $name
            ]);
            return array_values($filtered)[0];
        }

        // Имя не совпало ни с одним → взять первого из исходных
        Log::warning('Name does not match any candidate, taking first', [
            'count' => count($candidates),
            'search_name' => $name
        ]);
        return $candidates[0];
    }

    /**
     * Поиск контрагентов по телефону И email одновременно
     */
    protected function searchCounterpartiesByPhoneAndEmail(Account $account, string $phone, string $email): array
    {
        try {
            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/counterparty', [
                    'filter' => "phone={$phone};email={$email}"
                ]);

            return $result['data']['rows'] ?? [];
        } catch (\Exception $e) {
            Log::warning('Failed to search counterparty by phone and email', [
                'phone' => $phone,
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Поиск контрагентов по телефону
     */
    protected function searchCounterpartiesByPhone(Account $account, string $phone): array
    {
        try {
            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/counterparty', [
                    'filter' => "phone={$phone}"
                ]);

            return $result['data']['rows'] ?? [];
        } catch (\Exception $e) {
            Log::warning('Failed to search counterparty by phone', [
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Поиск контрагентов по email
     */
    protected function searchCounterpartiesByEmail(Account $account, string $email): array
    {
        try {
            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/counterparty', [
                    'filter' => "email={$email}"
                ]);

            return $result['data']['rows'] ?? [];
        } catch (\Exception $e) {
            Log::warning('Failed to search counterparty by email', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Получить или создать группу контрагентов
     */
    protected function getOrCreateCounterpartyGroup(string $accountId, string $groupName): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            // Получить список групп
            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/counterparty/folder');

            $folders = $result['data']['rows'] ?? [];

            // Найти нужную группу
            foreach ($folders as $folder) {
                if ($folder['name'] === $groupName) {
                    return $folder;
                }
            }

            // Создать группу
            $createResult = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->post('entity/counterparty/folder', [
                    'name' => $groupName
                ]);

            return $createResult['data'];

        } catch (\Exception $e) {
            Log::error('Failed to get or create counterparty group', [
                'account_id' => $accountId,
                'group_name' => $groupName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
