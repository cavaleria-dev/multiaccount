<template>
  <div class="bg-white shadow rounded-lg p-6">
    <div class="mb-4">
      <Toggle
        :model-value="productFiltersEnabled"
        @update:model-value="updateProductFiltersEnabled"
        label="Включить фильтрацию товаров"
        description="Использовать фильтры для выборочной синхронизации товаров"
        size="small"
        color="purple"
      />
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
import Toggle from '../Toggle.vue'

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
