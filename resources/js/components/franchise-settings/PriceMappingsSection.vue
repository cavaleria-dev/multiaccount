<template>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <!-- Сопоставление типов цен (left column) -->
    <div class="bg-white shadow rounded-lg p-5">
      <h3 class="text-base font-medium text-gray-900 mb-3">Сопоставление типов цен</h3>
      <p class="text-sm text-gray-500 mb-2">
        Задайте соответствие между типами цен главного и дочернего аккаунтов. Пусто = синхронизировать все типы цен.
      </p>
      <div class="bg-blue-50 border border-blue-200 rounded-md p-3 mb-4">
        <p class="text-xs text-blue-800">
          <strong>💰 Закупочная цена</strong> - специальный тип для поля buyPrice товаров, услуг и модификаций.
          Можно сопоставлять с другими типами цен или оставить как buyPrice.
        </p>
      </div>

      <div v-if="loadingPriceTypes" class="text-center py-4">
        <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600"></div>
        <p class="text-sm text-gray-500 mt-2">Загрузка типов цен...</p>
      </div>

      <div v-else class="space-y-3">
        <div
          v-for="(mapping, index) in modelValue"
          :key="`price-mapping-${index}`"
          class="flex gap-2 items-start"
        >
          <div class="flex-1 min-w-0">
            <label class="block text-xs font-semibold text-gray-800 mb-1">Главный</label>
            <SearchableSelect
              :model-value="mapping.main_price_type_id"
              @update:model-value="updateMainPriceType(index, $event)"
              placeholder="Выберите тип цены"
              :options="mainPriceTypesWithEmoji"
              class="text-sm"
            />
          </div>
          <div class="flex-1 min-w-0">
            <label class="block text-xs font-semibold text-gray-800 mb-1">Дочерний</label>
            <div class="flex gap-1">
              <div class="flex-1 min-w-0">
                <SearchableSelect
                  :model-value="mapping.child_price_type_id"
                  @update:model-value="updateChildPriceType(index, $event)"
                  placeholder="Выберите тип цены"
                  :options="childPriceTypesWithEmoji"
                  class="text-sm"
                />
              </div>
              <button
                type="button"
                @click="$emit('create-price-type', index)"
                class="flex-shrink-0 p-2 text-indigo-600 hover:bg-indigo-50 rounded mt-0.5"
                title="Создать новый тип цены"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
              </button>
            </div>
          </div>
          <button
            type="button"
            @click="$emit('remove-price-mapping', index)"
            class="mt-5 text-gray-400 hover:text-red-600"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>

        <button
          type="button"
          @click="$emit('add-price-mapping')"
          class="w-full px-3 py-2 border border-dashed border-gray-300 rounded-md text-sm text-gray-600 hover:border-indigo-500 hover:text-indigo-600"
        >
          + Добавить сопоставление
        </button>
      </div>
    </div>

    <!-- Выбор дополнительных полей (right column) -->
    <div class="bg-white shadow rounded-lg p-5">
      <h3 class="text-base font-medium text-gray-900 mb-3">Выбор дополнительных полей для синхронизации</h3>
      <p class="text-sm text-gray-500 mb-3">
        Выберите дополнительные поля (атрибуты), которые нужно синхронизировать.
        <span class="font-medium text-gray-700">Если ничего не выбрано → доп.поля НЕ синхронизируются.</span>
        Поля типов контрагент, сотрудник, склад, организация, товар не отображаются (управляются отдельно).
      </p>

      <div v-if="loadingAttributes" class="text-center py-4">
        <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600"></div>
        <p class="text-sm text-gray-500 mt-2">Загрузка атрибутов...</p>
      </div>

      <div v-else-if="attributes.length === 0" class="text-center py-6">
        <svg class="mx-auto h-10 w-10 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <p class="text-sm text-gray-500 mt-2">Дополнительных полей не найдено</p>
      </div>

      <div v-else class="max-h-80 overflow-y-auto border border-gray-200 rounded-md p-3 space-y-2">
        <div
          v-for="attr in attributes"
          :key="attr.id"
          class="py-1"
        >
          <Toggle
            :model-value="selectedAttributes.includes(attr.id)"
            @update:model-value="toggleAttribute(attr.id)"
            :label="attr.name"
            :description="`Тип: ${attr.type}`"
            size="small"
            color="indigo"
          />
        </div>
      </div>

      <p v-if="selectedAttributes.length > 0" class="mt-2 text-sm text-gray-600">
        Выбрано: <span class="font-medium text-indigo-600">{{ selectedAttributes.length }}</span>
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import SearchableSelect from '../SearchableSelect.vue'
import Toggle from '../Toggle.vue'

const props = defineProps({
  priceTypes: {
    type: Object,
    required: true
  },
  modelValue: {
    type: Array,
    required: true
  },
  attributes: {
    type: Array,
    required: true
  },
  selectedAttributes: {
    type: Array,
    required: true
  },
  loadingPriceTypes: {
    type: Boolean,
    default: false
  },
  loadingAttributes: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits([
  'update:modelValue',
  'update:selectedAttributes',
  'add-price-mapping',
  'remove-price-mapping',
  'create-price-type'
])

// Add emoji prefix to buyPrice items for better visibility
const mainPriceTypesWithEmoji = computed(() => {
  return props.priceTypes.main.map(pt => ({
    ...pt,
    name: pt.id === 'buyPrice' ? `💰 ${pt.name}` : pt.name
  }))
})

const childPriceTypesWithEmoji = computed(() => {
  return props.priceTypes.child.map(pt => ({
    ...pt,
    name: pt.id === 'buyPrice' ? `💰 ${pt.name}` : pt.name
  }))
})

const updateMainPriceType = (index, value) => {
  const newMappings = [...props.modelValue]
  newMappings[index].main_price_type_id = value
  emit('update:modelValue', newMappings)
}

const updateChildPriceType = (index, value) => {
  const newMappings = [...props.modelValue]
  newMappings[index].child_price_type_id = value
  emit('update:modelValue', newMappings)
}

const toggleAttribute = (attrId) => {
  const newSelected = [...props.selectedAttributes]
  const index = newSelected.indexOf(attrId)
  if (index > -1) {
    newSelected.splice(index, 1)
  } else {
    newSelected.push(attrId)
  }
  emit('update:selectedAttributes', newSelected)
}
</script>
