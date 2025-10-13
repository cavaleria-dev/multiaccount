<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold text-gray-900">Настройки синхронизации</h1>
      <p class="mt-2 text-sm text-gray-700">
        Настройте параметры синхронизации данных между главным и дочерними аккаунтами
      </p>
    </div>

    <form @submit.prevent="saveSettings" class="space-y-6">
      <!-- Что синхронизировать -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Объекты для синхронизации</h3>
        <div class="space-y-4">
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync-catalog"
                v-model="settings.syncCatalog"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync-catalog" class="font-medium text-gray-700">Каталог товаров</label>
              <p class="text-gray-500">Товары, услуги, комплекты</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync-prices"
                v-model="settings.syncPrices"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync-prices" class="font-medium text-gray-700">Цены</label>
              <p class="text-gray-500">Розничные и оптовые цены</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync-stock"
                v-model="settings.syncStock"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync-stock" class="font-medium text-gray-700">Остатки</label>
              <p class="text-gray-500">Остатки на складах</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync-orders"
                v-model="settings.syncOrders"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync-orders" class="font-medium text-gray-700">Заказы покупателей</label>
              <p class="text-gray-500">Синхронизация заказов с дочерних аккаунтов</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync-images"
                v-model="settings.syncImagesAll"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync-images" class="font-medium text-gray-700">Все изображения</label>
              <p class="text-gray-500">Синхронизировать все изображения товаров (медленнее)</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Сопоставление товаров -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Сопоставление товаров</h3>
        <div>
          <label for="match-field" class="block text-sm font-medium text-gray-700">
            Поле для сопоставления
          </label>
          <select
            id="match-field"
            v-model="settings.productMatchField"
            class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm"
          >
            <option value="article">Артикул</option>
            <option value="code">Код</option>
            <option value="name">Наименование</option>
          </select>
          <p class="mt-2 text-sm text-gray-500">
            Выберите поле, по которому будут сопоставляться товары между аккаунтами
          </p>
        </div>
      </div>

      <!-- Частота синхронизации -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Частота синхронизации</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label for="sync-interval" class="block text-sm font-medium text-gray-700">
              Интервал синхронизации
            </label>
            <select
              id="sync-interval"
              v-model="settings.syncInterval"
              class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm"
            >
              <option value="realtime">В реальном времени (вебхуки)</option>
              <option value="5">Каждые 5 минут</option>
              <option value="15">Каждые 15 минут</option>
              <option value="30">Каждые 30 минут</option>
              <option value="60">Каждый час</option>
            </select>
          </div>

          <div>
            <label for="batch-size" class="block text-sm font-medium text-gray-700">
              Размер пакета
            </label>
            <input
              type="number"
              id="batch-size"
              v-model.number="settings.batchSize"
              min="10"
              max="1000"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            />
            <p class="mt-1 text-xs text-gray-500">Количество объектов в одном запросе (10-1000)</p>
          </div>
        </div>
      </div>

      <!-- Кнопки -->
      <div class="flex justify-end space-x-3">
        <button
          type="button"
          @click="resetSettings"
          class="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          Сбросить
        </button>
        <button
          type="submit"
          class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          Сохранить настройки
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const settings = ref({
  syncCatalog: true,
  syncPrices: true,
  syncStock: true,
  syncOrders: true,
  syncImagesAll: false,
  productMatchField: 'article',
  syncInterval: 'realtime',
  batchSize: 100
})

// Загрузка настроек
onMounted(async () => {
  // TODO: Загрузка настроек с API
  console.log('Loading settings...')
})

function saveSettings() {
  // TODO: Сохранение через API
  console.log('Saving settings:', settings.value)
  alert('Настройки сохранены!')
}

function resetSettings() {
  if (confirm('Сбросить все настройки?')) {
    settings.value = {
      syncCatalog: true,
      syncPrices: true,
      syncStock: true,
      syncOrders: true,
      syncImagesAll: false,
      productMatchField: 'article',
      syncInterval: 'realtime',
      batchSize: 100
    }
  }
}
</script>
