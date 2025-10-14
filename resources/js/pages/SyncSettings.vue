<template>
  <div class="space-y-6">
    <!-- Заголовок -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-gray-900">Настройки синхронизации</h1>
        <p class="mt-2 text-sm text-gray-700" v-if="accountName">
          Аккаунт: <span class="font-medium">{{ accountName }}</span>
        </p>
      </div>
      <router-link
        to="/app/accounts"
        class="inline-flex items-center text-sm text-indigo-600 hover:text-indigo-700"
      >
        <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
        Назад к списку аккаунтов
      </router-link>
    </div>

    <!-- Индикатор загрузки -->
    <div v-if="loading" class="bg-white shadow rounded-lg p-8 text-center">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
      <p class="mt-2 text-sm text-gray-500">Загрузка настроек...</p>
    </div>

    <!-- Сообщение об ошибке -->
    <div v-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4">
      <p class="text-sm text-red-800">{{ error }}</p>
    </div>

    <!-- Форма настроек -->
    <form v-if="!loading && !error" @submit.prevent="saveSettings" class="space-y-6">
      <!-- Основные настройки -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Основные настройки</h3>
        <div class="space-y-4">
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_enabled"
                v-model="settings.sync_enabled"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_enabled" class="font-medium text-gray-700">Синхронизация включена</label>
              <p class="text-gray-500">Глобальное включение/отключение синхронизации</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Синхронизация товаров -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Синхронизация товаров</h3>
        <div class="space-y-4">
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_products"
                v-model="settings.sync_products"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_products" class="font-medium text-gray-700">Товары</label>
              <p class="text-gray-500">Синхронизировать простые товары</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_variants"
                v-model="settings.sync_variants"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_variants" class="font-medium text-gray-700">Модификации</label>
              <p class="text-gray-500">Синхронизировать модификации товаров</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_bundles"
                v-model="settings.sync_bundles"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_bundles" class="font-medium text-gray-700">Комплекты</label>
              <p class="text-gray-500">Синхронизировать комплекты</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_images"
                v-model="settings.sync_images"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_images" class="font-medium text-gray-700">Изображения</label>
              <p class="text-gray-500">Синхронизировать изображения товаров</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_images_all"
                v-model="settings.sync_images_all"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_images_all" class="font-medium text-gray-700">Все изображения</label>
              <p class="text-gray-500">Синхронизировать все изображения (медленнее, только первое по умолчанию)</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_prices"
                v-model="settings.sync_prices"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_prices" class="font-medium text-gray-700">Цены</label>
              <p class="text-gray-500">Синхронизировать цены товаров</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Синхронизация заказов -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Синхронизация документов</h3>
        <div class="space-y-4">
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_customer_orders"
                v-model="settings.sync_customer_orders"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_customer_orders" class="font-medium text-gray-700">Заказы покупателей</label>
              <p class="text-gray-500">Синхронизировать заказы покупателей из дочернего в главный</p>
            </div>
          </div>

          <div v-if="settings.sync_customer_orders" class="ml-7 space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">ID статуса заказа</label>
              <input
                type="text"
                v-model="settings.customer_order_state_id"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="UUID статуса"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">ID канала продаж</label>
              <input
                type="text"
                v-model="settings.customer_order_sales_channel_id"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="UUID канала продаж"
              />
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_retail_demands"
                v-model="settings.sync_retail_demands"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_retail_demands" class="font-medium text-gray-700">Розничные продажи</label>
              <p class="text-gray-500">Синхронизировать розничные продажи из дочернего в главный</p>
            </div>
          </div>

          <div v-if="settings.sync_retail_demands" class="ml-7 space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">ID статуса продажи</label>
              <input
                type="text"
                v-model="settings.retail_demand_state_id"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="UUID статуса"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">ID канала продаж</label>
              <input
                type="text"
                v-model="settings.retail_demand_sales_channel_id"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="UUID канала продаж"
              />
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="sync_purchase_orders"
                v-model="settings.sync_purchase_orders"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="sync_purchase_orders" class="font-medium text-gray-700">Заказы поставщику</label>
              <p class="text-gray-500">Синхронизировать заказы поставщику из дочернего в главный</p>
            </div>
          </div>

          <div v-if="settings.sync_purchase_orders" class="ml-7 space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">ID статуса заказа поставщику</label>
              <input
                type="text"
                v-model="settings.purchase_order_state_id"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="UUID статуса"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">ID канала продаж для заказов поставщику</label>
              <input
                type="text"
                v-model="settings.purchase_order_sales_channel_id"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="UUID канала продаж"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">ID контрагента-поставщика (главный офис)</label>
              <input
                type="text"
                v-model="settings.supplier_counterparty_id"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="UUID контрагента"
              />
              <p class="mt-1 text-xs text-gray-500">ID контрагента главного офиса в дочернем аккаунте</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Настройки целевых объектов -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Целевые объекты в главном аккаунте</h3>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">ID организации</label>
            <input
              type="text"
              v-model="settings.target_organization_id"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
              placeholder="UUID организации"
            />
            <p class="mt-1 text-xs text-gray-500">Организация для создаваемых документов</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">ID склада</label>
            <input
              type="text"
              v-model="settings.target_store_id"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
              placeholder="UUID склада"
            />
            <p class="mt-1 text-xs text-gray-500">Склад для создаваемых документов (опционально)</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">ID проекта</label>
            <input
              type="text"
              v-model="settings.target_project_id"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
              placeholder="UUID проекта"
            />
            <p class="mt-1 text-xs text-gray-500">Проект для создаваемых документов (опционально)</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">ID ответственного сотрудника</label>
            <input
              type="text"
              v-model="settings.responsible_employee_id"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
              placeholder="UUID сотрудника"
            />
            <p class="mt-1 text-xs text-gray-500">Ответственный за создаваемые документы</p>
          </div>
        </div>
      </div>

      <!-- Фильтрация товаров -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Фильтрация товаров</h3>
        <div class="space-y-4">
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="product_filters_enabled"
                v-model="settings.product_filters_enabled"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="product_filters_enabled" class="font-medium text-gray-700">Включить фильтрацию товаров</label>
              <p class="text-gray-500">Использовать фильтры для выборочной синхронизации товаров</p>
            </div>
          </div>

          <div v-if="settings.product_filters_enabled" class="ml-7">
            <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4">
              <p class="text-sm text-yellow-800">
                <strong>Фильтры товаров</strong> настраиваются в JSON формате.
                <a href="https://github.com/cavaleria-dev/multiaccount/blob/main/docs/PRODUCT_FILTERS.md" target="_blank" class="underline">
                  Смотрите документацию
                </a>
              </p>
            </div>
            <div class="mt-3">
              <label class="block text-sm font-medium text-gray-700">JSON фильтров</label>
              <textarea
                v-model="productFiltersJson"
                rows="10"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono text-xs"
                placeholder='{"enabled": true, "mode": "whitelist", "logic": "AND", "conditions": []}'
              ></textarea>
              <p v-if="filterJsonError" class="mt-1 text-xs text-red-600">{{ filterJsonError }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Автосоздание объектов -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Автоматическое создание</h3>
        <div class="space-y-4">
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="auto_create_attributes"
                v-model="settings.auto_create_attributes"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="auto_create_attributes" class="font-medium text-gray-700">Дополнительные поля</label>
              <p class="text-gray-500">Автоматически создавать доп. поля, если их нет в дочернем аккаунте</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="auto_create_characteristics"
                v-model="settings.auto_create_characteristics"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="auto_create_characteristics" class="font-medium text-gray-700">Характеристики</label>
              <p class="text-gray-500">Автоматически создавать характеристики для модификаций</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="auto_create_price_types"
                v-model="settings.auto_create_price_types"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="auto_create_price_types" class="font-medium text-gray-700">Типы цен</label>
              <p class="text-gray-500">Автоматически создавать типы цен, если их нет в дочернем аккаунте</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Кнопки -->
      <div class="flex justify-between items-center">
        <button
          type="button"
          @click="$router.push('/app/accounts')"
          class="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          Отмена
        </button>
        <button
          type="submit"
          :disabled="saving"
          class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
        >
          <span v-if="saving">Сохранение...</span>
          <span v-else>Сохранить настройки</span>
        </button>
      </div>
    </form>

    <!-- Сообщение об успешном сохранении -->
    <div v-if="saveSuccess" class="fixed bottom-4 right-4 bg-green-50 border border-green-200 rounded-lg p-4 shadow-lg">
      <p class="text-sm text-green-800">✓ Настройки успешно сохранены</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'

const route = useRoute()
const router = useRouter()

const accountId = ref(route.params.accountId)
const accountName = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref(null)
const saveSuccess = ref(false)
const filterJsonError = ref(null)

const settings = ref({
  sync_enabled: true,
  sync_products: true,
  sync_variants: true,
  sync_bundles: true,
  sync_images: true,
  sync_images_all: false,
  sync_prices: true,
  sync_customer_orders: false,
  sync_retail_demands: false,
  sync_purchase_orders: false,
  customer_order_state_id: null,
  customer_order_sales_channel_id: null,
  retail_demand_state_id: null,
  retail_demand_sales_channel_id: null,
  purchase_order_state_id: null,
  purchase_order_sales_channel_id: null,
  supplier_counterparty_id: null,
  target_organization_id: null,
  target_store_id: null,
  target_project_id: null,
  responsible_employee_id: null,
  product_filters_enabled: false,
  product_filters: null,
  auto_create_attributes: true,
  auto_create_characteristics: true,
  auto_create_price_types: true
})

// JSON для фильтров
const productFiltersJson = ref('')

// Следить за изменениями JSON
watch(productFiltersJson, (newValue) => {
  filterJsonError.value = null
  if (!newValue || newValue.trim() === '') {
    settings.value.product_filters = null
    return
  }

  try {
    settings.value.product_filters = JSON.parse(newValue)
  } catch (e) {
    filterJsonError.value = 'Некорректный JSON: ' + e.message
  }
})

// Загрузка настроек
const loadSettings = async () => {
  if (!accountId.value) {
    error.value = 'ID аккаунта не указан'
    return
  }

  try {
    loading.value = true
    error.value = null

    // Загрузить информацию об аккаунте
    const accountResponse = await api.childAccounts.get(accountId.value)
    accountName.value = accountResponse.data.data.account_name || 'Без названия'

    // Загрузить настройки
    const response = await api.syncSettings.get(accountId.value)
    const loadedSettings = response.data.data

    // Заполнить form
    Object.keys(settings.value).forEach(key => {
      if (loadedSettings[key] !== undefined) {
        settings.value[key] = loadedSettings[key]
      }
    })

    // Преобразовать product_filters в JSON
    if (loadedSettings.product_filters) {
      productFiltersJson.value = JSON.stringify(loadedSettings.product_filters, null, 2)
    }

  } catch (err) {
    console.error('Failed to load settings:', err)
    error.value = 'Не удалось загрузить настройки: ' + (err.response?.data?.error || err.message)
  } finally {
    loading.value = false
  }
}

// Сохранение настроек
const saveSettings = async () => {
  try {
    saving.value = true
    filterJsonError.value = null

    // Валидация JSON фильтров
    if (settings.value.product_filters_enabled && productFiltersJson.value) {
      try {
        settings.value.product_filters = JSON.parse(productFiltersJson.value)
      } catch (e) {
        filterJsonError.value = 'Некорректный JSON фильтров'
        return
      }
    }

    await api.syncSettings.update(accountId.value, settings.value)

    // Показать сообщение об успехе
    saveSuccess.value = true
    setTimeout(() => {
      saveSuccess.value = false
    }, 3000)

  } catch (err) {
    console.error('Failed to save settings:', err)
    alert('Не удалось сохранить настройки: ' + (err.response?.data?.error || err.message))
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  loadSettings()
})
</script>
