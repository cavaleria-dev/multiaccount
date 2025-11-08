<?php

namespace App\Services\Webhook;

use App\Models\Webhook;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

/**
 * WebhookReceiverService
 *
 * Fast webhook validation and logging service
 *
 * Responsibilities:
 * - Validate webhook payload structure (MUST be < 50ms)
 * - Check for duplicates via requestId (idempotency)
 * - Save webhook to webhook_logs table
 * - Update webhook counters (total_received)
 *
 * CRITICAL: This service must be FAST
 * МойСклад expects response within 1500ms
 */
class WebhookReceiverService
{
    /**
     * Receive and process incoming webhook
     *
     * @param array $payload Full webhook payload from МойСклад
     * @param string $requestId requestId from query parameter
     * @return WebhookLog
     * @throws \Exception If validation fails or duplicate detected
     */
    public function receive(array $payload, string $requestId): WebhookLog
    {
        $startTime = microtime(true);

        // 1. Validate payload structure (throws exception if invalid)
        $this->validate($payload);

        // 2. Check for duplicate (idempotency)
        if (WebhookLog::isDuplicate($requestId)) {
            $existing = WebhookLog::where('request_id', $requestId)->first();

            Log::info('Webhook duplicate detected (idempotency)', [
                'request_id' => $requestId,
                'existing_log_id' => $existing->id,
                'status' => $existing->status,
            ]);

            throw new \Exception("Duplicate webhook: requestId={$requestId} already processed");
        }

        // 3. Extract basic info from first event
        $events = $payload['events'];
        $firstEvent = $events[0];

        $accountId = $firstEvent['accountId'] ?? null;
        $entityType = $firstEvent['meta']['type'] ?? null;
        $action = $firstEvent['action'] ?? null;

        if (!$accountId || !$entityType || !$action) {
            throw new \Exception('Missing required fields in webhook event');
        }

        // 3.5. Extract updatedFields for UPDATE events (for partial sync)
        $updatedFields = null;
        if ($action === 'UPDATE' && isset($firstEvent['updatedFields']) && is_array($firstEvent['updatedFields'])) {
            $updatedFields = $firstEvent['updatedFields'];

            Log::debug('UPDATE webhook with updatedFields', [
                'request_id' => $requestId,
                'entity_type' => $entityType,
                'updated_fields' => $updatedFields,
            ]);
        }

        // 4. Find webhook record (optional, may not exist yet)
        $webhook = Webhook::where('account_id', $accountId)
                         ->where('entity_type', $entityType)
                         ->where('action', $action)
                         ->first();

        // 5. Create webhook log
        $webhookLog = WebhookLog::create([
            'request_id' => $requestId,
            'account_id' => $accountId,
            'webhook_id' => $webhook?->id,
            'entity_type' => $entityType,
            'action' => $action,
            'payload' => $payload,
            'updated_fields' => $updatedFields, // Save updatedFields for partial sync
            'status' => 'pending',
            'events_count' => count($events),
        ]);

        // 6. Update webhook counters (if webhook exists)
        if ($webhook) {
            $webhook->incrementReceived();
        }

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('Webhook received and logged', [
            'request_id' => $requestId,
            'log_id' => $webhookLog->id,
            'account_id' => $accountId,
            'entity_type' => $entityType,
            'action' => $action,
            'events_count' => count($events),
            'updated_fields_count' => $updatedFields ? count($updatedFields) : 0,
            'execution_time_ms' => $executionTime,
        ]);

        return $webhookLog;
    }

    /**
     * Validate webhook payload structure
     *
     * @param array $payload Webhook payload from МойСклад
     * @return void
     * @throws \Exception If payload is invalid
     */
    public function validate(array $payload): void
    {
        // Check if 'events' array exists
        if (!isset($payload['events']) || !is_array($payload['events'])) {
            throw new \Exception('Invalid webhook payload: missing "events" array');
        }

        // Check if events array is not empty
        if (empty($payload['events'])) {
            throw new \Exception('Invalid webhook payload: "events" array is empty');
        }

        // Validate each event has required fields
        foreach ($payload['events'] as $index => $event) {
            // Check 'action' field
            if (!isset($event['action'])) {
                throw new \Exception("Invalid webhook event #{$index}: missing 'action' field");
            }

            // Check 'meta' object
            if (!isset($event['meta']) || !is_array($event['meta'])) {
                throw new \Exception("Invalid webhook event #{$index}: missing 'meta' object");
            }

            // Check 'meta.type' field
            if (!isset($event['meta']['type'])) {
                throw new \Exception("Invalid webhook event #{$index}: missing 'meta.type' field");
            }

            // Check 'accountId' field
            if (!isset($event['accountId'])) {
                throw new \Exception("Invalid webhook event #{$index}: missing 'accountId' field");
            }
        }

        // Payload is valid
    }

    /**
     * Get summary of webhook payload (for logging)
     *
     * @param array $payload Webhook payload
     * @return array Summary info
     */
    public function getSummary(array $payload): array
    {
        $events = $payload['events'] ?? [];

        if (empty($events)) {
            return [
                'events_count' => 0,
                'entity_types' => [],
                'actions' => [],
            ];
        }

        $entityTypes = [];
        $actions = [];

        foreach ($events as $event) {
            $entityType = $event['meta']['type'] ?? 'unknown';
            $action = $event['action'] ?? 'unknown';

            if (!in_array($entityType, $entityTypes)) {
                $entityTypes[] = $entityType;
            }

            if (!in_array($action, $actions)) {
                $actions[] = $action;
            }
        }

        return [
            'events_count' => count($events),
            'entity_types' => $entityTypes,
            'actions' => $actions,
        ];
    }
}
