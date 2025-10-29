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

            // If first time selection (was null) -> install ALL webhooks
            if ($oldType === null) {
                Log::info('First time account type selection - installing all webhooks', [
                    'account_id' => $accountId,
                    'new_type' => $newType
                ]);

                $this->installAllWebhooks($account);
            } else {
                Log::info('Account type changed - webhooks already installed', [
                    'account_id' => $accountId,
                    'old_type' => $oldType,
                    'new_type' => $newType
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
     * Install ALL 24 webhooks (main 15 + child 9)
     * Called only on first account type selection
     */
    protected function installAllWebhooks(Account $account): void
    {
        // Get all webhook configurations (main + child)
        $mainWebhooks = $this->webhookSetupService->getWebhooksConfig('main');
        $childWebhooks = $this->webhookSetupService->getWebhooksConfig('child');
        $allWebhooks = array_merge($mainWebhooks, $childWebhooks);

        $webhookUrl = config('moysklad.webhook_url');
        $installed = 0;
        $errors = [];

        Log::info('Starting installation of all webhooks', [
            'account_id' => $account->account_id,
            'total_webhooks' => count($allWebhooks),
            'webhook_url' => $webhookUrl
        ]);

        foreach ($allWebhooks as $config) {
            try {
                $webhook = $this->webhookSetupService->createWebhook(
                    $account,
                    $webhookUrl,
                    $config['action'],
                    $config['entityType']
                );

                $installed++;

                Log::debug('Webhook installed', [
                    'account_id' => $account->account_id,
                    'entity_type' => $config['entityType'],
                    'action' => $config['action'],
                    'webhook_id' => $webhook['id'] ?? 'unknown'
                ]);
            } catch (\Exception $e) {
                $errors[] = [
                    'entity' => $config['entityType'],
                    'action' => $config['action'],
                    'error' => $e->getMessage()
                ];

                Log::error('Failed to install webhook', [
                    'account_id' => $account->account_id,
                    'entity_type' => $config['entityType'],
                    'action' => $config['action'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('Webhook installation completed', [
            'account_id' => $account->account_id,
            'installed' => $installed,
            'failed' => count($errors),
            'errors' => $errors
        ]);
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
