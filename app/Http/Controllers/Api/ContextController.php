<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MoySkladService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContextController extends Controller
{
    public function __construct(
        private MoySkladService $moySkladService
    ) {}

    /**
     * Получить контекст приложения из JWT токена
     */
    public function getContext(Request $request): JsonResponse
    {
        try {
            $contextKey = $request->input('contextKey');

            if (!$contextKey) {
                return response()->json([
                    'error' => 'Context key is required'
                ], 400);
            }

            // Декодируем JWT токен
            $context = $this->moySkladService->decodeContextKey($contextKey);

            if (!$context) {
                return response()->json([
                    'error' => 'Invalid context key'
                ], 401);
            }

            return response()->json([
                'accountId' => $context['accountId'] ?? null,
                'accountName' => $context['accountName'] ?? 'Неизвестный аккаунт',
                'userId' => $context['uid'] ?? null,
                'appId' => $context['appId'] ?? null,
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

            $context = $this->moySkladService->decodeContextKey($contextKey);

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
