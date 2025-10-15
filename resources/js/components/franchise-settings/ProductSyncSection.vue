<template>
  <!-- Секция 1: Синхронизация товаров + Расширенные настройки -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <!-- Синхронизация товаров -->
    <div class="bg-white shadow rounded-lg p-5">
      <h3 class="text-base font-medium text-gray-900 mb-3">Синхронизация товаров</h3>
      <div class="space-y-2">
        <Toggle
          v-model="localSettings.sync_products"
          label="Товары"
          size="small"
        />
        <Toggle
          v-model="localSettings.sync_variants"
          label="Модификации"
          size="small"
        />
        <Toggle
          v-model="localSettings.sync_bundles"
          label="Комплекты"
          size="small"
        />
        <Toggle
          v-model="localSettings.sync_services"
          label="Услуги"
          size="small"
        />
        <Toggle
          v-model="localSettings.sync_images"
          label="Изображения"
          size="small"
        />
        <Toggle
          v-model="localSettings.sync_images_all"
          label="Все изображения"
          size="small"
        />
        <Toggle
          v-model="localSettings.sync_prices"
          label="Цены"
          size="small"
        />
      </div>
    </div>

    <!-- Расширенные настройки товаров -->
    <div class="bg-white shadow rounded-lg p-5">
      <h3 class="text-base font-medium text-gray-900 mb-3">Расширенные настройки товаров</h3>
      <div class="space-y-4">
        <!-- Product match field -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Поле для сопоставления товаров</label>
          <select
            v-model="localSettings.product_match_field"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
          >
            <option value="code">Код (code)</option>
            <option value="article">Артикул (article)</option>
            <option value="externalCode">Внешний код (externalCode)</option>
            <option value="barcode">Штрихкод (первый barcode)</option>
          </select>
          <p class="mt-1 text-xs text-gray-500">По какому полю искать существующие товары в дочернем аккаунте</p>
        </div>

        <!-- Create product folders -->
        <Toggle
          v-model="localSettings.create_product_folders"
          label="Создавать группы товаров"
          description="Создавать соответствующие группы товаров в дочернем аккаунте (структура каталога)"
          size="small"
        />

        <!-- Sync all products button -->
        <div class="border-t border-gray-200 pt-3">
          <button
            type="button"
            @click="$emit('sync-all-products')"
            :disabled="syncing"
            class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 transition-all"
          >
            <svg v-if="syncing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span v-if="syncing">Синхронизация...</span>
            <span v-else>Синхронизировать все товары</span>
          </button>
          <p v-if="syncProgress" class="mt-2 text-sm text-green-600">{{ syncProgress }}</p>
          <p class="mt-2 text-xs text-gray-500">Запустит синхронизацию всех товаров согласно настройкам и фильтрам</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import Toggle from '../Toggle.vue'

const props = defineProps({
  settings: {
    type: Object,
    required: true
  },
  syncing: {
    type: Boolean,
    default: false
  },
  syncProgress: {
    type: String,
    default: null
  }
})

const emit = defineEmits(['update:settings', 'sync-all-products'])

// Local computed settings with two-way binding
const localSettings = computed({
  get: () => props.settings,
  set: (value) => emit('update:settings', value)
})
</script>
