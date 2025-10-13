<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MoySkladService;
use App\Services\VendorApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContextController extends Controller
{
    public function __construct(
        private MoySkladService $moySkladService,
        private VendorApiService $vendorApiService
    ) {}

    /**
     * Получить контекст приложения через Vendor API МойСклад
     */
    public function getContext(Request $request): JsonResponse
    {
        try {
            $contextKey = $request->input('contextKey');
            $appUid = $request->input('appUid');

            if (!$contextKey) {
                return response()->json([
                    'error' => 'Context key is required'
                ], 400);
            }

            if (!$appUid) {
                return response()->json([
                    'error' => 'App UID is required'
                ], 400);
            }

            Log::info('Запрос контекста пользователя', [
                'contextKey' => substr($contextKey, 0, 20) . '...',
                'appUid' => $appUid
            ]);

            // Запрашиваем контекст через Vendor API МойСклад
            $context = $this->vendorApiService->getContext($contextKey, $appUid);

            if (!$context) {
                return response()->json([
                    'error' => 'Invalid context key or API error'
                ], 401);
            }

            // Извлекаем нужные данные из ответа МойСклад
            return response()->json([
                'accountId' => $context['accountId'] ?? null,
                'accountName' => $context['accountName'] ?? 'Неизвестный аккаунт',
                'userId' => $context['uid'] ?? null,
                'appId' => $context['appId'] ?? null,
                'permissions' => $context['permissions'] ?? [],
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting context', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to get context'
            ], 500);
        }
    }

    /**
     * Получить статистику для текущего аккаунта
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            // Получаем контекст из заголовка
            $contextKey = $request->header('X-Context-Key');

            if (!$contextKey) {
                return response()->json([
                    'error' => 'Context key is required'
                ], 400);
            }

            // Запрашиваем контекст через Vendor API
            $context = $this->vendorApiService->getContext($contextKey);

            if (!$context) {
                return response()->json([
                    'error' => 'Invalid context key'
                ], 401);
            }

            $accountId = $context['accountId'] ?? null;

            // Получаем статистику из базы данных
            $stats = $this->moySkladService->getAccountStats($accountId);

            return response()->json($stats);

        } catch (\Exception $e) {
            Log::error('Error getting stats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to get stats'
            ], 500);
        }
    }
}
