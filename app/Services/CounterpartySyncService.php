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

            // Проверить, есть ли уже такой контрагент в главном (по ИНН)
            $parentAccount = Account::where('account_id', $parentAccountId)->firstOrFail();
            $existingCounterparty = null;

            if (isset($childCounterparty['inn']) && $childCounterparty['inn']) {
                $existingCounterparty = $this->findCounterpartyByInn(
                    $parentAccountId,
                    $childCounterparty['inn']
                );
            }

            // Если не нашли - создать
            if (!$existingCounterparty) {
                $newCounterpartyData = [
                    'name' => $childCounterparty['name'],
                    'companyType' => $childCounterparty['companyType'] ?? 'legal',
                ];

                if (isset($childCounterparty['inn'])) {
                    $newCounterpartyData['inn'] = $childCounterparty['inn'];
                }
                if (isset($childCounterparty['kpp'])) {
                    $newCounterpartyData['kpp'] = $childCounterparty['kpp'];
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
