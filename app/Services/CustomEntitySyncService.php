<?php

namespace App\Services;

use App\Models\CustomEntityMapping;
use App\Models\CustomEntityElementMapping;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации пользовательских справочников между аккаунтами
 */
class CustomEntitySyncService
{
    protected CustomEntityService $customEntityService;

    public function __construct(CustomEntityService $customEntityService)
    {
        $this->customEntityService = $customEntityService;
    }

    /**
     * Синхронизировать справочник (создать если не существует)
     *
     * @param string $parentAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $customEntityName Название справочника
     * @return array ['parent_id' => ..., 'child_id' => ...]
     */
    public function syncCustomEntity(
        string $parentAccountId,
        string $childAccountId,
        string $customEntityName
    ): array {
        try {
            // Проверить маппинг
            $mapping = CustomEntityMapping::where('parent_account_id', $parentAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('custom_entity_name', $customEntityName)
                ->first();

            if ($mapping) {
                return [
                    'parent_id' => $mapping->parent_custom_entity_id,
                    'child_id' => $mapping->child_custom_entity_id,
                ];
            }

            // Получить или создать справочник в главном аккаунте
            $parentEntity = $this->customEntityService->getOrCreateCustomEntity(
                $parentAccountId,
                $customEntityName
            );

            // Получить или создать справочник в дочернем аккаунте
            $childEntity = $this->customEntityService->getOrCreateCustomEntity(
                $childAccountId,
                $customEntityName
            );

            // Создать маппинг
            CustomEntityMapping::create([
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'parent_custom_entity_id' => $parentEntity['id'],
                'child_custom_entity_id' => $childEntity['id'],
                'custom_entity_name' => $customEntityName,
                'auto_created' => true,
            ]);

            Log::info('Custom entity synced', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'custom_entity_name' => $customEntityName,
                'parent_entity_id' => $parentEntity['id'],
                'child_entity_id' => $childEntity['id']
            ]);

            return [
                'parent_id' => $parentEntity['id'],
                'child_id' => $childEntity['id'],
            ];

        } catch (\Exception $e) {
            Log::error('Failed to sync custom entity', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'custom_entity_name' => $customEntityName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Синхронизировать элемент справочника
     *
     * @param string $parentAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param string $parentCustomEntityId UUID справочника в главном
     * @param string $parentElementId UUID элемента в главном
     * @return array Элемент в дочернем аккаунте
     */
    public function syncCustomEntityElement(
        string $parentAccountId,
        string $childAccountId,
        string $parentCustomEntityId,
        string $parentElementId
    ): array {
        try {
            // Найти маппинг справочника
            $entityMapping = CustomEntityMapping::where('parent_account_id', $parentAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_custom_entity_id', $parentCustomEntityId)
                ->first();

            if (!$entityMapping) {
                throw new \Exception("Custom entity mapping not found for {$parentCustomEntityId}");
            }

            // Проверить маппинг элемента
            $elementMapping = CustomEntityElementMapping::where('parent_account_id', $parentAccountId)
                ->where('child_account_id', $childAccountId)
                ->where('parent_custom_entity_id', $parentCustomEntityId)
                ->where('parent_element_id', $parentElementId)
                ->first();

            if ($elementMapping) {
                // Элемент уже синхронизирован
                return [
                    'id' => $elementMapping->child_element_id,
                    'meta' => [
                        'href' => config('moysklad.api_url') . "/entity/customentity/{$entityMapping->child_custom_entity_id}/{$elementMapping->child_element_id}",
                        'type' => 'customentity',
                        'mediaType' => 'application/json'
                    ]
                ];
            }

            // Получить элемент из главного аккаунта
            $parentElements = $this->customEntityService->getCustomEntityElements(
                $parentAccountId,
                $parentCustomEntityId
            );

            $parentElement = null;
            foreach ($parentElements as $element) {
                if ($element['id'] === $parentElementId) {
                    $parentElement = $element;
                    break;
                }
            }

            if (!$parentElement) {
                throw new \Exception("Parent element not found: {$parentElementId}");
            }

            // Найти или создать элемент в дочернем аккаунте
            $childElement = $this->customEntityService->getOrCreateElement(
                $childAccountId,
                $entityMapping->child_custom_entity_id,
                $parentElement['name']
            );

            // Создать маппинг
            CustomEntityElementMapping::create([
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'parent_custom_entity_id' => $parentCustomEntityId,
                'child_custom_entity_id' => $entityMapping->child_custom_entity_id,
                'parent_element_id' => $parentElementId,
                'child_element_id' => $childElement['id'],
                'element_name' => $parentElement['name'],
                'auto_created' => true,
            ]);

            Log::info('Custom entity element synced', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'parent_custom_entity_id' => $parentCustomEntityId,
                'parent_element_id' => $parentElementId,
                'child_element_id' => $childElement['id']
            ]);

            return [
                'id' => $childElement['id'],
                'meta' => [
                    'href' => config('moysklad.api_url') . "/entity/customentity/{$entityMapping->child_custom_entity_id}/{$childElement['id']}",
                    'type' => 'customentity',
                    'mediaType' => 'application/json'
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Failed to sync custom entity element', [
                'parent_account_id' => $parentAccountId,
                'child_account_id' => $childAccountId,
                'parent_custom_entity_id' => $parentCustomEntityId,
                'parent_element_id' => $parentElementId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Синхронизировать значение доп.поля типа справочник
     *
     * @param string $parentAccountId UUID главного аккаунта
     * @param string $childAccountId UUID дочернего аккаунта
     * @param array $attributeValue Значение атрибута из главного аккаунта
     * @return array Синхронизированное значение для дочернего аккаунта
     */
    public function syncAttributeValue(
        string $parentAccountId,
        string $childAccountId,
        array $attributeValue
    ): array {
        if (!isset($attributeValue['meta'])) {
            return $attributeValue;
        }

        // Извлечь ID справочника и элемента из meta
        $href = $attributeValue['meta']['href'] ?? '';
        $parts = explode('/', $href);

        if (count($parts) < 2) {
            return $attributeValue;
        }

        $parentElementId = end($parts);
        $parentCustomEntityId = prev($parts);

        // Синхронизировать элемент
        $syncedElement = $this->syncCustomEntityElement(
            $parentAccountId,
            $childAccountId,
            $parentCustomEntityId,
            $parentElementId
        );

        return $syncedElement;
    }
}
