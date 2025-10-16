<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы с JSON API 1.2 МойСклад
 *
 * Документация: https://dev.moysklad.ru/doc/api/remap/1.2/
 */
class MoySkladService
{
    protected string $apiUrl;
    protected string $accessToken;
    protected int $timeout;
    protected RateLimitHandler $rateLimitHandler;
    protected ?ApiLogService $apiLogService = null;

    // Контекст для логирования
    protected ?string $accountId = null;
    protected ?string $direction = null;
    protected ?string $relatedAccountId = null;
    protected ?string $entityType = null;
    protected ?string $entityId = null;

    public function __construct(RateLimitHandler $rateLimitHandler, ?ApiLogService $apiLogService = null)
    {
        $this->apiUrl = config('moysklad.api_url');
        $this->timeout = config('moysklad.timeout', 30);
        $this->rateLimitHandler = $rateLimitHandler;
        $this->apiLogService = $apiLogService;
    }

    /**
     * Установить токен доступа
     */
    public function setAccessToken(string $token): self
    {
        $this->accessToken = $token;
        return $this;
    }

    /**
     * Установить контекст для логирования
     */
    public function setLogContext(
        ?string $accountId = null,
        ?string $direction = null,
        ?string $relatedAccountId = null,
        ?string $entityType = null,
        ?string $entityId = null
    ): self {
        $this->accountId = $accountId;
        $this->direction = $direction;
        $this->relatedAccountId = $relatedAccountId;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        return $this;
    }

    /**
     * Очистить контекст логирования
     */
    public function clearLogContext(): self
    {
        $this->accountId = null;
        $this->direction = null;
        $this->relatedAccountId = null;
        $this->entityType = null;
        $this->entityId = null;
        return $this;
    }

    /**
     * Выполнить GET запрос
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    /**
     * Выполнить POST запрос
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, [], $data);
    }

    /**
     * Выполнить PUT запрос
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, [], $data);
    }

    /**
     * Выполнить DELETE запрос
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Базовый метод для выполнения запросов
     */
    protected function request(
        string $method,
        string $endpoint,
        array $params = [],
        array $data = []
    ): array {
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');
        $startTime = microtime(true);

        $headers = [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Accept-Encoding' => 'gzip',
            'Content-Type' => 'application/json',
        ];

        $responseStatus = null;
        $responseBody = null;
        $errorMessage = null;
        $rateLimitInfo = [];

        try {
            // Увеличиваем максимальный размер ответа до 10MB (по умолчанию 2MB в Guzzle)
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->withOptions([
                    'stream' => false, // Отключить стриминг для получения полного body
                    'decode_content' => true, // Декодировать gzip
                ])
                ->retry(3, 100)
                ->{strtolower($method)}($url, $method === 'GET' ? $params : $data);

            $responseStatus = $response->status();

            // Получаем raw body в первую очередь (до любой обработки)
            $rawBody = $response->body();
            $bodySize = strlen($rawBody);

            // Пытаемся распарсить JSON вручную с контролем размера
            $responseBody = $this->parseResponseBody($rawBody, $bodySize);

            // Извлечь информацию о rate limits и специальных заголовков МойСклад
            $rateLimitInfo = $this->rateLimitHandler->extractFromHeaders($response->headers());

            // Добавить специальные заголовки МойСклад в rateLimitInfo
            $rateLimitInfo = $this->enrichWithMoySkladHeaders($response->headers(), $rateLimitInfo);

            // Добавить метаинформацию о размере ответа
            $rateLimitInfo['response_size'] = $bodySize;
            $rateLimitInfo['response_truncated'] = isset($responseBody['_truncated']) ? true : false;

            // Проверить специальные HTTP статусы МойСклад
            $statusHandling = $this->handleSpecialHttpStatus($responseStatus, $rateLimitInfo);
            if ($statusHandling !== null) {
                $errorMessage = $statusHandling['message'];
                $this->logApiRequest($method, $url, $params ?: $data, $responseStatus, $responseBody, $errorMessage, $rateLimitInfo, $startTime);

                // Если статус требует exception, выбрасываем
                if ($statusHandling['throw_exception']) {
                    if ($responseStatus === 429) {
                        throw new \App\Exceptions\RateLimitException(
                            $errorMessage,
                            $rateLimitInfo['retry_after'] ?? 1000,
                            $rateLimitInfo
                        );
                    }
                    throw new \Exception($errorMessage);
                }

                // Для редиректов возвращаем успешный результат с Location
                return [
                    'data' => $responseBody,
                    'rateLimitInfo' => $rateLimitInfo,
                    'redirect' => $rateLimitInfo['location'] ?? null
                ];
            }

            if ($response->failed()) {
                // Парсить структуру ошибок МойСклад (с HTTP status context)
                $errorMessage = $this->parseErrorMessage($responseBody, $responseStatus);

                Log::error('МойСклад API Error', [
                    'method' => $method,
                    'url' => $url,
                    'status' => $response->status(),
                    'parsed_error' => $errorMessage,
                    'errors_array' => $responseBody['errors'] ?? [],
                    'raw_body_size' => $bodySize
                ]);

                // Логировать запрос
                $this->logApiRequest($method, $url, $params ?: $data, $responseStatus, $responseBody, $errorMessage, $rateLimitInfo, $startTime);

                throw new \Exception($errorMessage);
            }

            // Логировать успешный запрос
            $this->logApiRequest($method, $url, $params ?: $data, $responseStatus, $responseBody, null, $rateLimitInfo, $startTime);

            // Вернуть данные вместе с информацией о rate limits
            return [
                'data' => $response->json(),
                'rateLimitInfo' => $rateLimitInfo
            ];

        } catch (\App\Exceptions\RateLimitException $e) {
            // Пробросить RateLimitException дальше
            throw $e;

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            Log::error('МойСклад API Exception', [
                'method' => $method,
                'url' => $url,
                'error' => $errorMessage
            ]);

            // Логировать запрос с ошибкой
            if ($responseStatus === null) {
                $responseStatus = 0; // Сетевая ошибка или exception до получения ответа
            }
            $this->logApiRequest($method, $url, $params ?: $data, $responseStatus, $responseBody, $errorMessage, $rateLimitInfo, $startTime);

            throw $e;
        }
    }

    /**
     * Логировать API-запрос
     */
    protected function logApiRequest(
        string $method,
        string $url,
        array $requestPayload,
        ?int $responseStatus,
        $responseBody,
        ?string $errorMessage,
        array $rateLimitInfo,
        float $startTime
    ): void {
        // Пропустить логирование, если сервис не внедрен или нет accountId
        if (!$this->apiLogService || !$this->accountId) {
            return;
        }

        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        $this->apiLogService->logRequest([
            'account_id' => $this->accountId,
            'direction' => $this->direction,
            'related_account_id' => $this->relatedAccountId,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'method' => $method,
            'endpoint' => $url,
            'request_payload' => $requestPayload,
            'response_status' => $responseStatus,
            'response_body' => $responseBody,
            'error_message' => $errorMessage,
            'rate_limit_info' => $rateLimitInfo,
            'duration_ms' => $durationMs,
        ]);
    }

    // ============ Методы для работы с товарами ============

    /**
     * Получить список товаров
     */
    public function getProducts(array $filters = []): array
    {
        return $this->get('entity/product', $filters);
    }

    /**
     * Получить товар по ID
     */
    public function getProduct(string $productId): array
    {
        return $this->get("entity/product/{$productId}");
    }

    /**
     * Создать товар
     */
    public function createProduct(array $data): array
    {
        return $this->post('entity/product', $data);
    }

    /**
     * Обновить товар
     */
    public function updateProduct(string $productId, array $data): array
    {
        return $this->put("entity/product/{$productId}", $data);
    }

    // ============ Методы для работы с заказами ============

    /**
     * Получить список заказов покупателей
     */
    public function getCustomerOrders(array $filters = []): array
    {
        return $this->get('entity/customerorder', $filters);
    }

    /**
     * Получить заказ по ID
     */
    public function getCustomerOrder(string $orderId): array
    {
        return $this->get("entity/customerorder/{$orderId}");
    }

    /**
     * Создать заказ покупателя
     */
    public function createCustomerOrder(array $data): array
    {
        return $this->post('entity/customerorder', $data);
    }

    /**
     * Обновить заказ покупателя
     */
    public function updateCustomerOrder(string $orderId, array $data): array
    {
        return $this->put("entity/customerorder/{$orderId}", $data);
    }

    // ============ Методы для работы с вебхуками ============

    /**
     * Получить список вебхуков
     */
    public function getWebhooks(): array
    {
        $response = $this->get('entity/webhook');
        return $response['rows'] ?? [];
    }

    /**
     * Создать вебхук
     */
    public function createWebhook(string $url, string $action, string $entityType): array
    {
        $data = [
            'url' => $url,
            'action' => $action,
            'entityType' => $entityType
        ];

        return $this->post('entity/webhook', $data);
    }

    /**
     * Удалить вебхук
     */
    public function deleteWebhook(string $webhookId): array
    {
        return $this->delete("entity/webhook/{$webhookId}");
    }

    /**
     * Создать все необходимые вебхуки для приложения
     */
    public function setupWebhooks(string $accountId): array
    {
        $webhookUrl = config('moysklad.webhook_url');
        $createdWebhooks = [];

        // Вебхуки для товаров
        $productEvents = ['CREATE', 'UPDATE', 'DELETE'];
        foreach ($productEvents as $action) {
            try {
                $webhook = $this->createWebhook($webhookUrl, $action, 'product');
                $createdWebhooks[] = $webhook;

                // Сохраняем в БД
                \DB::table('webhooks')->insert([
                    'account_id' => $accountId,
                    'webhook_id' => $webhook['id'],
                    'entity_type' => 'product',
                    'action' => $action,
                    'url' => $webhookUrl,
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } catch (\Exception $e) {
                Log::error('Ошибка создания вебхука', [
                    'action' => $action,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Вебхуки для заказов
        $orderEvents = ['CREATE', 'UPDATE'];
        foreach ($orderEvents as $action) {
            try {
                $webhook = $this->createWebhook($webhookUrl, $action, 'customerorder');
                $createdWebhooks[] = $webhook;

                \DB::table('webhooks')->insert([
                    'account_id' => $accountId,
                    'webhook_id' => $webhook['id'],
                    'entity_type' => 'customerorder',
                    'action' => $action,
                    'url' => $webhookUrl,
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } catch (\Exception $e) {
                Log::error('Ошибка создания вебхука', [
                    'action' => $action,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $createdWebhooks;
    }

    // ============ Методы для работы с доп.полями ============

    /**
     * Получить метаданные для сущности
     */
    public function getMetadata(string $entityType): array
    {
        return $this->get("entity/{$entityType}/metadata");
    }

    /**
     * Создать дополнительное поле
     */
    public function createAttribute(string $entityType, array $attributeData): array
    {
        return $this->post("entity/{$entityType}/metadata/attributes", $attributeData);
    }

    /**
     * Получить список характеристик
     */
    public function getCharacteristics(string $productId): array
    {
        $response = $this->get("entity/variant", [
            'filter' => "product={$productId}"
        ]);

        return $response['rows'] ?? [];
    }

    // ============ Методы для работы со складами ============

    /**
     * Получить список складов
     */
    public function getStores(): array
    {
        $response = $this->get('entity/store');
        return $response['rows'] ?? [];
    }

    /**
     * Получить остатки товаров
     */
    public function getStockByStore(string $storeId): array
    {
        return $this->get('report/stock/bystore', [
            'filter' => "store={$storeId}"
        ]);
    }

    // ============ Методы для работы с ценами ============

    /**
     * Получить типы цен
     */
    public function getPriceTypes(): array
    {
        $response = $this->get('context/companysettings');
        return $response['data']['priceTypes'] ?? [];
    }

    // ============ Методы для работы с контекстом приложения ============

    /**
     * Декодировать JWT токен контекста
     */
    public function decodeContextKey(string $contextKey): ?array
    {
        try {
            Log::info('Декодирование JWT токена', [
                'token_length' => strlen($contextKey),
                'token_preview' => substr($contextKey, 0, 20) . '...'
            ]);

            // JWT токен состоит из трех частей, разделенных точками
            $parts = explode('.', $contextKey);

            if (count($parts) !== 3) {
                Log::error('Invalid JWT token format', [
                    'parts_count' => count($parts),
                    'token' => $contextKey
                ]);
                return null;
            }

            // Декодируем payload (вторая часть)
            // JWT использует base64url encoding, нужно заменить символы
            $base64 = str_replace(['-', '_'], ['+', '/'], $parts[1]);
            // Добавляем padding если нужно
            $base64 = str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '=', STR_PAD_RIGHT);

            $payload = base64_decode($base64);

            if (!$payload) {
                Log::error('Failed to decode JWT payload', [
                    'base64_part' => substr($base64, 0, 50)
                ]);
                return null;
            }

            Log::info('JWT payload decoded', [
                'payload' => $payload
            ]);

            $data = json_decode($payload, true);

            if (!$data) {
                Log::error('Failed to parse JWT payload JSON', [
                    'payload' => $payload,
                    'json_error' => json_last_error_msg()
                ]);
                return null;
            }

            Log::info('JWT token decoded successfully', [
                'data_keys' => array_keys($data)
            ]);

            // Проверяем срок действия токена
            if (isset($data['exp']) && $data['exp'] < time()) {
                Log::warning('JWT token has expired', [
                    'exp' => $data['exp'],
                    'now' => time()
                ]);
                return null;
            }

            return $data;

        } catch (\Exception $e) {
            Log::error('Error decoding context key', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Получить статистику для аккаунта
     */
    public function getAccountStats(?string $accountId): array
    {
        if (!$accountId) {
            return [
                'childAccounts' => 0,
                'activeAccounts' => 0,
                'syncsToday' => 0
            ];
        }

        try {
            // Получаем количество дочерних аккаунтов
            $childAccountsCount = \DB::table('child_accounts')
                ->where('parent_account_id', $accountId)
                ->count();

            // Получаем количество активных аккаунтов
            $activeAccountsCount = \DB::table('child_accounts')
                ->where('parent_account_id', $accountId)
                ->where('is_active', true)
                ->count();

            // Получаем количество синхронизаций за сегодня
            $syncsToday = \DB::table('sync_logs')
                ->where('parent_account_id', $accountId)
                ->whereDate('created_at', today())
                ->count();

            return [
                'childAccounts' => $childAccountsCount,
                'activeAccounts' => $activeAccountsCount,
                'syncsToday' => $syncsToday
            ];

        } catch (\Exception $e) {
            Log::error('Error getting account stats', [
                'accountId' => $accountId,
                'error' => $e->getMessage()
            ]);

            return [
                'childAccounts' => 0,
                'activeAccounts' => 0,
                'syncsToday' => 0
            ];
        }
    }

    /**
     * Обработать специальные HTTP статусы МойСклад
     *
     * Обрабатываются только статусы, требующие специальной логики:
     * - 3xx редиректы (возврат Location без exception)
     * - 429 Rate Limit (выбросить RateLimitException с retry_after)
     *
     * Остальные ошибки (400, 404, 500 и т.д.) обрабатываются через parseErrorMessage()
     * чтобы получить детальное сообщение из response body.
     *
     * Документация: https://dev.moysklad.ru/doc/api/remap/1.2/#mojsklad-json-api-oshibki
     *
     * @param int $status HTTP status code
     * @param array $rateLimitInfo Rate limit info (может содержать location для редиректов)
     * @return array|null ['message' => string, 'throw_exception' => bool] или null если обработка не нужна
     */
    protected function handleSpecialHttpStatus(int $status, array $rateLimitInfo): ?array
    {
        // 3xx - Редиректы (не ошибки, требуют специальной обработки)
        if (in_array($status, [301, 302, 303])) {
            $redirectMessages = [
                301 => 'Moved Permanently - запрашиваемый ресурс находится по другому URL',
                302 => 'Found - ресурс временно находится по другому URI',
                303 => 'See Other - ресурс доступен по другому URI (используйте GET)',
            ];

            $message = "[HTTP {$status}] " . $redirectMessages[$status];

            if (isset($rateLimitInfo['location'])) {
                $message .= " → {$rateLimitInfo['location']}";
            }

            return [
                'message' => $message,
                'throw_exception' => false // Редиректы не выбрасывают exception
            ];
        }

        // 429 - Rate Limit (требует RateLimitException с retry_after)
        if ($status === 429) {
            $message = "[HTTP 429] Too Many Requests - превышен лимит количества запросов";

            if (isset($rateLimitInfo['retry_after'])) {
                $retrySeconds = round($rateLimitInfo['retry_after'] / 1000, 1);
                $message .= " (retry after {$retrySeconds}s)";
            }

            return [
                'message' => $message,
                'throw_exception' => true
            ];
        }

        // Все остальные статусы (400, 404, 500 и т.д.) обрабатываются через parseErrorMessage()
        return null;
    }

    /**
     * Распарсить тело ответа с обработкой больших данных
     *
     * @param string $rawBody Raw body от HTTP response
     * @param int $bodySize Размер в байтах
     * @return array Распарсированный JSON или структура с raw body
     */
    protected function parseResponseBody(string $rawBody, int $bodySize): array
    {
        // Лимит: 5MB для сохранения в БД (PostgreSQL JSON)
        $maxSaveSize = 5 * 1024 * 1024;

        // Пустой ответ
        if (empty($rawBody)) {
            return ['_empty' => true];
        }

        // Попытка распарсить JSON
        $parsed = json_decode($rawBody, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            // JSON успешно распарсился

            // Если размер слишком большой, обрезаем но сохраняем ключевые поля
            if ($bodySize > $maxSaveSize) {
                $truncated = [
                    '_truncated' => true,
                    '_original_size' => $bodySize,
                    '_truncated_reason' => 'Response too large for database storage (max 5MB)'
                ];

                // Сохранить важные поля если есть
                if (isset($parsed['errors'])) {
                    $truncated['errors'] = $parsed['errors']; // Ошибки всегда сохраняем
                }
                if (isset($parsed['meta'])) {
                    $truncated['meta'] = $parsed['meta']; // Метаданные
                }
                if (isset($parsed['rows']) && is_array($parsed['rows'])) {
                    $truncated['rows_count'] = count($parsed['rows']);
                    $truncated['first_row'] = $parsed['rows'][0] ?? null;
                }

                return $truncated;
            }

            return $parsed;
        }

        // JSON не распарсился
        $error = [
            '_parse_error' => json_last_error_msg(),
            '_original_size' => $bodySize
        ];

        // Если размер небольшой, сохраняем raw body
        if ($bodySize < $maxSaveSize) {
            $error['raw'] = $rawBody;
        } else {
            // Сохраняем только начало и конец
            $error['raw_preview_start'] = substr($rawBody, 0, 1000);
            $error['raw_preview_end'] = substr($rawBody, -1000);
            $error['_truncated'] = true;
        }

        return $error;
    }

    /**
     * Добавить специальные заголовки МойСклад к rate limit info
     *
     * @param array $headers Заголовки от HTTP response
     * @param array $rateLimitInfo Существующая информация о rate limits
     * @return array Расширенная информация
     */
    protected function enrichWithMoySkladHeaders(array $headers, array $rateLimitInfo): array
    {
        // X-Lognex-Auth - код ошибки аутентификации
        if (isset($headers['X-Lognex-Auth'])) {
            $rateLimitInfo['lognex_auth_code'] = $this->getHeaderValue($headers['X-Lognex-Auth']);
        }

        // X-Lognex-Auth-Message - сообщение об ошибке аутентификации
        if (isset($headers['X-Lognex-Auth-Message'])) {
            $rateLimitInfo['lognex_auth_message'] = $this->getHeaderValue($headers['X-Lognex-Auth-Message']);
        }

        // X-Lognex-API-Version-Deprecated - дата отключения API
        if (isset($headers['X-Lognex-API-Version-Deprecated'])) {
            $rateLimitInfo['api_version_deprecated'] = $this->getHeaderValue($headers['X-Lognex-API-Version-Deprecated']);
        }

        // Location - URL для редиректов (301, 302, 303)
        if (isset($headers['Location'])) {
            $rateLimitInfo['location'] = $this->getHeaderValue($headers['Location']);
        }

        return $rateLimitInfo;
    }

    /**
     * Получить значение заголовка (может быть массивом или строкой)
     */
    protected function getHeaderValue($headerValue): string
    {
        if (is_array($headerValue)) {
            return $headerValue[0] ?? '';
        }

        return (string) $headerValue;
    }

    /**
     * Парсить сообщение об ошибке из ответа МойСклад API
     *
     * Структура ошибок МойСклад:
     * {
     *   "errors": [
     *     {
     *       "error": "Ошибка сохранения объекта",
     *       "error_message": "Поле 'name' обязательно",
     *       "code": 1016,
     *       "parameter": "name",
     *       "moreInfo": "https://dev.moysklad.ru/...",
     *       "line": 12,
     *       "column": 34
     *     }
     *   ]
     * }
     *
     * @param mixed $responseBody Ответ от API (array или null)
     * @param int|null $httpStatus HTTP status code для добавления контекста
     * @return string Форматированное сообщение об ошибке
     */
    protected function parseErrorMessage($responseBody, ?int $httpStatus = null): string
    {
        // Добавить HTTP status context
        $statusContext = '';
        if ($httpStatus !== null) {
            $statusDescriptions = [
                400 => 'Bad Request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not Found',
                405 => 'Method Not Allowed',
                409 => 'Conflict',
                410 => 'API Deprecated',
                412 => 'Precondition Failed',
                413 => 'Payload Too Large',
                414 => 'URI Too Long',
                415 => 'Unsupported Media Type',
                500 => 'Internal Server Error',
                502 => 'Bad Gateway',
                503 => 'Service Unavailable',
                504 => 'Gateway Timeout',
            ];

            $statusDescription = $statusDescriptions[$httpStatus] ?? 'Error';
            $statusContext = "[HTTP {$httpStatus} {$statusDescription}] ";
        }

        // Если нет структуры errors в ответе
        if (!is_array($responseBody) || !isset($responseBody['errors'])) {
            return $statusContext . 'API request failed: Unknown error';
        }

        $errors = $responseBody['errors'];
        if (empty($errors) || !is_array($errors)) {
            return $statusContext . 'API request failed: Empty error response';
        }

        // Извлечь первую ошибку (обычно самая критичная)
        $firstError = $errors[0];

        $parts = [];

        // Основное сообщение об ошибке
        if (isset($firstError['error'])) {
            $parts[] = $firstError['error'];
        }

        // Детальное описание
        if (isset($firstError['error_message'])) {
            $parts[] = $firstError['error_message'];
        }

        // Код ошибки МойСклад
        if (isset($firstError['code'])) {
            $parts[] = "Code: {$firstError['code']}";
        }

        // Параметр, вызвавший ошибку
        if (isset($firstError['parameter'])) {
            $parts[] = "Parameter: {$firstError['parameter']}";
        }

        if (empty($parts)) {
            return $statusContext . 'API request failed: Malformed error response';
        }

        return $statusContext . implode(' | ', $parts);
    }
}