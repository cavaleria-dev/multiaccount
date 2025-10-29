<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookJob;
use App\Services\Webhook\WebhookReceiverService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер для обработки вебхуков МойСклад
 *
 * Responsibilities:
 * - Fast webhook validation (< 50ms target)
 * - Idempotency check via requestId
 * - Save to webhook_logs table
 * - Dispatch ProcessWebhookJob for async processing
 * - Return fast 200 OK response
 *
 * IMPORTANT: МойСклад webhook format:
 * {
 *   "events": [
 *     {
 *       "accountId": "...",
 *       "action": "CREATE",
 *       "meta": {
 *         "type": "product",
 *         "href": "..."
 *       }
 *     }
 *   ]
 * }
 *
 * accountId, action, meta are INSIDE each event in events array!
 */
class WebhookController extends Controller
{
    protected WebhookReceiverService $receiverService;

    public function __construct(WebhookReceiverService $receiverService)
    {
        $this->receiverService = $receiverService;
    }

    /**
     * Обработать вебхук от МойСклад
     *
     * Fast validation + save + dispatch to queue
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        $startTime = microtime(true);

        try {
            // 1. Get full payload
            $payload = $request->all();

            // 2. Get requestId from header (for idempotency)
            $requestId = $request->header('X-Lognex-WebHook-Request-Id');

            if (!$requestId) {
                Log::warning('Webhook received without requestId header', [
                    'payload' => $payload
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing X-Lognex-WebHook-Request-Id header'
                ], 400);
            }

            // 3. Validate and save using WebhookReceiverService
            try {
                $webhookLog = $this->receiverService->receive($payload, $requestId);
            } catch (\Exception $e) {
                // Check if it's a duplicate
                if (str_contains($e->getMessage(), 'Duplicate webhook')) {
                    $processingTime = (int) ((microtime(true) - $startTime) * 1000);

                    Log::info('Webhook duplicate detected', [
                        'request_id' => $requestId,
                        'processing_time_ms' => $processingTime
                    ]);

                    // Return 200 OK for duplicates (idempotent)
                    return response()->json([
                        'status' => 'duplicate',
                        'request_id' => $requestId
                    ], 200);
                }

                throw $e;
            }

            // 4. Dispatch async processing job
            ProcessWebhookJob::dispatch($webhookLog->id);

            // 5. Calculate processing time
            $processingTime = (int) ((microtime(true) - $startTime) * 1000);

            Log::info('Webhook accepted', [
                'request_id' => $requestId,
                'webhook_log_id' => $webhookLog->id,
                'account_id' => $webhookLog->account_id,
                'entity_type' => $webhookLog->entity_type,
                'action' => $webhookLog->action,
                'events_count' => $webhookLog->events_count,
                'processing_time_ms' => $processingTime
            ]);

            // 6. Return fast response
            return response()->json([
                'status' => 'accepted',
                'request_id' => $requestId,
                'webhook_log_id' => $webhookLog->id
            ], 200);

        } catch (\Exception $e) {
            $processingTime = (int) ((microtime(true) - $startTime) * 1000);

            Log::error('Webhook validation failed', [
                'request_id' => $request->header('X-Lognex-WebHook-Request-Id'),
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
