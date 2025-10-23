<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Сервис для фильтрации товаров по различным условиям
 */
class ProductFilterService
{
    /**
     * Построить строку фильтра для МойСклад API
     *
     * Конвертирует фильтры в МойСклад query string (urlencoded).
     * Возвращает null если фильтр нельзя применить через API (OR логика, not_in, и т.д.).
     *
     * @param array|null $filters Массив фильтров (из sync_settings.product_filters)
     * @param string $mainAccountId UUID главного аккаунта (для построения href)
     * @param array|null $attributesMetadata Метаданные атрибутов с customEntityMeta
     * @return string|null Строка для параметра filter (urlencoded) или null
     */
    public function buildApiFilter(?array $filters, string $mainAccountId, ?array $attributesMetadata = null): ?string
    {
        // Если фильтры не заданы
        if (!$filters) {
            return null;
        }

        // Автоматическая конвертация из UI формата (groups ИЛИ conditions без enabled)
        if (isset($filters['groups']) || (isset($filters['conditions']) && !isset($filters['enabled']))) {
            $filters = $this->convertFromUiFormat($filters, $mainAccountId, $attributesMetadata);
        }

        // Если фильтры отключены
        if (!($filters['enabled'] ?? false)) {
            return null;
        }

        $conditions = $filters['conditions'] ?? [];
        if (empty($conditions)) {
            return null;
        }

        $logic = strtoupper($filters['logic'] ?? 'AND');

        // OR логика НЕ поддерживается МойСклад API
        if ($logic === 'OR') {
            return null;
        }

        // Собрать условия для API (AND только)
        $apiConditions = $this->buildApiConditions($conditions, $mainAccountId);

        // Если не удалось построить (unsupported операторы/типы)
        if ($apiConditions === null || empty($apiConditions)) {
            return null;
        }

        // Объединить через ; (HTTP client закодирует автоматически)
        $filterString = implode(';', $apiConditions);
        return $filterString;
    }

    /**
     * Построить массив API условий из фильтров
     *
     * @param array $conditions Массив условий
     * @param string $mainAccountId UUID главного аккаунта
     * @return array|null Массив строк условий или null если unsupported
     */
    protected function buildApiConditions(array $conditions, string $mainAccountId): ?array
    {
        $apiConditions = [];

        foreach ($conditions as $condition) {
            $type = $condition['type'] ?? null;

            if ($type === 'folder') {
                $folderConditions = $this->buildFolderApiConditions($condition, $mainAccountId);
                if ($folderConditions === null) {
                    return null; // Unsupported operator
                }
                $apiConditions = array_merge($apiConditions, $folderConditions);

            } elseif ($type === 'attribute') {
                $attributeCondition = $this->buildAttributeApiCondition($condition, $mainAccountId);
                if ($attributeCondition === null) {
                    return null; // Unsupported operator/type
                }
                $apiConditions[] = $attributeCondition;

            } elseif ($type === 'group') {
                $groupLogic = strtoupper($condition['logic'] ?? 'AND');

                // OR в группах НЕ поддерживается
                if ($groupLogic === 'OR') {
                    return null;
                }

                // Рекурсивно обработать группу (AND только)
                $groupConditions = $this->buildApiConditions($condition['conditions'] ?? [], $mainAccountId);
                if ($groupConditions === null) {
                    return null;
                }
                $apiConditions = array_merge($apiConditions, $groupConditions);

            } else {
                // Неизвестный тип
                return null;
            }
        }

        return $apiConditions;
    }

    /**
     * Построить API условия для productFolder
     *
     * @param array $condition Условие фильтра
     * @param string $mainAccountId UUID главного аккаунта
     * @return array|null Массив строк условий или null
     */
    protected function buildFolderApiConditions(array $condition, string $mainAccountId): ?array
    {
        $operator = $condition['operator'] ?? 'in';
        $filterValues = (array)($condition['value'] ?? []);

        if (empty($filterValues)) {
            return [];
        }

        // not_in НЕ поддерживается
        if ($operator === 'not_in') {
            return null;
        }

        // in оператор → несколько условий productFolder={id1};productFolder={id2}
        $conditions = [];
        foreach ($filterValues as $folderId) {
            $href = "https://api.moysklad.ru/api/remap/1.2/entity/productfolder/{$folderId}";
            $conditions[] = "productFolder={$href}";
        }

        return $conditions;
    }

    /**
     * Построить API условие для attribute (доп.поле)
     *
     * @param array $condition Условие фильтра
     * @param string $mainAccountId UUID главного аккаунта
     * @return string|null Строка условия или null
     */
    protected function buildAttributeApiCondition(array $condition, string $mainAccountId): ?string
    {
        $attributeId = $condition['attribute_id'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $filterValue = $condition['value'] ?? null;
        $attributeType = $condition['attribute_type'] ?? 'string'; // Тип доп.поля

        if (!$attributeId) {
            return null;
        }

        // Построить URL доп.поля
        $attributeUrl = "https://api.moysklad.ru/api/remap/1.2/entity/product/metadata/attributes/{$attributeId}";

        // Определить API оператор по типу
        return match($attributeType) {
            'string', 'text', 'link' => $this->buildStringApiCondition($attributeUrl, $operator, $filterValue),
            'long', 'double' => $this->buildNumericApiCondition($attributeUrl, $operator, $filterValue),
            'boolean' => $this->buildBooleanApiCondition($attributeUrl, $operator, $filterValue),
            'time' => $this->buildTimeApiCondition($attributeUrl, $operator, $filterValue),
            'customentity' => $this->buildCustomEntityApiCondition($attributeUrl, $operator, $filterValue),
            'file' => null, // File НЕ поддерживается
            default => null
        };
    }

    /**
     * Построить API условие для строковых атрибутов
     */
    protected function buildStringApiCondition(string $url, string $operator, mixed $value): ?string
    {
        // Значение без экранирования (HTTP client обработает encoding)
        $escapedValue = (string)$value;

        return match($operator) {
            'equals' => "{$url}={$escapedValue}",
            'not_equals' => "{$url}!={$escapedValue}",
            'contains' => "{$url}~{$escapedValue}",
            'not_contains' => "{$url}!~{$escapedValue}",
            'starts_with' => "{$url}~={$escapedValue}",
            'ends_with' => "{$url}=~{$escapedValue}",
            'is_null' => "{$url}=;",
            'is_not_null' => "{$url}!=;",
            'in' => null, // НЕ поддерживается для строк (слишком много вариантов)
            'not_in' => null,
            default => null
        };
    }

    /**
     * Построить API условие для числовых атрибутов
     */
    protected function buildNumericApiCondition(string $url, string $operator, mixed $value): ?string
    {
        if (!is_numeric($value) && $operator !== 'is_null' && $operator !== 'is_not_null') {
            return null;
        }

        return match($operator) {
            'equals' => "{$url}={$value}",
            'not_equals' => "{$url}!={$value}",
            'greater_than' => "{$url}>{$value}",
            'less_than' => "{$url}<{$value}",
            'greater_or_equal' => "{$url}>={$value}",
            'less_or_equal' => "{$url}<={$value}",
            'is_null' => "{$url}=;",
            'is_not_null' => "{$url}!=;",
            'in' => null, // Можно реализовать: {url}=1;{url}=2, но сложно
            'not_in' => null,
            default => null
        };
    }

    /**
     * Построить API условие для boolean атрибутов
     */
    protected function buildBooleanApiCondition(string $url, string $operator, mixed $value): ?string
    {
        if ($operator === 'is_null') {
            return "{$url}=;";
        }

        if ($operator === 'is_not_null') {
            return "{$url}!=;";
        }

        // Конвертировать в true/false строку
        $boolValue = $value ? 'true' : 'false';

        return match($operator) {
            'equals' => "{$url}={$boolValue}",
            'not_equals' => "{$url}!={$boolValue}",
            default => null
        };
    }

    /**
     * Построить API условие для time (дата/время) атрибутов
     */
    protected function buildTimeApiCondition(string $url, string $operator, mixed $value): ?string
    {
        // Конвертировать в формат МойСклад: YYYY-MM-DD HH:MM:SS
        if (is_numeric($value)) {
            $dateValue = date('Y-m-d H:i:s', (int)$value);
        } elseif (is_string($value)) {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return null;
            }
            $dateValue = date('Y-m-d H:i:s', $timestamp);
        } else {
            return null;
        }

        return match($operator) {
            'equals' => "{$url}={$dateValue}",
            'not_equals' => "{$url}!={$dateValue}",
            'greater_than' => "{$url}>{$dateValue}",
            'less_than' => "{$url}<{$dateValue}",
            'greater_or_equal' => "{$url}>={$dateValue}",
            'less_or_equal' => "{$url}<={$dateValue}",
            'is_null' => "{$url}=;",
            'is_not_null' => "{$url}!=;",
            default => null
        };
    }

    /**
     * Построить API условие для customentity (справочник) атрибутов
     *
     * После конвертации из UI формата value уже содержит полный href.
     * Но для обратной совместимости поддерживаем и UUID (возвращаем null, т.к. без metadata не построить href).
     */
    protected function buildCustomEntityApiCondition(string $url, string $operator, mixed $value): ?string
    {
        if ($operator === 'is_null') {
            return "{$url}=;";
        }

        if ($operator === 'is_not_null') {
            return "{$url}!=;";
        }

        // Для in оператора - value это массив
        if ($operator === 'in' && is_array($value)) {
            // После конвертации из UI формата каждый элемент это полный href
            $conditions = [];
            foreach ($value as $item) {
                if (!is_string($item)) {
                    continue;
                }

                // Если это href - использовать
                if (str_starts_with($item, 'https://')) {
                    $conditions[] = "{$url}={$item}";
                }
                // Если UUID - не можем построить href без metadata
            }

            // Если не удалось построить ни одного условия
            if (empty($conditions)) {
                return null;
            }

            // Вернуть несколько условий объединенных через ;
            return implode(';', $conditions);
        }

        // not_in НЕ поддерживается
        if ($operator === 'not_in') {
            return null;
        }

        // Для equals/not_equals - value это полный href элемента (после конвертации) или UUID (без конвертации)
        if (is_string($value)) {
            // Если это уже href - использовать
            if (str_starts_with($value, 'https://')) {
                $valueHref = $value;

                return match($operator) {
                    'equals' => "{$url}={$valueHref}",
                    'not_equals' => "{$url}!={$valueHref}",
                    default => null
                };
            } else {
                // UUID элемента - не можем построить href без metadata
                // Вернуть null → API фильтр не построится → будет client-side фильтрация
                return null;
            }
        }

        return null;
    }

    /**
     * Проверить проходит ли товар фильтры
     *
     * @param array $product Данные товара из МойСклад
     * @param array|null $filters Конфигурация фильтров
     * @param string|null $mainAccountId UUID главного аккаунта (для конвертации UI формата)
     * @param array|null $attributesMetadata Метаданные атрибутов с customEntityMeta
     * @return bool
     */
    public function passes(array $product, ?array $filters, ?string $mainAccountId = null, ?array $attributesMetadata = null): bool
    {
        // Если фильтры не заданы
        if (!$filters) {
            return true;
        }

        // Автоматическая конвертация из UI формата
        if (isset($filters['groups']) && $mainAccountId) {
            $filters = $this->convertFromUiFormat($filters, $mainAccountId, $attributesMetadata);
        }

        // Если фильтры отключены
        if (!($filters['enabled'] ?? false)) {
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
     *
     * Поддерживает сравнение:
     * - UUID с UUID
     * - UUID с href (извлекает UUID из href)
     * - href с href
     *
     * После конвертации из UI формата filterValue содержит либо:
     * - Полный href элемента (если metadata была передана)
     * - UUID элемента (если metadata не была передана, сохранен в _element_id)
     */
    protected function compareCustomEntityValues(mixed $actualValue, mixed $filterValue, string $operator): bool
    {
        // actualValue - это UUID элемента (извлеченный из product.attributes[].value)
        // filterValue - это либо href (после конвертации), либо UUID

        // Нормализовать filterValue (извлечь UUID если это href)
        $filterUuid = $this->extractUuidFromValue($filterValue);

        // Для in/not_in оператора - filterValue это массив
        if ($operator === 'in' || $operator === 'not_in') {
            if (!is_array($filterValue)) {
                return false;
            }

            // Нормализовать каждый элемент массива
            $filterUuids = array_map(fn($val) => $this->extractUuidFromValue($val), $filterValue);

            return match($operator) {
                'in' => in_array($actualValue, $filterUuids),
                'not_in' => !in_array($actualValue, $filterUuids),
                default => false
            };
        }

        return match($operator) {
            'equals' => $actualValue === $filterUuid,
            'not_equals' => $actualValue !== $filterUuid,
            'is_null' => $actualValue === null,
            'is_not_null' => $actualValue !== null,
            default => false
        };
    }

    /**
     * Извлечь UUID из значения (поддерживает UUID и href)
     *
     * @param mixed $value UUID или href
     * @return string|null UUID элемента
     */
    protected function extractUuidFromValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        // Если это href - извлечь UUID
        if (str_starts_with($value, 'https://')) {
            return $this->extractIdFromHref($value);
        }

        // Иначе это уже UUID
        return $value;
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

    /**
     * Конвертировать фильтры из UI формата в ProductFilterService формат
     *
     * Поддерживает два UI формата:
     * - Новый (упрощенный): { conditions: [...] } - одна группа с AND логикой
     * - Старый: { groups: [...] } - множественные группы с OR логикой (для обратной совместимости)
     *
     * @param array $uiFilters Фильтры в UI формате
     * @param string $mainAccountId UUID главного аккаунта
     * @param array|null $attributesMetadata Метаданные атрибутов с customEntityMeta
     * @return array Фильтры в ProductFilterService формате
     */
    public function convertFromUiFormat(array $uiFilters, string $mainAccountId, ?array $attributesMetadata = null): array
    {
        // Если уже в правильном формате (есть enabled)
        if (isset($uiFilters['enabled'])) {
            return $uiFilters;
        }

        // НОВЫЙ ФОРМАТ: { conditions: [...] } - упрощенный (одна группа AND)
        if (isset($uiFilters['conditions']) && is_array($uiFilters['conditions'])) {
            $convertedConditions = [];

            foreach ($uiFilters['conditions'] as $condition) {
                $converted = $this->convertUiCondition($condition, $mainAccountId, $attributesMetadata);
                if ($converted) {
                    $convertedConditions[] = $converted;
                }
            }

            return [
                'enabled' => !empty($convertedConditions),
                'mode' => 'whitelist',
                'logic' => 'AND',  // Все условия объединяются по И
                'conditions' => $convertedConditions
            ];
        }

        // СТАРЫЙ ФОРМАТ: { groups: [...] } - для обратной совместимости
        // ВАЖНО: Поскольку OR логика отключена, объединяем все условия из всех групп в один плоский массив с AND логикой
        if (isset($uiFilters['groups']) && is_array($uiFilters['groups'])) {
            if (empty($uiFilters['groups'])) {
                return [
                    'enabled' => false,
                    'mode' => 'whitelist',
                    'logic' => 'AND',
                    'conditions' => []
                ];
            }

            $groups = $uiFilters['groups'];
            $allConditions = [];  // Плоский массив всех условий из всех групп

            // Собрать все условия из всех групп в один массив (AND логика)
            foreach ($groups as $group) {
                foreach ($group['conditions'] ?? [] as $condition) {
                    $converted = $this->convertUiCondition($condition, $mainAccountId, $attributesMetadata);
                    if ($converted) {
                        $allConditions[] = $converted;
                    }
                }
            }

            return [
                'enabled' => !empty($allConditions),
                'mode' => 'whitelist',
                'logic' => 'AND',  // AND логика (OR отключена)
                'conditions' => $allConditions
            ];
        }

        // Пустой фильтр (ни conditions, ни groups)
        return [
            'enabled' => false,
            'mode' => 'whitelist',
            'logic' => 'AND',
            'conditions' => []
        ];
    }

    /**
     * Конвертировать одно условие из UI формата
     *
     * @param array $condition Условие из UI
     * @param string $mainAccountId UUID главного аккаунта
     * @param array|null $attributesMetadata Метаданные атрибутов
     * @return array|null Сконвертированное условие или null
     */
    protected function convertUiCondition(array $condition, string $mainAccountId, ?array $attributesMetadata): ?array
    {
        $type = $condition['type'] ?? null;

        // Folder condition
        if ($type === 'folder') {
            return [
                'type' => 'folder',
                'operator' => 'in',
                'value' => $condition['folder_ids'] ?? []
            ];
        }

        // Attribute flag (boolean) condition
        if ($type === 'attribute_flag') {
            return [
                'type' => 'attribute',
                'attribute_id' => $condition['attribute_id'] ?? null,
                'attribute_type' => 'boolean',
                'operator' => 'equals',
                'value' => $condition['value'] ?? false
            ];
        }

        // Attribute customentity condition
        if ($type === 'attribute_customentity') {
            $attributeId = $condition['attribute_id'] ?? null;
            $elementId = $condition['value'] ?? null;  // UUID элемента

            if (!$attributeId || !$elementId) {
                return null;
            }

            // Построить полный href элемента справочника
            $elementHref = $this->buildCustomEntityElementHref(
                $attributeId,
                $elementId,
                $attributesMetadata
            );

            return [
                'type' => 'attribute',
                'attribute_id' => $attributeId,
                'attribute_type' => 'customentity',
                'operator' => 'equals',
                'value' => $elementHref,  // Полный href (или UUID если не удалось построить)
                '_element_id' => $elementId  // Сохранить UUID для client-side
            ];
        }

        return null;
    }

    /**
     * Построить полный href элемента справочника
     *
     * @param string $attributeId UUID атрибута
     * @param string $elementId UUID элемента справочника
     * @param array|null $attributesMetadata Метаданные атрибутов
     * @return string Полный href элемента или UUID если не удалось построить
     */
    protected function buildCustomEntityElementHref(
        string $attributeId,
        string $elementId,
        ?array $attributesMetadata
    ): string {
        // Если метаданные не переданы - вернуть UUID (для client-side)
        if (!$attributesMetadata) {
            return $elementId;
        }

        // Найти атрибут в метаданных
        $attributeMeta = null;
        foreach ($attributesMetadata as $attr) {
            if ($attr['id'] === $attributeId) {
                $attributeMeta = $attr;
                break;
            }
        }

        // Если атрибут не найден или нет customEntityMeta - вернуть UUID
        if (!$attributeMeta || !isset($attributeMeta['customEntityMeta']['href'])) {
            return $elementId;
        }

        // Построить полный href: customEntityMeta.href + / + elementId
        $customEntityHref = $attributeMeta['customEntityMeta']['href'];

        // Убрать trailing slash если есть
        $customEntityHref = rtrim($customEntityHref, '/');

        return "{$customEntityHref}/{$elementId}";
    }

    /**
     * Построить строку фильтра для /entity/assortment endpoint
     *
     * Конвертирует фильтры в МойСклад query string для assortment endpoint.
     * Автоматически добавляет фильтр по типу сущности (type=service|product|bundle).
     *
     * @param array|null $filters Массив фильтров (из sync_settings.product_filters)
     * @param string $entityType Тип сущности (product, service, bundle, variant)
     * @param string $mainAccountId UUID главного аккаунта (для построения href)
     * @param array|null $attributesMetadata Метаданные атрибутов с customEntityMeta
     * @return string|null Строка для параметра filter (urlencoded) или null
     */
    public function buildAssortmentApiFilter(
        ?array $filters,
        string $entityType,
        string $mainAccountId,
        ?array $attributesMetadata = null
    ): ?string {
        // Получить тип для assortment параметра type=
        $assortmentType = EntityConfig::getAssortmentType($entityType);

        // Построить базовый фильтр для обычного endpoint
        $baseFilter = $this->buildApiFilter($filters, $mainAccountId, $attributesMetadata);

        // Если базовый фильтр не построился - вернуть только type
        if (!$baseFilter) {
            return "type={$assortmentType}";
        }

        // Добавить фильтр по типу к базовому
        return "type={$assortmentType};{$baseFilter}";
    }
}
