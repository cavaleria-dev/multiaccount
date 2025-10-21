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
            $cause = $request->input('cause'); // Install, Resume, StatusUpdate, TariffChanged
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

            // accessToken обязателен только при Install, для TariffChanged необязателен
            if ($cause === 'Install' && !$accessToken) {
                Log::error('МойСклад: Отсутствует accessToken при установке', [
                    'has_accessToken' => !empty($accessToken),
                    'has_accountId' => !empty($accountId),
                    'access_array' => $access
                ]);
                return response()->json([
                    'error' => 'Missing required parameter: accessToken',
                    'details' => [
                        'accessToken' => 'missing',
                        'accountId' => !empty($accountId) ? 'present' : 'missing'
                    ]
                ], 400);
            }

            if (!$accountId) {
                Log::error('МойСклад: Отсутствует accountId', [
                    'has_accountId' => !empty($accountId)
                ]);
                return response()->json([
                    'error' => 'Missing required parameter: accountId'
                ], 400);
            }

            // Ищем существующий аккаунт
            $account = DB::table('accounts')->where('account_id', $accountId)->first();

            // Базовые данные для обновления
            $accountData = [
                'subscription_status' => $subscription['trial'] ?? false ? 'Trial' : 'Active',
                'cause' => $cause,
                'updated_at' => now()
            ];

            // Добавляем access_token только если он есть
            if ($accessToken) {
                $accountData['access_token'] = $accessToken;
            }

            // Добавляем accountName если есть
            if ($accountName) {
                $accountData['account_name'] = $accountName;
            }

            if ($cause === 'Install') {
                // Первая установка или переустановка приложения
                $accountData['app_id'] = $appId;
                $accountData['status'] = 'activated';
                $accountData['suspended_at'] = null;
                $accountData['uninstalled_at'] = null;
                $accountData['tariff_name'] = $subscription['tariffName'] ?? null;
                $accountData['tariff_id'] = $subscription['tariffId'] ?? null;
                $accountData['price_per_month'] = 0;

                // Парсим и сохраняем дату истечения подписки
                if (isset($subscription['expiryMoment'])) {
                    try {
                        $accountData['subscription_expires_at'] = new \DateTime($subscription['expiryMoment']);
                    } catch (\Exception $e) {
                        Log::warning('МойСклад: Не удалось распарсить expiryMoment', [
                            'expiryMoment' => $subscription['expiryMoment'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                if ($account) {
                    // Переустановка - обновляем существующую запись
                    $accountData['installed_at'] = $account->installed_at; // Сохраняем дату первой установки
                    DB::table('accounts')
                        ->where('account_id', $accountId)
                        ->update($accountData);

                    Log::info('МойСклад: Приложение переустановлено', [
                        'accountId' => $accountId,
                        'previous_status' => $account->status
                    ]);
                } else {
                    // Первая установка - создаем новую запись
                    $accountData['account_id'] = $accountId;
                    $accountData['installed_at'] = now();
                    $accountData['created_at'] = now();
                    DB::table('accounts')->insert($accountData);

                    Log::info('МойСклад: Новая установка приложения', [
                        'accountId' => $accountId
                    ]);
                }

                return response()->json([
                    'status' => 'Activated'
                ], 200);
            }

            if ($cause === 'Resume' || $cause === 'StatusUpdate') {
                // Возобновление работы после приостановки или обновление статуса
                if ($account) {
                    $updateData = [
                        'subscription_status' => $subscription['trial'] ?? false ? 'Trial' : 'Active',
                        'cause' => $cause,
                        'updated_at' => now()
                    ];

                    // Добавляем access_token только если он есть
                    if ($accessToken) {
                        $updateData['access_token'] = $accessToken;
                    }

                    // Если был приостановлен или удален - активируем
                    if (in_array($account->status, ['suspended', 'uninstalled'])) {
                        $updateData['status'] = 'activated';
                        $updateData['suspended_at'] = null;
                        $updateData['uninstalled_at'] = null;

                        Log::info('МойСклад: Приложение возобновлено', [
                            'accountId' => $accountId,
                            'previous_status' => $account->status,
                            'cause' => $cause
                        ]);
                    }

                    DB::table('accounts')
                        ->where('account_id', $accountId)
                        ->update($updateData);
                } else {
                    Log::warning('МойСклад: Resume/StatusUpdate для несуществующего аккаунта', [
                        'accountId' => $accountId,
                        'cause' => $cause
                    ]);
                }

                return response()->json([
                    'status' => 'Activated'
                ], 200);
            }

            if ($cause === 'TariffChanged') {
                // Изменение тарифа
                if ($account) {
                    $tariffData = [
                        'tariff_name' => $subscription['tariffName'] ?? null,
                        'tariff_id' => $subscription['tariffId'] ?? null,
                        'subscription_status' => $subscription['trial'] ?? false ? 'Trial' : 'Active',
                        'updated_at' => now()
                    ];

                    // Обновляем дату истечения подписки если есть
                    if (isset($subscription['expiryMoment'])) {
                        try {
                            $tariffData['subscription_expires_at'] = new \DateTime($subscription['expiryMoment']);
                        } catch (\Exception $e) {
                            Log::warning('МойСклад: Не удалось распарсить expiryMoment при смене тарифа', [
                                'expiryMoment' => $subscription['expiryMoment'],
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    DB::table('accounts')
                        ->where('account_id', $accountId)
                        ->update($tariffData);

                    Log::info('МойСклад: Тариф изменен', [
                        'accountId' => $accountId,
                        'tariff' => $subscription['tariffName'] ?? null,
                        'tariffId' => $subscription['tariffId'] ?? null
                    ]);
                }

                return response()->json([
                    'status' => 'Activated'
                ], 200);
            }

            // Неизвестный cause - просто обновляем данные
            if ($account) {
                DB::table('accounts')
                    ->where('account_id', $accountId)
                    ->update($accountData);
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
                // Данные остаются в БД, только меняется статус
                $updated = DB::table('accounts')
                    ->where('account_id', $accountId)
                    ->update([
                        'status' => 'suspended',
                        'suspended_at' => now(),
                        'updated_at' => now()
                    ]);

                Log::info('МойСклад: Приложение приостановлено', [
                    'accountId' => $accountId,
                    'rows_affected' => $updated
                ]);
            } else {
                // Деактивация (пользователь удалил приложение)
                // Данные остаются в БД, только меняется статус
                $updated = DB::table('accounts')
                    ->where('account_id', $accountId)
                    ->update([
                        'status' => 'uninstalled',
                        'uninstalled_at' => now(),
                        'updated_at' => now()
                    ]);

                Log::info('МойСклад: Приложение деактивировано', [
                    'accountId' => $accountId,
                    'rows_affected' => $updated,
                    'cause' => $cause
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
                'uninstalled' => 'NotFound', // Для МойСклада uninstalled = NotFound
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

}
