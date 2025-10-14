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

        // Найти атрибут в товаре
        $attribute = $this->findAttribute($product, $attributeId);

        // Если атрибут не найден
        if (!$attribute) {
            return $operator === 'is_null';
        }

        // Извлечь значение с учетом типа
        $attributeValue = $this->extractAttributeValue($attribute);

        // Если значение не найдено
        if ($attributeValue === null) {
            return $operator === 'is_null';
        }

        return $this->compareValues($attributeValue, $filterValue, $operator, $attribute['type'] ?? 'string');
    }

    /**
     * Найти атрибут в товаре
     *
     * @param array $product Товар
     * @param string $attributeId UUID атрибута
     * @return array|null Полные данные атрибута или null
     */
    protected function findAttribute(array $product, string $attributeId): ?array
    {
        $attributes = $product['attributes'] ?? [];

        foreach ($attributes as $attribute) {
            $attrHref = $attribute['meta']['href'] ?? '';
            $attrId = $this->extractIdFromHref($attrHref);

            if ($attrId === $attributeId) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Извлечь значение атрибута с учетом его типа
     *
     * @param array $attribute Данные атрибута
     * @return mixed Значение атрибута
     */
    protected function extractAttributeValue(array $attribute): mixed
    {
        $type = $attribute['type'] ?? 'string';
        $value = $attribute['value'] ?? null;

        return match($type) {
            'customentity' => $this->extractCustomEntityId($value),
            'file' => $this->extractFileId($value),
            'time' => $this->extractTimeValue($value),
            'boolean' => (bool)$value,
            'long' => (int)$value,
            'double' => (float)$value,
            default => $value // string, text, link
        };
    }

    /**
     * Извлечь ID элемента справочника из customentity
     *
     * @param mixed $value Значение customentity (может быть строкой или массивом с meta)
     * @return string|null
     */
    protected function extractCustomEntityId(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $href = $value['meta']['href'] ?? null;
            if ($href) {
                return $this->extractIdFromHref($href);
            }
        }

        return null;
    }

    /**
     * Извлечь ID файла
     *
     * @param mixed $value Значение file
     * @return string|null
     */
    protected function extractFileId(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $value['filename'] ?? null;
        }

        return null;
    }

    /**
     * Извлечь значение времени
     *
     * @param mixed $value Значение time (timestamp в миллисекундах)
     * @return int|null Timestamp в секундах
     */
    protected function extractTimeValue(mixed $value): ?int
    {
        if (is_numeric($value)) {
            // МойСклад возвращает timestamp в миллисекундах
            return (int)($value / 1000);
        }

        if (is_string($value)) {
            // Попробовать распарсить дату
            $timestamp = strtotime($value);
            return $timestamp !== false ? $timestamp : null;
        }

        return null;
    }

    /**
     * Сравнить значения с учетом оператора и типа атрибута
     *
     * @param mixed $actualValue Фактическое значение
     * @param mixed $filterValue Значение для сравнения
     * @param string $operator Оператор
     * @param string $attributeType Тип атрибута (string, long, double, boolean, time, customentity, etc.)
     * @return bool
     */
    protected function compareValues(mixed $actualValue, mixed $filterValue, string $operator, string $attributeType = 'string'): bool
    {
        // Для флагов (boolean) - специальная обработка
        if ($attributeType === 'boolean') {
            return $this->compareBooleanValues($actualValue, $filterValue, $operator);
        }

        // Для чисел (long, double) - специальная обработка
        if (in_array($attributeType, ['long', 'double'])) {
            return $this->compareNumericValues($actualValue, $filterValue, $operator);
        }

        // Для времени (time) - специальная обработка
        if ($attributeType === 'time') {
            return $this->compareDateTimeValues($actualValue, $filterValue, $operator);
        }

        // Для справочника (customentity) - сравнение по ID
        if ($attributeType === 'customentity') {
            return $this->compareCustomEntityValues($actualValue, $filterValue, $operator);
        }

        // Для строк и остальных типов
        return $this->compareStringValues($actualValue, $filterValue, $operator);
    }

    /**
     * Сравнить булевы значения
     */
    protected function compareBooleanValues(mixed $actualValue, mixed $filterValue, string $operator): bool
    {
        $actual = (bool)$actualValue;
        $filter = (bool)$filterValue;

        return match($operator) {
            'equals' => $actual === $filter,
            'not_equals' => $actual !== $filter,
            'is_null' => $actualValue === null,
            'is_not_null' => $actualValue !== null,
            default => false
        };
    }

    /**
     * Сравнить числовые значения
     */
    protected function compareNumericValues(mixed $actualValue, mixed $filterValue, string $operator): bool
    {
        if (!is_numeric($actualValue) || !is_numeric($filterValue)) {
            return false;
        }

        return match($operator) {
            'equals' => $actualValue == $filterValue,
            'not_equals' => $actualValue != $filterValue,
            'greater_than' => $actualValue > $filterValue,
            'less_than' => $actualValue < $filterValue,
            'greater_or_equal' => $actualValue >= $filterValue,
            'less_or_equal' => $actualValue <= $filterValue,
            'is_null' => $actualValue === null,
            'is_not_null' => $actualValue !== null,
            'in' => is_array($filterValue) && in_array($actualValue, $filterValue),
            'not_in' => is_array($filterValue) && !in_array($actualValue, $filterValue),
            default => false
        };
    }

    /**
     * Сравнить значения даты/времени
     */
    protected function compareDateTimeValues(mixed $actualValue, mixed $filterValue, string $operator): bool
    {
        // Конвертировать в timestamp если строка
        if (is_string($filterValue)) {
            $filterValue = strtotime($filterValue);
        }

        if (!is_numeric($actualValue) || !is_numeric($filterValue)) {
            return false;
        }

        return match($operator) {
            'equals' => abs($actualValue - $filterValue) < 60, // В пределах минуты
            'not_equals' => abs($actualValue - $filterValue) >= 60,
            'greater_than' => $actualValue > $filterValue,
            'less_than' => $actualValue < $filterValue,
            'greater_or_equal' => $actualValue >= $filterValue,
            'less_or_equal' => $actualValue <= $filterValue,
            'is_null' => $actualValue === null,
            'is_not_null' => $actualValue !== null,
            default => false
        };
    }

    /**
     * Сравнить значения справочника (customentity)
     */
    protected function compareCustomEntityValues(mixed $actualValue, mixed $filterValue, string $operator): bool
    {
        // Значения уже должны быть ID элементов справочника
        return match($operator) {
            'equals' => $actualValue === $filterValue,
            'not_equals' => $actualValue !== $filterValue,
            'in' => is_array($filterValue) && in_array($actualValue, $filterValue),
            'not_in' => is_array($filterValue) && !in_array($actualValue, $filterValue),
            'is_null' => $actualValue === null,
            'is_not_null' => $actualValue !== null,
            default => false
        };
    }

    /**
     * Сравнить строковые значения
     */
    protected function compareStringValues(mixed $actualValue, mixed $filterValue, string $operator): bool
    {
        return match($operator) {
            'equals' => $actualValue == $filterValue,
            'not_equals' => $actualValue != $filterValue,
            'contains' => is_string($actualValue) && str_contains($actualValue, (string)$filterValue),
            'not_contains' => is_string($actualValue) && !str_contains($actualValue, (string)$filterValue),
            'starts_with' => is_string($actualValue) && str_starts_with($actualValue, (string)$filterValue),
            'ends_with' => is_string($actualValue) && str_ends_with($actualValue, (string)$filterValue),
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
