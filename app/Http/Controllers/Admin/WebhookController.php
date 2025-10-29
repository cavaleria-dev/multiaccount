<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Webhook\WebhookSetupService;
use App\Services\Webhook\WebhookHealthService;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected WebhookSetupService $setupService;
    protected WebhookHealthService $healthService;

    public function __construct(
        WebhookSetupService $setupService,
        WebhookHealthService $healthService
    ) {
        $this->setupService = $setupService;
        $this->healthService = $healthService;
    }

    /**
     * Показать список аккаунтов с вебхуками
     */
    public function index(Request $request)
    {
        try {
            // Получить все проблемные аккаунты
            $problemAccounts = $this->healthService->getAccountsWithProblems();

            // Получить ВСЕ активированные аккаунты (главные и дочерние)
            $allAccounts = Account::where('status', 'activated')
                ->orderBy('account_name')
                ->get();

            // Для каждого аккаунта получить health report
            $accountsWithHealth = $allAccounts->map(function($account) use ($problemAccounts) {
                try {
                    $healthReport = $this->healthService->getHealthReport($account->account_id);

                    // Использовать реальный тип аккаунта из БД
                    $accountType = $account->account_type ?? 'unknown';

                    return [
                        'account_id' => $account->account_id,
                        'account_name' => $account->account_name,
                        'account_type' => $accountType,
                        'health' => $healthReport,
                        'has_problems' => in_array($account->account_id, array_column($problemAccounts, 'account_id'))
                    ];
                } catch (\Exception $e) {
                    Log::error('Failed to get health report for account', [
                        'account_id' => $account->account_id,
                        'error' => $e->getMessage()
                    ]);

                    return [
                        'account_id' => $account->account_id,
                        'account_name' => $account->account_name,
                        'account_type' => $account->account_type ?? 'unknown',
                        'health' => null,
                        'has_problems' => false,
                        'error' => true
                    ];
                }
            });

            // Подсчитать статистику
            $stats = [
                'total' => $accountsWithHealth->count(),
                'healthy' => $accountsWithHealth->filter(function($acc) {
                    return $acc['health'] && $acc['health']['overall_health'] === 'healthy';
                })->count(),
                'degraded' => $accountsWithHealth->filter(function($acc) {
                    return $acc['health'] && in_array($acc['health']['overall_health'], ['degraded', 'warning']);
                })->count(),
                'critical' => $accountsWithHealth->filter(function($acc) {
                    return $acc['health'] && $acc['health']['overall_health'] === 'critical';
                })->count(),
            ];

            return view('admin.webhooks.index', [
                'accounts' => $accountsWithHealth,
                'stats' => $stats,
                'problemAccounts' => $problemAccounts,
            ]);

        } catch (\Exception $e) {
            Log::error('Error loading webhooks admin page', [
                'error' => $e->getMessage()
            ]);

            return view('admin.webhooks.index', [
                'accounts' => collect([]),
                'stats' => ['total' => 0, 'healthy' => 0, 'degraded' => 0, 'critical' => 0],
                'problemAccounts' => [],
                'error' => 'Ошибка загрузки данных: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Показать детали по аккаунту
     */
    public function show(Request $request, string $accountId)
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();

            // Использовать реальный тип аккаунта из БД
            $accountType = $account->account_type ?? $request->input('account_type', 'main');

            // Получить детальный health report
            $healthReport = $this->healthService->getHealthReport($accountId);

            return view('admin.webhooks.show', [
                'account' => $account,
                'accountType' => $accountType,
                'healthReport' => $healthReport,
            ]);

        } catch (\Exception $e) {
            Log::error('Error loading webhook details', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('admin.webhooks.index')
                ->with('error', 'Ошибка загрузки данных: ' . $e->getMessage());
        }
    }

    /**
     * Переустановить вебхуки для аккаунта
     */
    public function reinstall(Request $request, string $accountId)
    {
        try {
            $account = Account::where('account_id', $accountId)->firstOrFail();
            $accountType = $request->input('account_type', 'main');

            Log::info('Admin reinstalling webhooks', [
                'account_id' => $accountId,
                'account_type' => $accountType
            ]);

            $result = $this->setupService->reinstallWebhooks($account, $accountType);

            $message = sprintf(
                'Вебхуки переустановлены. Создано: %d, Ошибок: %d',
                $result['created'] ?? 0,
                count($result['errors'] ?? [])
            );

            if (!empty($result['errors'])) {
                $message .= '. Ошибки: ' . implode(', ', $result['errors']);
            }

            return redirect()->route('admin.webhooks.index')
                ->with('success', $message);

        } catch (\Exception $e) {
            Log::error('Error reinstalling webhooks', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('admin.webhooks.index')
                ->with('error', 'Ошибка переустановки вебхуков: ' . $e->getMessage());
        }
    }

    /**
     * Проверить здоровье вебхуков
     */
    public function healthCheck(Request $request, string $accountId)
    {
        try {
            $autoHeal = $request->input('auto_heal', false);

            Log::info('Admin health check', [
                'account_id' => $accountId,
                'auto_heal' => $autoHeal
            ]);

            if ($autoHeal) {
                $result = $this->healthService->autoHeal($accountId);

                $message = sprintf(
                    'Проверка здоровья завершена. Исправлено: %d, Ошибок: %d',
                    count($result['healed'] ?? []),
                    count($result['failed'] ?? [])
                );

                return redirect()->route('admin.webhooks.index')
                    ->with('success', $message);
            } else {
                $healthReport = $this->healthService->getHealthReport($accountId);

                $healthStatus = $healthReport['summary']['health_status'] ?? 'unknown';
                $message = "Статус здоровья: {$healthStatus}";

                return redirect()->route('admin.webhooks.index')
                    ->with('success', $message);
            }

        } catch (\Exception $e) {
            Log::error('Error checking webhook health', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('admin.webhooks.index')
                ->with('error', 'Ошибка проверки здоровья: ' . $e->getMessage());
        }
    }
}
