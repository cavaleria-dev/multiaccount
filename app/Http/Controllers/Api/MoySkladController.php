<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Services\MoySkladService;

/**
 * Контроллер для работы с Vendor API МойСклад
 *
 * Документация: https://dev.moysklad.ru/doc/api/vendor/1.0/
 */
class MoySkladController extends Controller
{
    protected $moyskladService;

    public function __construct(MoySkladService $moyskladService)
    {
        $this->moyskladService = $moyskladService;
    }

    /**
     * Установка/Активация приложения на аккаунте
     *
     * PUT /api/moysklad/vendor/1.0/apps/{appId}/{accountId}
     *
     * @param string $appId - UUID приложения
     * @param string $accountId - UUID аккаунта МойСклад
     * @param Request $request
     * @return JsonResponse
     */
    public function install(string $appId, string $accountId, Request $request): JsonResponse
    {
        try {
            Log::info('МойСклад: Запрос установки', [
                'appId' => $appId,
                'accountId' => $accountId,
                'cause' => $request->input('cause'),
                'ip' => $request->ip()
            ]);

            // Проверка appId
            if ($appId !== config('moysklad.app_id')) {
                Log::warning('МойСклад: Неверный appId', ['received' => $appId]);
                return response()->json(['error' => 'Invalid appId'], 400);
            }

            $accessToken = $request->input('access_token');
            $cause = $request->input('cause'); // Install, StatusUpdate, TariffChanged
            $subscription = $request->input('subscription', []);

            if (!$accessToken || !$accountId) {
                return response()->json(['error' => 'Missing required parameters'], 400);
            }

            // Сохранение или обновление аккаунта
            $account = DB::table('accounts')->where('account_id', $accountId)->first();

            $accountData = [
                'app_id' => $appId,
                'access_token' => $accessToken,
                'subscription_status' => $subscription['status'] ?? null,
                'tariff_name' => $subscription['tariff']['name'] ?? null,
                'price_per_month' => $subscription['pricePerMonth'] ?? 0,
                'cause' => $cause,
                'updated_at' => now()
            ];

            if ($cause === 'Install' || !$account) {
                $accountData['installed_at'] = now();
                $accountData['status'] = 'activating';
                $accountData['account_id'] = $accountId;
                $accountData['created_at'] = now();

                DB::table('accounts')->insert($accountData);

                Log::info('МойСклад: Новая установка', ['accountId' => $accountId]);
            } else {
                DB::table('accounts')
                    ->where('account_id', $accountId)
                    ->update($accountData);

                Log::info('МойСклад: Обновление установки', [
                    'accountId' => $accountId,
                    'cause' => $cause
                ]);
            }

            // Определяем статус ответа
            if ($cause === 'Install') {
                // Для новой установки - требуется настройка
                $this->createDefaultSettings($accountId);

                return response()->json([
                    'status' => 'SettingsRequired'
                ], 200);
            }

            if ($cause === 'StatusUpdate' && $account && $account->status === 'suspended') {
                // Возобновление после приостановки
                DB::table('accounts')
                    ->where('account_id', $accountId)
                    ->update([
                        'status' => 'activated',
                        'suspended_at' => null
                    ]);

                // Включаем вебхуки обратно
                $this->enableWebhooks($accountId);

                Log::info('МойСклад: Приложение возобновлено', ['accountId' => $accountId]);
            }

            return response()->json([
                'status' => 'Activated'
            ], 200);

        } catch (\Exception $e) {
            Log::error('МойСклад: Ошибка установки', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Installation failed',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Удаление/Приостановка приложения
     *
     * DELETE /api/moysklad/vendor/1.0/apps/{appId}/{accountId}
     */
    public function uninstall(string $appId, string $accountId, Request $request): JsonResponse
    {
        try {
            Log::info('МойСклад: Запрос удаления', [
                'appId' => $appId,
                'accountId' => $accountId,
                'cause' => $request->input('cause')
            ]);

            if ($appId !== config('moysklad.app_id')) {
                return response()->json(['error' => 'Invalid appId'], 400);
            }

            $cause = $request->input('cause'); // Uninstall, Suspend

            $account = DB::table('accounts')
                ->where('account_id', $accountId)
                ->first();

            if (!$account) {
                return response()->json(['error' => 'Account not found'], 404);
            }

            if ($cause === 'Suspend') {
                // Приостановка
                DB::table('accounts')
                    ->where('account_id', $accountId)
                    ->update([
                        'status' => 'suspended',
                        'suspended_at' => now(),
                        'updated_at' => now()
                    ]);

                // Отключаем вебхуки
                $this->disableWebhooks($accountId);

                Log::info('МойСклад: Приложение приостановлено', ['accountId' => $accountId]);
            } else {
                // Полное удаление

                // Удаляем вебхуки через API
                try {
                    $this->moyskladService->setAccessToken($account->access_token);
                    $webhooks = $this->moyskladService->getWebhooks();

                    foreach ($webhooks as $webhook) {
                        $this->moyskladService->deleteWebhook($webhook['id']);
                    }
                } catch (\Exception $e) {
                    Log::warning('МойСклад: Не удалось удалить вебхуки', [
                        'error' => $e->getMessage()
                    ]);
                }

                // Архивируем данные
                DB::table('accounts_archive')->insert([
                    'account_id' => $accountId,
                    'data' => json_encode($account),
                    'deleted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // Удаляем связанные данные (каскадное удаление через foreign keys)
                DB::table('accounts')->where('account_id', $accountId)->delete();

                Log::info('МойСклад: Приложение удалено', ['accountId' => $accountId]);
            }

            return response()->json(['status' => 'Success'], 200);

        } catch (\Exception $e) {
            Log::error('МойСклад: Ошибка удаления', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Uninstallation failed'
            ], 500);
        }
    }

    /**
     * Получение статуса приложения
     *
     * GET /api/moysklad/vendor/1.0/apps/{appId}/{accountId}/status
     */
    public function status(string $appId, string $accountId): JsonResponse
    {
        try {
            if ($appId !== config('moysklad.app_id')) {
                return response()->json(['error' => 'Invalid appId'], 400);
            }

            $account = DB::table('accounts')
                ->where('account_id', $accountId)
                ->first();

            if (!$account) {
                return response()->json(['status' => 'NotFound'], 200);
            }

            $status = match($account->status) {
                'activating' => 'Activating',
                'activated' => 'Activated',
                'settings_required' => 'SettingsRequired',
                'suspended' => 'Suspended',
                default => 'NotFound'
            };

            return response()->json(['status' => $status], 200);

        } catch (\Exception $e) {
            Log::error('МойСклад: Ошибка получения статуса', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Status check failed'
            ], 500);
        }
    }

    /**
     * Обновление статуса из iframe
     *
     * POST /api/apps/update-status
     */
    public function updateStatus(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => 'required|uuid',
            'status' => 'required|in:activated,settings_required'
        ]);

        $accountId = $request->input('account_id');
        $status = $request->input('status');

        DB::table('accounts')
            ->where('account_id', $accountId)
            ->update([
                'status' => $status,
                'updated_at' => now()
            ]);

        Log::info('МойСклад: Статус обновлен из iframe', [
            'accountId' => $accountId,
            'status' => $status
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Получение контекста пользователя для iframe
     *
     * GET /api/context/{contextKey}
     */
    public function getContext(string $contextKey): JsonResponse
    {
        // Здесь должна быть логика получения контекста через Vendor API
        // Временная заглушка
        return response()->json([
            'contextKey' => $contextKey,
            'message' => 'Context API not implemented yet'
        ]);
    }

    // ============ Вспомогательные методы ============

    /**
     * Создание настроек по умолчанию
     */
    private function createDefaultSettings(string $accountId): void
    {
        DB::table('sync_settings')->insert([
            'account_id' => $accountId,
            'sync_catalog' => true,
            'sync_orders' => true,
            'sync_prices' => true,
            'sync_stock' => true,
            'sync_images_all' => false,
            'product_match_field' => 'article',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Отключение вебхуков
     */
    private function disableWebhooks(string $accountId): void
    {
        DB::table('webhooks')
            ->where('account_id', $accountId)
            ->update(['enabled' => false]);
    }

    /**
     * Включение вебхуков
     */
    private function enableWebhooks(string $accountId): void
    {
        DB::table('webhooks')
            ->where('account_id', $accountId)
            ->update(['enabled' => true]);
    }
}