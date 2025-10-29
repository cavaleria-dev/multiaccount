<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Webhook\WebhookSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AccountController extends Controller
{
    protected WebhookSetupService $webhookSetupService;

    public function __construct(WebhookSetupService $webhookSetupService)
    {
        $this->webhookSetupService = $webhookSetupService;
    }

    /**
     * POST /api/account/set-type
     * Set or change account type
     */
    public function setAccountType(Request $request)
    {
        $request->validate([
            'account_type' => 'required|in:main,child'
        ]);

        // Получаем account_id из контекста (middleware уже загрузил)
        $context = $request->get('moysklad_context');
        $accountId = $context['accountId'] ?? null;

        if (!$accountId) {
            Log::error('Account ID not found in context', [
                'context' => $context
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Account ID not found in context'
            ], 400);
        }

        $newType = $request->input('account_type');

        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();
            $oldType = $account->account_type;

            $account->account_type = $newType;
            $account->save();

            // Если первый раз ИЛИ тип изменился -> (пере)установить вебхуки
            if ($oldType === null || $oldType !== $newType) {
                Log::info('Installing webhooks for account type', [
                    'account_id' => $accountId,
                    'old_type' => $oldType,
                    'new_type' => $newType
                ]);

                $result = $this->webhookSetupService->reinstallWebhooks($account, $newType);

                Log::info('Webhook installation completed', [
                    'account_id' => $accountId,
                    'account_type' => $newType,
                    'created' => count($result['created'] ?? []),
                    'errors' => count($result['errors'] ?? [])
                ]);

                // Если есть ошибки, логируем их
                if (!empty($result['errors'])) {
                    Log::warning('Some webhooks failed to install', [
                        'account_id' => $accountId,
                        'errors' => $result['errors']
                    ]);
                }
            } else {
                Log::info('Account type unchanged, skipping webhook reinstall', [
                    'account_id' => $accountId,
                    'account_type' => $newType
                ]);
            }

            return response()->json([
                'success' => true,
                'account_type' => $newType,
                'message' => 'Тип аккаунта успешно установлен'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to set account type', [
                'account_id' => $accountId,
                'new_type' => $newType,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/account/type
     * Get current account type
     */
    public function getAccountType(Request $request)
    {
        // Получаем account_id из контекста (middleware уже загрузил)
        $context = $request->get('moysklad_context');
        $accountId = $context['accountId'] ?? null;

        if (!$accountId) {
            Log::error('Account ID not found in context', [
                'context' => $context
            ]);
            return response()->json([
                'error' => 'Account ID not found in context'
            ], 400);
        }

        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            return response()->json([
                'account_type' => $account->account_type
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get account type', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'account_type' => null
            ], 500);
        }
    }
}
