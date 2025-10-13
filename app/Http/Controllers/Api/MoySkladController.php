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
                'method' => $request->method(),
                'body' => $request->all(),
                'ip' => $request->ip()
            ]);

            // Проверка appId
            if ($appId !== config('moysklad.app_id')) {
                Log::warning('МойСклад: Неверный appId', [
                    'expected' => config('moysklad.app_id'),
                    'received' => $appId
                ]);
                return response()->json(['error' => 'Invalid appId'], 400);
            }

            // Получение данных из запроса (правильная структура от МойСклад)
            $appUid = $request->input('appUid');
            $accountName = $request->input('accountName');
            $cause = $request->input('cause'); // Install, StatusUpdate, TariffChanged
            $access = $request->input('access', []);
            $subscription = $request->input('subscription', []);

            // access_token находится внутри массива access
            $accessToken = null;
            if (!empty($access) && is_array($access)) {
                $accessToken = $access[0]['access_token'] ?? null;
            }

            Log::info('МойСклад: Извлеченные данные', [
                'appUid' => $appUid,
                'accountName' => $accountName,
                'cause' => $cause,
                'has_access_token' => !empty($accessToken),
                'subscription' => $subscription
            ]);

            if (!$accessToken || !$accountId) {
                Log::error('МойСклад: Отсутствуют обязательные параметры', [
                    'has_accessToken' => !empty($accessToken),
                    'has_accountId' => !empty($accountId),
                    'access_array' => $access
                ]);
                return response()->json([
                    'error' => 'Missing required parameters',
                    'details' => [
                        'accessToken' => !empty($accessToken) ? 'present' : 'missing',
                        'accountId' => !empty($accountId) ? 'present' : 'missing'
                    ]
                ], 400);
            }

            // Сохранение или обновление аккаунта
            $account = DB::table('accounts')->where('account_id', $accountId)->first();

            $accountData = [
                'app_id' => $appId,
                'access_token' => $accessToken,
                'subscription_status' => $subscription['trial'] ?? false ? 'Trial' : 'Active',
                'tariff_name' => $subscription['tariffName'] ?? null,
                'price_per_month' => 0, // МойСклад не передает цену в этом запросе
                'cause' => $cause,
                'updated_at' => now()
            ];

            if ($cause === 'Install') {
                // Сразу активируем приложение
                DB::table('accounts')
                    ->where('account_id', $accountId)
                    ->update(['status' => 'activated']);

                Log::info('МойСклад: Приложение активировано', [
                    'accountId' => $accountId
                ]);

                return response()->json([
                    'status' => 'Activated'
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
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
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
            Log::info('МойСклад: Запрос удаления/приостановки', [
                'appId' => $appId,
                'accountId' => $accountId,
                'body' => $request->all(),
                'ip' => $request->ip()
            ]);

            // Проверка appId
            if ($appId !== config('moysklad.app_id')) {
                Log::warning('МойСклад: Неверный appId при удалении', [
                    'expected' => config('moysklad.app_id'),
                    'received' => $appId
                ]);
                return response()->json(['error' => 'Invalid appId'], 400);
            }

            $cause = $request->input('cause'); // Uninstall, Suspend

            Log::info('МойСклад: Причина удаления', ['cause' => $cause]);

            // Находим аккаунт
            $account = DB::table('accounts')
                ->where('account_id', $accountId)
                ->first();

            if (!$account) {
                Log::warning('МойСклад: Аккаунт не найден при удалении', [
                    'accountId' => $accountId
                ]);
                return response()->json(['error' => 'Account not found'], 404);
            }

            Log::info('МойСклад: Аккаунт найден', [
                'accountId' => $accountId,
                'current_status' => $account->status
            ]);

            if ($cause === 'Suspend') {
                // Приостановка (не оплачена подписка)
                $updated = DB::table('accounts')
                    ->where('account_id', $accountId)
                    ->update([
                        'status' => 'suspended',
                        'suspended_at' => now(),
                        'updated_at' => now()
                    ]);

                Log::info('МойСклад: Обновление статуса suspended', [
                    'accountId' => $accountId,
                    'rows_affected' => $updated
                ]);

                // Отключаем вебхуки
                $webhooksDisabled = DB::table('webhooks')
                    ->where('account_id', $accountId)
                    ->update(['enabled' => false]);

                Log::info('МойСклад: Вебхуки отключены', [
                    'accountId' => $accountId,
                    'webhooks_disabled' => $webhooksDisabled
                ]);

                Log::info('МойСклад: Приложение приостановлено', [
                    'accountId' => $accountId
                ]);
            } else {
                // Полное удаление

                Log::info('МойСклад: Начало полного удаления', [
                    'accountId' => $accountId
                ]);

                // Архивируем данные
                DB::table('accounts_archive')->insert([
                    'account_id' => $accountId,
                    'data' => json_encode($account),
                    'deleted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                Log::info('МойСклад: Данные архивированы', [
                    'accountId' => $accountId
                ]);

                // Удаляем связанные данные
                // Благодаря foreign keys с cascade, остальное удалится автоматически
                $deleted = DB::table('accounts')
                    ->where('account_id', $accountId)
                    ->delete();

                Log::info('МойСклад: Аккаунт удален', [
                    'accountId' => $accountId,
                    'rows_deleted' => $deleted
                ]);
            }

            return response()->json(['status' => 'Success'], 200);

        } catch (\Exception $e) {
            Log::error('МойСклад: Ошибка удаления/приостановки', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'accountId' => $accountId
            ]);

            return response()->json([
                'error' => 'Uninstallation failed',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error'
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
    // ============ Вспомогательные методы ============

    /**
     * Создание настроек по умолчанию
     */
    private function createDefaultSettings(string $accountId): void
    {
        try {
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

            Log::info('МойСклад: Созданы настройки по умолчанию', [
                'accountId' => $accountId
            ]);
        } catch (\Exception $e) {
            Log::warning('МойСклад: Ошибка создания настроек', [
                'accountId' => $accountId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Включение вебхуков
     */
    private function enableWebhooks(string $accountId): void
    {
        $updated = DB::table('webhooks')
            ->where('account_id', $accountId)
            ->update(['enabled' => true]);

        Log::info('МойСклад: Вебхуки включены', [
            'accountId' => $accountId,
            'count' => $updated
        ]);
    }
}
