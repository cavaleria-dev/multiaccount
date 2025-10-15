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
      <p class="text-sm text-red-800 font-medium">{{ error }}</p>
      <details class="mt-2">
        <summary class="text-xs text-red-600 cursor-pointer">Показать детали</summary>
        <pre class="mt-2 text-xs text-red-700 bg-red-100 p-2 rounded overflow-auto">{{ JSON.stringify({ accountId: accountId, route: $route }, null, 2) }}</pre>
      </details>
    </div>

    <!-- Debug info -->
    <div v-if="!loading && !error && !accountId" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
      <p class="text-sm text-yellow-800">⚠️ Account ID отсутствует</p>
      <p class="text-xs text-yellow-700 mt-1">Route params: {{ JSON.stringify($route.params) }}</p>
    </div>

    <!-- Форма настроек -->
    <form v-if="!loading && !error" @submit.prevent="saveSettings" class="space-y-4">
      <!-- Главный выключатель синхронизации -->
      <div class="bg-gradient-to-r from-indigo-500 to-purple-600 shadow-lg rounded-lg p-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-3">
            <div class="bg-white rounded-lg p-2">
              <svg class="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
            </div>
            <div>
              <h3 class="text-base font-semibold text-white">Синхронизация</h3>
              <p class="text-xs text-indigo-100">Глобальное управление всеми настройками</p>
            </div>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input
              v-model="settings.sync_enabled"
              type="checkbox"
              class="sr-only peer"
            />
            <div class="w-14 h-7 bg-white/20 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-white/30 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-white"></div>
            <span class="ml-3 text-sm font-medium text-white">{{ settings.sync_enabled ? 'Вкл' : 'Выкл' }}</span>
          </label>
        </div>
      </div>

      <!-- Секция 1: Синхронизация товаров + Расширенные настройки -->
      <ProductSyncSection
        v-model:settings="settings"
        :syncing="syncing"
        :sync-progress="syncProgress"
        @sync-all-products="syncAllProducts"
      />

      <!-- Секция 2: Сопоставление типов цен + Выбор доп.полей -->
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

      <!-- Секция 3: Фильтрация товаров (full width) -->
      <ProductFiltersSection
        v-model:settings="settings"
        :account-id="accountId"
        :attributes="attributes"
        :folders="folders"
        :loading-folders="loadingFolders"
      />

      <!-- Секция 4: Синхронизация документов + Целевые объекты -->
      <DocumentSyncSection
        v-model:settings="settings"
        :organizations="organizations"
        :stores="stores"
        :projects="projects"
        :employees="employees"
        :sales-channels="salesChannels"
        :customer-order-states="customerOrderStates"
        :purchase-order-states="purchaseOrderStates"
        :loading-organizations="loadingOrganizations"
        :loading-stores="loadingStores"
        :loading-projects="loadingProjects"
        :loading-employees="loadingEmployees"
        :loading-sales-channels="loadingSalesChannels"
        :loading-customer-order-states="loadingCustomerOrderStates"
        :loading-purchase-order-states="loadingPurchaseOrderStates"
        :organizations-error="organizationsError"
        :stores-error="storesError"
        :projects-error="projectsError"
        :employees-error="employeesError"
        :sales-channels-error="salesChannelsError"
        :customer-order-states-error="customerOrderStatesError"
        :purchase-order-states-error="purchaseOrderStatesError"
        :target-objects-meta="targetObjectsMeta"
        @load-organizations="loadOrganizations"
        @load-stores="loadStores"
        @load-projects="loadProjects"
        @load-employees="loadEmployees"
        @load-sales-channels="loadSalesChannels"
        @load-customer-order-states="loadCustomerOrderStates"
        @load-purchase-order-states="loadPurchaseOrderStates"
        @create-customer-order-state="showCreateCustomerOrderStateModal = true"
        @create-retail-demand-state="showCreateRetailDemandStateModal = true"
        @create-purchase-order-state="showCreatePurchaseOrderStateModal = true"
        @create-sales-channel="showCreateSalesChannelModal = true"
        @create-store="showCreateStoreModal = true"
        @create-project="showCreateProjectModal = true"
        @clear-target-store="clearTargetStore"
        @clear-target-project="clearTargetProject"
        @clear-responsible-employee="clearResponsibleEmployee"
        @clear-customer-order-state="clearCustomerOrderState"
        @clear-customer-order-sales-channel="clearCustomerOrderSalesChannel"
        @clear-retail-demand-state="clearRetailDemandState"
        @clear-retail-demand-sales-channel="clearRetailDemandSalesChannel"
        @clear-purchase-order-state="clearPurchaseOrderState"
        @clear-purchase-order-sales-channel="clearPurchaseOrderSalesChannel"
      />

      <!-- Автосоздание объектов -->
      <AutoCreateSection v-model:settings="settings" />

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

    <!-- Modals -->
    <CreateProjectModal
      :show="showCreateProjectModal"
      @close="showCreateProjectModal = false"
      @created="handleProjectCreated"
      ref="createProjectModalRef"
    />

    <CreateStoreModal
      :show="showCreateStoreModal"
      @close="showCreateStoreModal = false"
      @created="handleStoreCreated"
      ref="createStoreModalRef"
    />

    <CreateSalesChannelModal
      :show="showCreateSalesChannelModal"
      @close="showCreateSalesChannelModal = false"
      @created="handleSalesChannelCreated"
      ref="createSalesChannelModalRef"
    />

    <CreateStateModal
      :show="showCreateCustomerOrderStateModal"
      entity-type="customerorder"
      @close="showCreateCustomerOrderStateModal = false"
      @created="handleCustomerOrderStateCreated"
      ref="createCustomerOrderStateModalRef"
    />

    <CreateStateModal
      :show="showCreateRetailDemandStateModal"
      entity-type="customerorder"
      @close="showCreateRetailDemandStateModal = false"
      @created="handleRetailDemandStateCreated"
      ref="createRetailDemandStateModalRef"
    />

    <CreateStateModal
      :show="showCreatePurchaseOrderStateModal"
      entity-type="customerorder"
      @close="showCreatePurchaseOrderStateModal = false"
      @created="handlePurchaseOrderStateCreated"
      ref="createPurchaseOrderStateModalRef"
    />
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import CreateProjectModal from '../components/CreateProjectModal.vue'
import CreateStoreModal from '../components/CreateStoreModal.vue'
import CreateSalesChannelModal from '../components/CreateSalesChannelModal.vue'
import CreateStateModal from '../components/CreateStateModal.vue'
import ProductSyncSection from '../components/franchise-settings/ProductSyncSection.vue'
import PriceMappingsSection from '../components/franchise-settings/PriceMappingsSection.vue'
import ProductFiltersSection from '../components/franchise-settings/ProductFiltersSection.vue'
import DocumentSyncSection from '../components/franchise-settings/DocumentSyncSection.vue'
import AutoCreateSection from '../components/franchise-settings/AutoCreateSection.vue'

const route = useRoute()
const router = useRouter()

const accountId = ref(route.params.accountId)
const accountName = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref(null)
const saveSuccess = ref(false)
const filterJsonError = ref(null)

// Extended settings
const priceTypes = ref({ main: [], child: [] })
const attributes = ref([])
const folders = ref([])
const loadingPriceTypes = ref(false)
const loadingAttributes = ref(false)
const loadingFolders = ref(false)
const syncing = ref(false)
const syncProgress = ref(null)

// Create price type state
const creatingPriceTypeForIndex = ref(null)
const newPriceTypeName = ref('')
const creatingPriceType = ref(false)
const createPriceTypeError = ref(null)

// Target objects state
const organizations = ref([])
const stores = ref([])
const projects = ref([])
const employees = ref([])
const salesChannels = ref([])
const customerOrderStates = ref([])
const purchaseOrderStates = ref([])

const loadingOrganizations = ref(false)
const loadingStores = ref(false)
const loadingProjects = ref(false)
const loadingEmployees = ref(false)
const loadingSalesChannels = ref(false)
const loadingCustomerOrderStates = ref(false)
const loadingPurchaseOrderStates = ref(false)

const organizationsError = ref(null)
const storesError = ref(null)
const projectsError = ref(null)
const employeesError = ref(null)
const salesChannelsError = ref(null)
const customerOrderStatesError = ref(null)
const purchaseOrderStatesError = ref(null)

// Modal state
const showCreateProjectModal = ref(false)
const showCreateStoreModal = ref(false)
const showCreateSalesChannelModal = ref(false)
const showCreateCustomerOrderStateModal = ref(false)
const showCreateRetailDemandStateModal = ref(false)
const showCreatePurchaseOrderStateModal = ref(false)

// Modal refs
const createProjectModalRef = ref(null)
const createStoreModalRef = ref(null)
const createSalesChannelModalRef = ref(null)
const createCustomerOrderStateModalRef = ref(null)
const createRetailDemandStateModalRef = ref(null)
const createPurchaseOrderStateModalRef = ref(null)

// Target objects metadata (for displaying names)
const targetObjectsMeta = ref({})

const settings = ref({
  sync_enabled: true,
  sync_products: true,
  sync_variants: true,
  sync_bundles: true,
  sync_services: true,
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
  product_match_field: 'article',
  create_product_folders: true,
  price_mappings: null,
  attribute_sync_list: null,
  auto_create_attributes: true,
  auto_create_characteristics: true,
  auto_create_price_types: true
})

// Price mappings array for UI
const priceMappings = ref([])

// Attribute sync list for UI
const selectedAttributes = ref([])

// Load extended data
const loadPriceTypes = async () => {
  try {
    loadingPriceTypes.value = true
    const response = await api.syncSettings.getPriceTypes(accountId.value)
    priceTypes.value = response.data
  } catch (err) {
    console.error('Failed to load price types:', err)
  } finally {
    loadingPriceTypes.value = false
  }
}

const loadAttributes = async () => {
  try {
    loadingAttributes.value = true
    const response = await api.syncSettings.getAttributes(accountId.value)
    attributes.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load attributes:', err)
  } finally {
    loadingAttributes.value = false
  }
}

const loadFolders = async () => {
  try {
    loadingFolders.value = true
    const response = await api.syncSettings.getFolders(accountId.value)
    folders.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load folders:', err)
  } finally {
    loadingFolders.value = false
  }
}

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

    // Convert price_mappings from JSON to array
    if (loadedSettings.price_mappings) {
      priceMappings.value = Array.isArray(loadedSettings.price_mappings)
        ? loadedSettings.price_mappings
        : []
    }

    // Convert attribute_sync_list from JSON to array
    if (loadedSettings.attribute_sync_list) {
      selectedAttributes.value = Array.isArray(loadedSettings.attribute_sync_list)
        ? loadedSettings.attribute_sync_list
        : []
    }

    // Load target_objects_meta
    if (loadedSettings.target_objects_meta) {
      targetObjectsMeta.value = loadedSettings.target_objects_meta || {}
    }

    // Load extended data
    await Promise.all([
      loadPriceTypes(),
      loadAttributes(),
      loadFolders()
    ])

  } catch (err) {
    console.error('Failed to load settings:', err)
    error.value = 'Не удалось загрузить настройки: ' + (err.response?.data?.error || err.message)
  } finally {
    loading.value = false
  }
}

// Price mappings management
const addPriceMapping = () => {
  priceMappings.value.push({
    main_price_type_id: '',
    child_price_type_id: ''
  })
}

const removePriceMapping = (index) => {
  priceMappings.value.splice(index, 1)
}

// Create price type management
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

const createNewPriceType = async (index) => {
  // Валидация
  if (!newPriceTypeName.value || newPriceTypeName.value.trim().length < 2) {
    createPriceTypeError.value = 'Название должно содержать минимум 2 символа'
    return
  }

  try {
    creatingPriceType.value = true
    createPriceTypeError.value = null

    const response = await api.syncSettings.createPriceType(accountId.value, {
      name: newPriceTypeName.value.trim()
    })

    const createdPriceType = response.data.data

    // Добавить в список типов цен дочернего аккаунта
    priceTypes.value.child.push({
      id: createdPriceType.id,
      name: createdPriceType.name
    })

    // Автоматически выбрать созданный тип в текущем маппинге
    priceMappings.value[index].child_price_type_id = createdPriceType.id

    // Скрыть форму
    hideCreatePriceTypeForm()

    // Показать успешное уведомление (можно добавить позже)
    console.log('Price type created successfully:', createdPriceType)

  } catch (err) {
    console.error('Failed to create price type:', err)

    // Обработка ошибок
    if (err.response?.status === 409) {
      createPriceTypeError.value = 'Тип цены с таким названием уже существует'
    } else {
      createPriceTypeError.value = err.response?.data?.error || 'Не удалось создать тип цены'
    }
  } finally {
    creatingPriceType.value = false
  }
}

// Load target objects functions (lazy loading)
const loadOrganizations = async () => {
  if (organizations.value.length > 0) return // Already loaded

  try {
    loadingOrganizations.value = true
    organizationsError.value = null
    const response = await api.syncSettings.getOrganizations(accountId.value)
    organizations.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load organizations:', err)
    organizationsError.value = 'Не удалось загрузить организации'
  } finally {
    loadingOrganizations.value = false
  }
}

const loadStores = async () => {
  if (stores.value.length > 0) return // Already loaded

  try {
    loadingStores.value = true
    storesError.value = null
    const response = await api.syncSettings.getStores(accountId.value)
    stores.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load stores:', err)
    storesError.value = 'Не удалось загрузить склады'
  } finally {
    loadingStores.value = false
  }
}

const loadProjects = async () => {
  if (projects.value.length > 0) return // Already loaded

  try {
    loadingProjects.value = true
    projectsError.value = null
    const response = await api.syncSettings.getProjects(accountId.value)
    projects.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load projects:', err)
    projectsError.value = 'Не удалось загрузить проекты'
  } finally {
    loadingProjects.value = false
  }
}

const loadEmployees = async () => {
  if (employees.value.length > 0) return // Already loaded

  try {
    loadingEmployees.value = true
    employeesError.value = null
    const response = await api.syncSettings.getEmployees(accountId.value)
    employees.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load employees:', err)
    employeesError.value = 'Не удалось загрузить сотрудников'
  } finally {
    loadingEmployees.value = false
  }
}

const loadSalesChannels = async () => {
  if (salesChannels.value.length > 0) return // Already loaded

  try {
    loadingSalesChannels.value = true
    salesChannelsError.value = null
    const response = await api.syncSettings.getSalesChannels(accountId.value)
    salesChannels.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load sales channels:', err)
    salesChannelsError.value = 'Не удалось загрузить каналы продаж'
  } finally {
    loadingSalesChannels.value = false
  }
}

const loadCustomerOrderStates = async () => {
  if (customerOrderStates.value.length > 0) return // Already loaded

  try {
    loadingCustomerOrderStates.value = true
    customerOrderStatesError.value = null
    const response = await api.syncSettings.getStates(accountId.value, 'customerorder')
    customerOrderStates.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load customer order states:', err)
    customerOrderStatesError.value = 'Не удалось загрузить статусы заказов'
  } finally {
    loadingCustomerOrderStates.value = false
  }
}

const loadPurchaseOrderStates = async () => {
  // ВАЖНО: purchaseorder в child → customerorder в main
  // Поэтому загружаем customerorder states вместо purchaseorder
  if (customerOrderStates.value.length > 0) {
    // Если customerOrderStates уже загружены, используем их
    purchaseOrderStates.value = customerOrderStates.value
    return
  }

  try {
    loadingPurchaseOrderStates.value = true
    purchaseOrderStatesError.value = null
    const response = await api.syncSettings.getStates(accountId.value, 'customerorder')
    purchaseOrderStates.value = response.data.data || []
    // Синхронизируем с customerOrderStates для консистентности
    customerOrderStates.value = purchaseOrderStates.value
  } catch (err) {
    console.error('Failed to load purchase order states:', err)
    purchaseOrderStatesError.value = 'Не удалось загрузить статусы заказов'
  } finally {
    loadingPurchaseOrderStates.value = false
  }
}

// Clear handlers (update metadata when clearing)
const clearTargetStore = () => {
  updateTargetObjectMeta('target_store_id', null, null)
}

const clearTargetProject = () => {
  updateTargetObjectMeta('target_project_id', null, null)
}

const clearResponsibleEmployee = () => {
  updateTargetObjectMeta('responsible_employee_id', null, null)
}

const clearCustomerOrderState = () => {
  updateTargetObjectMeta('customer_order_state_id', null, null)
}

const clearCustomerOrderSalesChannel = () => {
  updateTargetObjectMeta('customer_order_sales_channel_id', null, null)
}

const clearRetailDemandState = () => {
  updateTargetObjectMeta('retail_demand_state_id', null, null)
}

const clearRetailDemandSalesChannel = () => {
  updateTargetObjectMeta('retail_demand_sales_channel_id', null, null)
}

const clearPurchaseOrderState = () => {
  updateTargetObjectMeta('purchase_order_state_id', null, null)
}

const clearPurchaseOrderSalesChannel = () => {
  updateTargetObjectMeta('purchase_order_sales_channel_id', null, null)
}

// Update target object metadata helper
const updateTargetObjectMeta = (fieldName, id, name) => {
  if (!targetObjectsMeta.value) {
    targetObjectsMeta.value = {}
  }

  if (id && name) {
    targetObjectsMeta.value[fieldName] = { id, name }
  } else {
    delete targetObjectsMeta.value[fieldName]
  }
}

// Watch for changes in target object IDs and update metadata
watch(() => settings.value.target_organization_id, (newValue) => {
  if (newValue) {
    const org = organizations.value.find(o => o.id === newValue)
    if (org) updateTargetObjectMeta('target_organization_id', org.id, org.name)
  }
})

watch(() => settings.value.target_store_id, (newValue) => {
  if (newValue) {
    const store = stores.value.find(s => s.id === newValue)
    if (store) updateTargetObjectMeta('target_store_id', store.id, store.name)
  }
})

watch(() => settings.value.target_project_id, (newValue) => {
  if (newValue) {
    const project = projects.value.find(p => p.id === newValue)
    if (project) updateTargetObjectMeta('target_project_id', project.id, project.name)
  }
})

watch(() => settings.value.responsible_employee_id, (newValue) => {
  if (newValue) {
    const employee = employees.value.find(e => e.id === newValue)
    if (employee) updateTargetObjectMeta('responsible_employee_id', employee.id, employee.name)
  }
})

watch(() => settings.value.customer_order_state_id, (newValue) => {
  if (newValue) {
    const state = customerOrderStates.value.find(s => s.id === newValue)
    if (state) updateTargetObjectMeta('customer_order_state_id', state.id, state.name)
  }
})

watch(() => settings.value.customer_order_sales_channel_id, (newValue) => {
  if (newValue) {
    const channel = salesChannels.value.find(c => c.id === newValue)
    if (channel) updateTargetObjectMeta('customer_order_sales_channel_id', channel.id, channel.name)
  }
})

watch(() => settings.value.retail_demand_state_id, (newValue) => {
  if (newValue) {
    const state = customerOrderStates.value.find(s => s.id === newValue)
    if (state) updateTargetObjectMeta('retail_demand_state_id', state.id, state.name)
  }
})

watch(() => settings.value.retail_demand_sales_channel_id, (newValue) => {
  if (newValue) {
    const channel = salesChannels.value.find(c => c.id === newValue)
    if (channel) updateTargetObjectMeta('retail_demand_sales_channel_id', channel.id, channel.name)
  }
})

watch(() => settings.value.purchase_order_state_id, (newValue) => {
  if (newValue) {
    const state = purchaseOrderStates.value.find(s => s.id === newValue)
    if (state) updateTargetObjectMeta('purchase_order_state_id', state.id, state.name)
  }
})

watch(() => settings.value.purchase_order_sales_channel_id, (newValue) => {
  if (newValue) {
    const channel = salesChannels.value.find(c => c.id === newValue)
    if (channel) updateTargetObjectMeta('purchase_order_sales_channel_id', channel.id, channel.name)
  }
})

// Modal creation handlers
const handleProjectCreated = async (data) => {
  try {
    createProjectModalRef.value?.setLoading(true)

    const response = await api.syncSettings.createProject(accountId.value, data)
    const created = response.data.data

    // Add to projects list
    projects.value.push(created)

    // Select the newly created project
    settings.value.target_project_id = created.id
    updateTargetObjectMeta('target_project_id', created.id, created.name)

    // Close modal
    showCreateProjectModal.value = false

  } catch (err) {
    console.error('Failed to create project:', err)
    createProjectModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать проект')
  } finally {
    createProjectModalRef.value?.setLoading(false)
  }
}

const handleStoreCreated = async (data) => {
  try {
    createStoreModalRef.value?.setLoading(true)

    const response = await api.syncSettings.createStore(accountId.value, data)
    const created = response.data.data

    // Add to stores list
    stores.value.push(created)

    // Select the newly created store
    settings.value.target_store_id = created.id
    updateTargetObjectMeta('target_store_id', created.id, created.name)

    // Close modal
    showCreateStoreModal.value = false

  } catch (err) {
    console.error('Failed to create store:', err)
    createStoreModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать склад')
  } finally {
    createStoreModalRef.value?.setLoading(false)
  }
}

const handleSalesChannelCreated = async (data) => {
  try {
    createSalesChannelModalRef.value?.setLoading(true)

    const response = await api.syncSettings.createSalesChannel(accountId.value, data)
    const created = response.data.data

    // Add to sales channels list
    salesChannels.value.push(created)

    // Don't auto-select here since it could be for any of the 3 sales channel fields
    // User can select manually after creation

    // Close modal
    showCreateSalesChannelModal.value = false

  } catch (err) {
    console.error('Failed to create sales channel:', err)
    createSalesChannelModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать канал продаж')
  } finally {
    createSalesChannelModalRef.value?.setLoading(false)
  }
}

const handleCustomerOrderStateCreated = async (data) => {
  try {
    createCustomerOrderStateModalRef.value?.setLoading(true)

    const response = await api.syncSettings.createState(accountId.value, 'customerorder', data)
    const created = response.data.data

    // Add to customer order states list
    customerOrderStates.value.push(created)

    // Select the newly created state
    settings.value.customer_order_state_id = created.id
    updateTargetObjectMeta('customer_order_state_id', created.id, created.name)

    // Close modal
    showCreateCustomerOrderStateModal.value = false

  } catch (err) {
    console.error('Failed to create customer order state:', err)
    createCustomerOrderStateModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать статус')
  } finally {
    createCustomerOrderStateModalRef.value?.setLoading(false)
  }
}

const handleRetailDemandStateCreated = async (data) => {
  try {
    createRetailDemandStateModalRef.value?.setLoading(true)

    const response = await api.syncSettings.createState(accountId.value, 'customerorder', data)
    const created = response.data.data

    // Add to customer order states list (retail demand uses same states)
    if (!customerOrderStates.value.find(s => s.id === created.id)) {
      customerOrderStates.value.push(created)
    }

    // Select the newly created state
    settings.value.retail_demand_state_id = created.id
    updateTargetObjectMeta('retail_demand_state_id', created.id, created.name)

    // Close modal
    showCreateRetailDemandStateModal.value = false

  } catch (err) {
    console.error('Failed to create retail demand state:', err)
    createRetailDemandStateModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать статус')
  } finally {
    createRetailDemandStateModalRef.value?.setLoading(false)
  }
}

const handlePurchaseOrderStateCreated = async (data) => {
  try {
    createPurchaseOrderStateModalRef.value?.setLoading(true)

    // ВАЖНО: purchaseorder в child → customerorder в main
    // Поэтому создаем customerorder state
    const response = await api.syncSettings.createState(accountId.value, 'customerorder', data)
    const created = response.data.data

    // Add to both states lists (they share the same states)
    if (!customerOrderStates.value.find(s => s.id === created.id)) {
      customerOrderStates.value.push(created)
    }
    if (!purchaseOrderStates.value.find(s => s.id === created.id)) {
      purchaseOrderStates.value.push(created)
    }

    // Select the newly created state
    settings.value.purchase_order_state_id = created.id
    updateTargetObjectMeta('purchase_order_state_id', created.id, created.name)

    // Close modal
    showCreatePurchaseOrderStateModal.value = false

  } catch (err) {
    console.error('Failed to create purchase order state:', err)
    createPurchaseOrderStateModalRef.value?.setError(err.response?.data?.error || 'Не удалось создать статус')
  } finally {
    createPurchaseOrderStateModalRef.value?.setLoading(false)
  }
}

// Sync all products action
const syncAllProducts = async () => {
  if (!confirm('Запустить синхронизацию всех товаров? Это может занять продолжительное время.')) {
    return
  }

  try {
    syncing.value = true
    syncProgress.value = 'Запуск синхронизации...'

    const response = await api.syncActions.syncAllProducts(accountId.value)

    syncProgress.value = `Синхронизация запущена! Создано задач: ${response.data.tasks_created}`

    setTimeout(() => {
      syncProgress.value = null
      syncing.value = false
    }, 5000)

  } catch (err) {
    console.error('Failed to sync products:', err)
    alert('Не удалось запустить синхронизацию: ' + (err.response?.data?.error || err.message))
    syncing.value = false
    syncProgress.value = null
  }
}

// Сохранение настроек
const saveSettings = async () => {
  try {
    saving.value = true
    filterJsonError.value = null

    // Convert arrays back to JSON for storage
    settings.value.price_mappings = priceMappings.value.length > 0 ? priceMappings.value : null
    settings.value.attribute_sync_list = selectedAttributes.value.length > 0 ? selectedAttributes.value : null
    settings.value.target_objects_meta = Object.keys(targetObjectsMeta.value).length > 0 ? targetObjectsMeta.value : null

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
