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

    public function __construct()
    {
        $this->apiUrl = config('moysklad.api_url');
        $this->timeout = config('moysklad.timeout', 30);
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

        $headers = [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Accept-Encoding' => 'gzip',
            'Content-Type' => 'application/json',
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->retry(3, 100)
                ->{strtolower($method)}($url, $method === 'GET' ? $params : $data);

            if ($response->failed()) {
                Log::error('МойСклад API Error', [
                    'method' => $method,
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                throw new \Exception('API request failed: ' . $response->body());
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('МойСклад API Exception', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
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
        $response = $this->get('context/companysettings/pricetype');
        return $response['priceTypes'] ?? [];
    }
}