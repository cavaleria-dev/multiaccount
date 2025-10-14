<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы с каналами продаж МойСклад
 */
class SalesChannelService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Получить список каналов продаж
     *
     * @param string $accountId UUID аккаунта
     * @return array Список каналов продаж
     */
    public function getSalesChannels(string $accountId): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/saleschannel');

            return $result['data']['rows'] ?? [];

        } catch (\Exception $e) {
            Log::error('Failed to get sales channels', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Создать канал продаж
     *
     * @param string $accountId UUID аккаунта
     * @param string $name Название канала
     * @param string|null $description Описание канала
     * @return array Созданный канал продаж
     */
    public function createSalesChannel(string $accountId, string $name, ?string $description = null): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $data = [
                'name' => $name,
            ];

            if ($description) {
                $data['description'] = $description;
            }

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->post('entity/saleschannel', $data);

            Log::info('Sales channel created', [
                'account_id' => $accountId,
                'channel_id' => $result['data']['id'] ?? null,
                'name' => $name
            ]);

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to create sales channel', [
                'account_id' => $accountId,
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Найти канал по названию
     *
     * @param string $accountId UUID аккаунта
     * @param string $name Название канала
     * @return array|null Канал продаж или null если не найден
     */
    public function findByName(string $accountId, string $name): ?array
    {
        $channels = $this->getSalesChannels($accountId);

        foreach ($channels as $channel) {
            if ($channel['name'] === $name) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * Получить или создать канал (helper метод)
     *
     * @param string $accountId UUID аккаунта
     * @param string $name Название канала
     * @param string|null $description Описание канала
     * @return array Канал продаж
     */
    public function getOrCreate(string $accountId, string $name, ?string $description = null): array
    {
        $existing = $this->findByName($accountId, $name);

        if ($existing) {
            Log::info('Sales channel already exists', [
                'account_id' => $accountId,
                'channel_id' => $existing['id'],
                'name' => $name
            ]);
            return $existing;
        }

        return $this->createSalesChannel($accountId, $name, $description);
    }

    /**
     * Получить канал по ID
     *
     * @param string $accountId UUID аккаунта
     * @param string $channelId UUID канала продаж
     * @return array Канал продаж
     */
    public function getChannel(string $accountId, string $channelId): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get("entity/saleschannel/{$channelId}");

            return $result['data'];

        } catch (\Exception $e) {
            Log::error('Failed to get sales channel', [
                'account_id' => $accountId,
                'channel_id' => $channelId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
