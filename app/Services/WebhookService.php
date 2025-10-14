<?php

namespace App\Services;

use App\Models\Account;
use App\Models\WebhookHealth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для управления вебхуками МойСклад
 */
class WebhookService
{
    protected MoySkladService $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Настроить вебхуки для аккаунта
     *
     * @param string $accountId UUID аккаунта
     * @param string $accountType Тип аккаунта (main/child)
     * @return array Созданные вебхуки
     */
    public function setupWebhooks(string $accountId, string $accountType): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();
            $webhookUrl = config('moysklad.webhook_url');
            $createdWebhooks = [];

            // Удалить существующие вебхуки приложения
            $this->cleanupOldWebhooks($accountId);

            if ($accountType === 'main') {
                // Вебхуки для главного аккаунта (товары)
                $createdWebhooks = array_merge(
                    $createdWebhooks,
                    $this->setupProductWebhooks($account, $webhookUrl)
                );
            } else {
                // Вебхуки для дочернего аккаунта (заказы)
                $createdWebhooks = array_merge(
                    $createdWebhooks,
                    $this->setupOrderWebhooks($account, $webhookUrl)
                );
            }

            Log::info('Webhooks setup completed', [
                'account_id' => $accountId,
                'account_type' => $accountType,
                'webhooks_count' => count($createdWebhooks)
            ]);

            return $createdWebhooks;

        } catch (\Exception $e) {
            Log::error('Webhooks setup failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Настроить вебхуки для товаров (главный аккаунт)
     */
    protected function setupProductWebhooks(Account $account, string $webhookUrl): array
    {
        $createdWebhooks = [];
        $productEntities = ['product', 'variant', 'bundle'];
        $productActions = ['CREATE', 'UPDATE', 'DELETE'];

        foreach ($productEntities as $entityType) {
            foreach ($productActions as $action) {
                try {
                    $webhook = $this->createWebhook($account, $webhookUrl, $action, $entityType);
                    $createdWebhooks[] = $webhook;

                    // Сохранить в webhook_health
                    WebhookHealth::updateOrCreate(
                        [
                            'account_id' => $account->account_id,
                            'entity_type' => $entityType,
                            'action' => $action,
                        ],
                        [
                            'webhook_id' => $webhook['id'],
                            'is_active' => true,
                            'last_check_at' => now(),
                            'last_success_at' => now(),
                            'check_attempts' => 0,
                            'error_message' => null,
                        ]
                    );

                } catch (\Exception $e) {
                    Log::error('Failed to create product webhook', [
                        'account_id' => $account->account_id,
                        'entity_type' => $entityType,
                        'action' => $action,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $createdWebhooks;
    }

    /**
     * Настроить вебхуки для заказов (дочерний аккаунт)
     */
    protected function setupOrderWebhooks(Account $account, string $webhookUrl): array
    {
        $createdWebhooks = [];
        $orderEntities = ['customerorder', 'retaildemand', 'purchaseorder'];
        $orderActions = ['UPDATE']; // Только UPDATE для заказов

        foreach ($orderEntities as $entityType) {
            foreach ($orderActions as $action) {
                try {
                    $webhook = $this->createWebhook($account, $webhookUrl, $action, $entityType);
                    $createdWebhooks[] = $webhook;

                    WebhookHealth::updateOrCreate(
                        [
                            'account_id' => $account->account_id,
                            'entity_type' => $entityType,
                            'action' => $action,
                        ],
                        [
                            'webhook_id' => $webhook['id'],
                            'is_active' => true,
                            'last_check_at' => now(),
                            'last_success_at' => now(),
                            'check_attempts' => 0,
                            'error_message' => null,
                        ]
                    );

                } catch (\Exception $e) {
                    Log::error('Failed to create order webhook', [
                        'account_id' => $account->account_id,
                        'entity_type' => $entityType,
                        'action' => $action,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $createdWebhooks;
    }

    /**
     * Создать вебхук
     */
    protected function createWebhook(Account $account, string $url, string $action, string $entityType): array
    {
        $data = [
            'url' => $url,
            'action' => $action,
            'entityType' => $entityType,
            'enabled' => true,
        ];

        $result = $this->moySkladService
            ->setAccessToken($account->access_token)
            ->post('entity/webhook', $data);

        Log::info('Webhook created', [
            'account_id' => $account->account_id,
            'entity_type' => $entityType,
            'action' => $action,
            'webhook_id' => $result['data']['id'] ?? null
        ]);

        return $result['data'];
    }

    /**
     * Удалить старые вебхуки приложения
     */
    protected function cleanupOldWebhooks(string $accountId): void
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            // Получить все вебхуки
            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/webhook');

            $webhooks = $result['data']['rows'] ?? [];
            $webhookUrl = config('moysklad.webhook_url');

            // Удалить вебхуки с нашим URL
            foreach ($webhooks as $webhook) {
                if (isset($webhook['url']) && str_contains($webhook['url'], parse_url($webhookUrl, PHP_URL_HOST))) {
                    $this->moySkladService
                        ->setAccessToken($account->access_token)
                        ->delete("entity/webhook/{$webhook['id']}");

                    Log::info('Old webhook deleted', [
                        'account_id' => $accountId,
                        'webhook_id' => $webhook['id']
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::warning('Failed to cleanup old webhooks', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Проверить здоровье вебхуков аккаунта
     */
    public function checkWebhookHealth(string $accountId): array
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            // Получить все вебхуки из МойСклад
            $result = $this->moySkladService
                ->setAccessToken($account->access_token)
                ->get('entity/webhook');

            $webhooks = $result['data']['rows'] ?? [];
            $webhookUrl = config('moysklad.webhook_url');

            // Обновить статус в webhook_health
            $healthRecords = WebhookHealth::where('account_id', $accountId)->get();

            foreach ($healthRecords as $health) {
                $found = false;

                foreach ($webhooks as $webhook) {
                    if ($webhook['id'] === $health->webhook_id) {
                        $found = true;

                        // Проверить что вебхук активен
                        if (isset($webhook['enabled']) && !$webhook['enabled']) {
                            $health->update([
                                'is_active' => false,
                                'error_message' => 'Webhook is disabled in MoySklad',
                                'last_check_at' => now(),
                            ]);
                        } else {
                            $health->update([
                                'is_active' => true,
                                'last_check_at' => now(),
                                'last_success_at' => now(),
                                'check_attempts' => 0,
                                'error_message' => null,
                            ]);
                        }

                        break;
                    }
                }

                if (!$found) {
                    // Вебхук не найден, отметить как неактивный
                    $health->increment('check_attempts');
                    $health->update([
                        'is_active' => false,
                        'error_message' => 'Webhook not found in MoySklad',
                        'last_check_at' => now(),
                    ]);

                    // Попытаться пересоздать если слишком много неудачных проверок
                    if ($health->check_attempts >= 3) {
                        Log::warning('Webhook missing, attempting to recreate', [
                            'account_id' => $accountId,
                            'webhook_id' => $health->webhook_id,
                            'entity_type' => $health->entity_type,
                            'action' => $health->action
                        ]);

                        try {
                            $newWebhook = $this->createWebhook(
                                $account,
                                $webhookUrl,
                                $health->action,
                                $health->entity_type
                            );

                            $health->update([
                                'webhook_id' => $newWebhook['id'],
                                'is_active' => true,
                                'check_attempts' => 0,
                                'error_message' => null,
                                'last_success_at' => now(),
                            ]);

                        } catch (\Exception $e) {
                            Log::error('Failed to recreate webhook', [
                                'account_id' => $accountId,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
            }

            Log::info('Webhook health check completed', [
                'account_id' => $accountId
            ]);

            return $healthRecords->toArray();

        } catch (\Exception $e) {
            Log::error('Webhook health check failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить статус вебхуков для аккаунта
     */
    public function getWebhookStatus(string $accountId): array
    {
        $healthRecords = WebhookHealth::where('account_id', $accountId)
            ->orderBy('entity_type')
            ->orderBy('action')
            ->get();

        $activeCount = $healthRecords->where('is_active', true)->count();
        $totalCount = $healthRecords->count();

        return [
            'total' => $totalCount,
            'active' => $activeCount,
            'inactive' => $totalCount - $activeCount,
            'webhooks' => $healthRecords->map(function($health) {
                return [
                    'entity_type' => $health->entity_type,
                    'action' => $health->action,
                    'is_active' => $health->is_active,
                    'last_check_at' => $health->last_check_at,
                    'last_success_at' => $health->last_success_at,
                    'error_message' => $health->error_message,
                ];
            })->toArray()
        ];
    }
}
