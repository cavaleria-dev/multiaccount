<template>
  <div class="bg-white shadow rounded-lg p-6">
    <div class="flex items-start mb-4">
      <div class="flex items-center h-5">
        <input
          id="product_filters_enabled"
          type="checkbox"
          :checked="productFiltersEnabled"
          @change="updateProductFiltersEnabled($event.target.checked)"
          class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
        />
      </div>
      <div class="ml-3">
        <label for="product_filters_enabled" class="text-sm font-medium text-gray-700">Включить фильтрацию товаров</label>
        <p class="text-sm text-gray-500">Использовать фильтры для выборочной синхронизации товаров</p>
      </div>
    </div>

    <div v-if="productFiltersEnabled">
      <ProductFilterBuilder
        :model-value="productFilters"
        @update:model-value="updateProductFilters"
        :account-id="accountId"
        :attributes="attributes"
        :folders="folders"
        :loading-folders="loadingFolders"
      />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import ProductFilterBuilder from '../ProductFilterBuilder.vue'

const props = defineProps({
  settings: {
    type: Object,
    required: true
  },
  accountId: {
    type: String,
    required: true
  },
  attributes: {
    type: Array,
    required: true
  },
  folders: {
    type: Array,
    required: true
  },
  loadingFolders: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:settings'])

const productFiltersEnabled = computed(() => props.settings.product_filters_enabled)
const productFilters = computed(() => props.settings.product_filters)

const updateProductFiltersEnabled = (value) => {
  emit('update:settings', {
    ...props.settings,
    product_filters_enabled: value
  })
}

const updateProductFilters = (value) => {
  emit('update:settings', {
    ...props.settings,
    product_filters: value
  })
}
</script>
