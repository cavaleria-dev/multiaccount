<template>
  <div class="space-y-6">
    <!-- Loading -->
    <div v-if="loading" class="bg-white shadow rounded-lg p-8 text-center">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
      <p class="mt-2 text-sm text-gray-500">Загрузка настроек...</p>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4">
      <p class="text-sm text-red-800 font-medium">{{ error }}</p>
    </div>

    <!-- Form -->
    <form v-else @submit.prevent="saveSettings" class="space-y-6">
      <!-- Product Sync Section -->
      <ProductSyncSection
        v-model:settings="settings"
        :syncing="syncing"
        :sync-progress="syncProgress"
        @sync-all-products="syncAllProducts"
      />

      <!-- Price Mappings Section -->
      <PriceMappingsSection
        v-model="priceMappings"
        v-model:selected-attributes="selectedAttributes"
        v-model:new-price-type-name="newPriceTypeName"
        :price-types="priceTypes"
        :attributes="attributes"
        :loading-price-types="loadingPriceTypes"
        :loading-attributes="loadingAttributes"
        :creating-price-type-for-index="creatingPriceTypeForIndex"
        :creating-price-type="creatingPriceType"
        :create-price-type-error="createPriceTypeError"
        @add-price-mapping="addPriceMapping"
        @remove-price-mapping="removePriceMapping"
        @show-create-price-type="showCreatePriceTypeForm"
        @hide-create-price-type="hideCreatePriceTypeForm"
        @create-price-type="createNewPriceType"
      />

      <!-- Product Filters Section -->
      <ProductFiltersSection
        v-model:settings="settings"
        :account-id="accountId"
        :attributes="attributes"
        :folders="folders"
        :loading-folders="loadingFolders"
      />

      <!-- Save Button -->
      <div class="flex justify-end">
        <button
          type="submit"
          :disabled="saving"
          class="inline-flex justify-center rounded-lg border border-transparent bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 px-6 py-2.5 text-sm font-medium text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 transition-all duration-200"
        >
          <svg v-if="saving" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span v-if="saving">Сохранение...</span>
          <span v-else>Сохранить настройки</span>
        </button>
      </div>
    </form>

    <!-- Success Message -->
    <div v-if="saveSuccess" class="fixed bottom-4 right-4 bg-green-50 border border-green-200 rounded-lg p-4 shadow-lg animate-slide-up z-50">
      <div class="flex items-center">
        <svg class="h-5 w-5 text-green-400 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-sm text-green-800 font-medium">Настройки успешно сохранены</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'
import ProductSyncSection from '../../components/franchise-settings/ProductSyncSection.vue'
import PriceMappingsSection from '../../components/franchise-settings/PriceMappingsSection.vue'
import ProductFiltersSection from '../../components/franchise-settings/ProductFiltersSection.vue'

const props = defineProps({
  accountId: {
    type: String,
    required: true
  }
})

// State
const loading = ref(false)
const saving = ref(false)
const error = ref(null)
const saveSuccess = ref(false)

const settings = ref({
  sync_products: true,
  sync_variants: true,
  sync_bundles: true,
  sync_services: true,
  sync_images: true,
  sync_images_all: false,
  sync_prices: true,
  product_match_field: 'article',
  service_match_field: 'code',
  create_product_folders: true,
  product_filters_enabled: false,
  product_filters: { groups: [] }
})

const priceTypes = ref({ main: [], child: [] })
const attributes = ref([])
const folders = ref([])
const priceMappings = ref([])
const selectedAttributes = ref([])

const loadingPriceTypes = ref(false)
const loadingAttributes = ref(false)
const loadingFolders = ref(false)

const syncing = ref(false)
const syncProgress = ref(null)

// Price type creation
const creatingPriceTypeForIndex = ref(null)
const newPriceTypeName = ref('')
const creatingPriceType = ref(false)
const createPriceTypeError = ref(null)

// Load settings
const loadSettings = async () => {
  try {
    loading.value = true
    error.value = null

    const response = await api.syncSettings.get(props.accountId)
    const data = response.data

    // Update settings
    Object.keys(settings.value).forEach(key => {
      if (data[key] !== undefined) {
        settings.value[key] = data[key]
      }
    })

    // Parse JSON fields
    priceMappings.value = data.price_mappings ? JSON.parse(data.price_mappings) : []
    selectedAttributes.value = data.attribute_sync_list ? JSON.parse(data.attribute_sync_list) : []

  } catch (err) {
    console.error('Failed to load settings:', err)
    error.value = 'Не удалось загрузить настройки'
  } finally {
    loading.value = false
  }
}

// Load price types
const loadPriceTypes = async () => {
  try {
    loadingPriceTypes.value = true
    const response = await api.syncSettings.getPriceTypes(props.accountId)
    priceTypes.value = response.data
  } catch (err) {
    console.error('Failed to load price types:', err)
  } finally {
    loadingPriceTypes.value = false
  }
}

// Load attributes
const loadAttributes = async () => {
  try {
    loadingAttributes.value = true
    const response = await api.syncSettings.getAttributes(props.accountId)
    attributes.value = response.data
  } catch (err) {
    console.error('Failed to load attributes:', err)
  } finally {
    loadingAttributes.value = false
  }
}

// Load folders
const loadFolders = async () => {
  try {
    loadingFolders.value = true
    const response = await api.syncSettings.getFolders(props.accountId)
    folders.value = response.data
  } catch (err) {
    console.error('Failed to load folders:', err)
  } finally {
    loadingFolders.value = false
  }
}

// Price mappings
const addPriceMapping = () => {
  priceMappings.value.push({
    main_price_type_id: '',
    child_price_type_id: ''
  })
}

const removePriceMapping = (index) => {
  priceMappings.value.splice(index, 1)
}

// Price type creation
const showCreatePriceTypeForm = (index) => {
  creatingPriceTypeForIndex.value = index
  newPriceTypeName.value = ''
  createPriceTypeError.value = null
}

const hideCreatePriceTypeForm = () => {
  creatingPriceTypeForIndex.value = null
  newPriceTypeName.value = ''
  createPriceTypeError.value = null
}

const createNewPriceType = async () => {
  if (!newPriceTypeName.value.trim()) {
    createPriceTypeError.value = 'Введите название типа цены'
    return
  }

  try {
    creatingPriceType.value = true
    createPriceTypeError.value = null

    const response = await api.syncSettings.createPriceType(props.accountId, {
      name: newPriceTypeName.value.trim()
    })

    // Reload price types
    await loadPriceTypes()

    // Hide form
    hideCreatePriceTypeForm()
  } catch (err) {
    console.error('Failed to create price type:', err)
    createPriceTypeError.value = err.response?.data?.error || 'Не удалось создать тип цены'
  } finally {
    creatingPriceType.value = false
  }
}

// Sync all products
const syncAllProducts = async () => {
  try {
    syncing.value = true
    syncProgress.value = 'Запуск синхронизации...'

    await api.syncActions.syncAllProducts(props.accountId)

    syncProgress.value = 'Синхронизация запущена! Проверьте очередь задач.'

    setTimeout(() => {
      syncing.value = false
      syncProgress.value = null
    }, 3000)
  } catch (err) {
    console.error('Failed to sync products:', err)
    syncProgress.value = 'Ошибка: ' + (err.response?.data?.error || err.message)

    setTimeout(() => {
      syncing.value = false
      syncProgress.value = null
    }, 5000)
  }
}

// Save settings
const saveSettings = async () => {
  try {
    saving.value = true
    error.value = null

    const data = {
      ...settings.value,
      price_mappings: JSON.stringify(priceMappings.value),
      attribute_sync_list: JSON.stringify(selectedAttributes.value)
    }

    await api.syncSettings.update(props.accountId, data)

    // Show success message
    saveSuccess.value = true
    setTimeout(() => {
      saveSuccess.value = false
    }, 3000)
  } catch (err) {
    console.error('Failed to save settings:', err)
    error.value = err.response?.data?.error || 'Не удалось сохранить настройки'
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  loadSettings()
  loadPriceTypes()
  loadAttributes()
  loadFolders()
})
</script>

<style scoped>
@keyframes slide-up {
  from {
    transform: translateY(100%);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}

.animate-slide-up {
  animation: slide-up 0.3s ease-out;
}
</style>
