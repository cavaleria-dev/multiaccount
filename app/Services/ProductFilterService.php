<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Сервис для фильтрации товаров по различным условиям
 */
class ProductFilterService
{
    /**
     * Проверить проходит ли товар фильтры
     *
     * @param array $product Данные товара из МойСклад
     * @param array|null $filters Конфигурация фильтров
     * @return bool
     */
    public function passes(array $product, ?array $filters): bool
    {
        // Если фильтры не заданы или отключены
        if (!$filters || !($filters['enabled'] ?? false)) {
            return true;
        }

        $conditions = $filters['conditions'] ?? [];
        if (empty($conditions)) {
            return true;
        }

        $logic = strtoupper($filters['logic'] ?? 'AND');
        $mode = $filters['mode'] ?? 'whitelist';

        // Проверить все условия с учетом логики
        $result = $this->evaluateConditions($product, $conditions, $logic);

        // Применить режим (whitelist/blacklist)
        return $mode === 'whitelist' ? $result : !$result;
    }

    /**
     * Вычислить результат набора условий
     *
     * @param array $product Товар
     * @param array $conditions Условия
     * @param string $logic AND или OR
     * @return bool
     */
    protected function evaluateConditions(array $product, array $conditions, string $logic): bool
    {
        if (empty($conditions)) {
            return true;
        }

        $results = [];

        foreach ($conditions as $condition) {
            $conditionResult = $this->evaluateCondition($product, $condition);
            $results[] = $conditionResult;

            // Ранний выход для оптимизации
            if ($logic === 'AND' && !$conditionResult) {
                return false; // Если AND и нашли false - сразу false
            }
            if ($logic === 'OR' && $conditionResult) {
                return true; // Если OR и нашли true - сразу true
            }
        }

        // Финальная оценка
        return $logic === 'AND'
            ? !in_array(false, $results, true)
            : in_array(true, $results, true);
    }

    /**
     * Вычислить результат одного условия
     *
     * @param array $product Товар
     * @param array $condition Условие
     * @return bool
     */
    protected function evaluateCondition(array $product, array $condition): bool
    {
        $type = $condition['type'] ?? null;

        return match($type) {
            'folder' => $this->checkFolder($product, $condition),
            'attribute' => $this->checkAttribute($product, $condition),
            'group' => $this->evaluateConditions(
                $product,
                $condition['conditions'] ?? [],
                strtoupper($condition['logic'] ?? 'AND')
            ),
            default => true
        };
    }

    /**
     * Проверить условие по группе товара (productFolder)
     *
     * @param array $product Товар
     * @param array $condition Условие
     * @return bool
     */
    protected function checkFolder(array $product, array $condition): bool
    {
        $operator = $condition['operator'] ?? 'in';
        $filterValues = (array)($condition['value'] ?? []);

        if (empty($filterValues)) {
            return true;
        }

        // Извлечь ID группы товара
        $folderHref = $product['productFolder']['meta']['href'] ?? null;
        if (!$folderHref) {
            // Товар не в группе
            return false;
        }

        $folderId = $this->extractIdFromHref($folderHref);

        return match($operator) {
            'in' => in_array($folderId, $filterValues),
            'not_in' => !in_array($folderId, $filterValues),
            default => false
        };
    }

    /**
     * Проверить условие по доп.полю товара
     *
     * @param array $product Товар
     * @param array $condition Условие
     * @return bool
     */
    protected function checkAttribute(array $product, array $condition): bool
    {
        $attributeId = $condition['attribute_id'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $filterValue = $condition['value'] ?? null;

        if (!$attributeId) {
            return true;
        }

        // Найти значение атрибута в товаре
        $attributeValue = $this->findAttributeValue($product, $attributeId);

        // Если атрибут не найден
        if ($attributeValue === null) {
            return $operator === 'is_null';
        }

        return $this->compareValues($attributeValue, $filterValue, $operator);
    }

    /**
     * Найти значение атрибута в товаре
     *
     * @param array $product Товар
     * @param string $attributeId UUID атрибута
     * @return mixed|null
     */
    protected function findAttributeValue(array $product, string $attributeId): mixed
    {
        $attributes = $product['attributes'] ?? [];

        foreach ($attributes as $attribute) {
            $attrHref = $attribute['meta']['href'] ?? '';
            $attrId = $this->extractIdFromHref($attrHref);

            if ($attrId === $attributeId) {
                return $attribute['value'] ?? null;
            }
        }

        return null;
    }

    /**
     * Сравнить значения с учетом оператора
     *
     * @param mixed $actualValue Фактическое значение
     * @param mixed $filterValue Значение для сравнения
     * @param string $operator Оператор
     * @return bool
     */
    protected function compareValues(mixed $actualValue, mixed $filterValue, string $operator): bool
    {
        return match($operator) {
            'equals' => $actualValue == $filterValue,
            'not_equals' => $actualValue != $filterValue,
            'contains' => is_string($actualValue) && str_contains($actualValue, (string)$filterValue),
            'not_contains' => is_string($actualValue) && !str_contains($actualValue, (string)$filterValue),
            'starts_with' => is_string($actualValue) && str_starts_with($actualValue, (string)$filterValue),
            'ends_with' => is_string($actualValue) && str_ends_with($actualValue, (string)$filterValue),
            'greater_than' => is_numeric($actualValue) && $actualValue > $filterValue,
            'less_than' => is_numeric($actualValue) && $actualValue < $filterValue,
            'greater_or_equal' => is_numeric($actualValue) && $actualValue >= $filterValue,
            'less_or_equal' => is_numeric($actualValue) && $actualValue <= $filterValue,
            'is_null' => $actualValue === null,
            'is_not_null' => $actualValue !== null,
            'in' => is_array($filterValue) && in_array($actualValue, $filterValue),
            'not_in' => is_array($filterValue) && !in_array($actualValue, $filterValue),
            default => false
        };
    }

    /**
     * Извлечь ID из href
     *
     * @param string $href
     * @return string|null
     */
    protected function extractIdFromHref(string $href): ?string
    {
        if (empty($href)) {
            return null;
        }

        $parts = explode('/', $href);
        return end($parts) ?: null;
    }

    /**
     * Валидировать структуру фильтров
     *
     * @param array $filters Конфигурация фильтров
     * @return array Массив ошибок (пустой если валидно)
     */
    public function validate(array $filters): array
    {
        $errors = [];

        if (!isset($filters['enabled']) || !is_bool($filters['enabled'])) {
            $errors[] = 'Field "enabled" must be boolean';
        }

        if (isset($filters['mode']) && !in_array($filters['mode'], ['whitelist', 'blacklist'])) {
            $errors[] = 'Field "mode" must be "whitelist" or "blacklist"';
        }

        if (isset($filters['logic']) && !in_array(strtoupper($filters['logic']), ['AND', 'OR'])) {
            $errors[] = 'Field "logic" must be "AND" or "OR"';
        }

        if (isset($filters['conditions'])) {
            if (!is_array($filters['conditions'])) {
                $errors[] = 'Field "conditions" must be array';
            } else {
                $errors = array_merge($errors, $this->validateConditions($filters['conditions']));
            }
        }

        return $errors;
    }

    /**
     * Валидировать массив условий
     *
     * @param array $conditions
     * @return array
     */
    protected function validateConditions(array $conditions): array
    {
        $errors = [];

        foreach ($conditions as $index => $condition) {
            if (!isset($condition['type'])) {
                $errors[] = "Condition #{$index}: missing 'type' field";
                continue;
            }

            $type = $condition['type'];

            if ($type === 'group') {
                if (!isset($condition['conditions']) || !is_array($condition['conditions'])) {
                    $errors[] = "Condition #{$index}: group must have 'conditions' array";
                }
                if (isset($condition['logic']) && !in_array(strtoupper($condition['logic']), ['AND', 'OR'])) {
                    $errors[] = "Condition #{$index}: logic must be 'AND' or 'OR'";
                }
                if (isset($condition['conditions'])) {
                    $errors = array_merge($errors, $this->validateConditions($condition['conditions']));
                }
            } elseif ($type === 'folder') {
                if (!isset($condition['value']) || !is_array($condition['value'])) {
                    $errors[] = "Condition #{$index}: folder condition must have 'value' array";
                }
            } elseif ($type === 'attribute') {
                if (!isset($condition['attribute_id'])) {
                    $errors[] = "Condition #{$index}: attribute condition must have 'attribute_id'";
                }
                if (!isset($condition['operator'])) {
                    $errors[] = "Condition #{$index}: attribute condition must have 'operator'";
                }
            } else {
                $errors[] = "Condition #{$index}: unknown type '{$type}'";
            }
        }

        return $errors;
    }
}
