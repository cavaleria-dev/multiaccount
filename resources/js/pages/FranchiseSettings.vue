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

    <!-- Tabs Navigation -->
    <div v-if="!loading && !error" class="bg-white shadow rounded-lg">
      <div class="border-b border-gray-200">
        <nav class="-mb-px flex space-x-8 px-6" aria-label="Tabs">
          <button
            type="button"
            @click="activeTab = 'products'"
            :class="tabClass('products')"
            class="py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200 whitespace-nowrap"
          >
            <span class="flex items-center">
              <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              </svg>
              Товары и услуги
            </span>
          </button>
          <button
            type="button"
            @click="activeTab = 'documents'"
            :class="tabClass('documents')"
            class="py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200 whitespace-nowrap"
          >
            <span class="flex items-center">
              <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              Документы
            </span>
          </button>
          <button
            type="button"
            @click="activeTab = 'general'"
            :class="tabClass('general')"
            class="py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200 whitespace-nowrap"
          >
            <span class="flex items-center">
              <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              Общие настройки
            </span>
          </button>
        </nav>
      </div>
    </div>

    <!-- Форма настроек -->
    <form v-if="!loading && !error" @submit.prevent="saveSettings" class="space-y-4">
      <!-- Вкладка: Товары и услуги -->
      <div v-show="activeTab === 'products'" class="space-y-4">
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
      </div>
      <!-- Конец вкладки: Товары и услуги -->

      <!-- Вкладка: Документы -->
      <div v-show="activeTab === 'documents'" class="space-y-4">
      <!-- Секция: Синхронизация документов + Целевые объекты -->
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
      </div>
      <!-- Конец вкладки: Документы -->

      <!-- Вкладка: Общие настройки -->
      <div v-show="activeTab === 'general'" class="space-y-4">
      <!-- Главный выключатель синхронизации -->
      <div
        class="shadow-lg rounded-lg p-4 transition-all duration-300"
        :class="settings.sync_enabled ? 'bg-gradient-to-r from-indigo-600 to-purple-700' : 'bg-gradient-to-r from-gray-400 to-gray-500'"
      >
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-3">
            <div class="bg-white/90 rounded-lg p-2 shadow">
              <svg
                class="h-6 w-6 transition-colors duration-300"
                :class="settings.sync_enabled ? 'text-indigo-600' : 'text-gray-500'"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
            </div>
            <div>
              <h3 class="text-base font-semibold text-white">Синхронизация</h3>
              <p class="text-xs text-white/80">Глобальное управление всеми настройками</p>
            </div>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input
              v-model="settings.sync_enabled"
              type="checkbox"
              class="sr-only peer"
            />
            <div class="w-14 h-7 bg-white/30 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-white/40 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all after:shadow-md peer-checked:bg-white/90"></div>
            <span class="ml-3 text-sm font-medium text-white">{{ settings.sync_enabled ? 'Вкл' : 'Выкл' }}</span>
          </label>
        </div>
      </div>

      <!-- Автосоздание объектов -->
      <AutoCreateSection v-model:settings="settings" />

      <!-- НДС и налогообложение -->
      <VatSyncSection
        :settings="settings"
        @update:settings="handleVatSettingsUpdate"
      />
      </div>
      <!-- Конец вкладки: Общие настройки -->

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
import { useMoyskladEntities } from '../composables/useMoyskladEntities'
import { useTargetObjectsMetadata } from '../composables/useTargetObjectsMetadata'
import { useFranchiseSettingsData } from '../composables/useFranchiseSettingsData'
import { usePriceMappingsManager } from '../composables/usePriceMappingsManager'
import { useModalManager } from '../composables/useModalManager'
import { useFranchiseSettingsForm } from '../composables/useFranchiseSettingsForm'
import CreateProjectModal from '../components/CreateProjectModal.vue'
import CreateStoreModal from '../components/CreateStoreModal.vue'
import CreateSalesChannelModal from '../components/CreateSalesChannelModal.vue'
import CreateStateModal from '../components/CreateStateModal.vue'
import ProductSyncSection from '../components/franchise-settings/ProductSyncSection.vue'
import PriceMappingsSection from '../components/franchise-settings/PriceMappingsSection.vue'
import ProductFiltersSection from '../components/franchise-settings/ProductFiltersSection.vue'
import DocumentSyncSection from '../components/franchise-settings/DocumentSyncSection.vue'
import AutoCreateSection from '../components/franchise-settings/AutoCreateSection.vue'
import VatSyncSection from '../components/franchise-settings/VatSyncSection.vue'

const route = useRoute()
const router = useRouter()

const accountId = ref(route.params.accountId)

// Active tab state
const activeTab = ref('products')

// Tab class helper function
const tabClass = (tab) => {
  if (activeTab.value === tab) {
    return 'border-indigo-600 text-indigo-600'
  }
  return 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
}

// Watch for query param to switch tabs
watch(
  () => route.query.tab,
  (newTab) => {
    if (newTab && ['products', 'documents', 'general'].includes(newTab)) {
      activeTab.value = newTab
    }
  },
  { immediate: true }
)

// Entity loaders using composables
const organizationsLoader = useMoyskladEntities(accountId.value, 'organizations')
const storesLoader = useMoyskladEntities(accountId.value, 'stores')
const projectsLoader = useMoyskladEntities(accountId.value, 'projects')
const employeesLoader = useMoyskladEntities(accountId.value, 'employees')
const salesChannelsLoader = useMoyskladEntities(accountId.value, 'salesChannels')
const customerOrderStatesLoader = useMoyskladEntities(accountId.value, 'customerOrderStates')
const purchaseOrderStatesLoader = useMoyskladEntities(accountId.value, 'purchaseOrderStates')

// Access loader data (backwards compatibility)
const organizations = organizationsLoader.items
const stores = storesLoader.items
const projects = projectsLoader.items
const employees = employeesLoader.items
const salesChannels = salesChannelsLoader.items
const customerOrderStates = customerOrderStatesLoader.items
const purchaseOrderStates = purchaseOrderStatesLoader.items

const loadingOrganizations = organizationsLoader.loading
const loadingStores = storesLoader.loading
const loadingProjects = projectsLoader.loading
const loadingEmployees = employeesLoader.loading
const loadingSalesChannels = salesChannelsLoader.loading
const loadingCustomerOrderStates = customerOrderStatesLoader.loading
const loadingPurchaseOrderStates = purchaseOrderStatesLoader.loading

const organizationsError = organizationsLoader.error
const storesError = storesLoader.error
const projectsError = projectsLoader.error
const employeesError = employeesLoader.error
const salesChannelsError = salesChannelsLoader.error
const customerOrderStatesError = customerOrderStatesLoader.error
const purchaseOrderStatesError = purchaseOrderStatesLoader.error

// Attribute sync list for UI (needed for form composition)
const selectedAttributes = ref([])

// Settings data composable (priceTypes, attributes, folders)
const settingsData = useFranchiseSettingsData(accountId)
const {
  priceTypes,
  attributes,
  folders,
  loadingPriceTypes,
  loadingAttributes,
  loadingFolders
} = settingsData

// Price mappings manager composable
const priceMappingsManager = usePriceMappingsManager(accountId, priceTypes)
const {
  priceMappings,
  creatingPriceTypeForIndex,
  newPriceTypeName,
  creatingPriceType,
  createPriceTypeError,
  addPriceMapping,
  removePriceMapping,
  showCreatePriceTypeForm,
  hideCreatePriceTypeForm,
  createNewPriceType
} = priceMappingsManager

// Modal manager composable
const modalManager = useModalManager()
const {
  showCreateProjectModal,
  showCreateStoreModal,
  showCreateSalesChannelModal,
  showCreateCustomerOrderStateModal,
  showCreateRetailDemandStateModal,
  showCreatePurchaseOrderStateModal,
  createProjectModalRef,
  createStoreModalRef,
  createSalesChannelModalRef,
  createCustomerOrderStateModalRef,
  createRetailDemandStateModalRef,
  createPurchaseOrderStateModalRef
} = modalManager

// Sync state (not moved to composable as it's specific to this component)
const syncing = ref(false)
const syncProgress = ref(null)

// Form composable (settings, load, save)
// Create with temporary empty dependencies, will setup metadata manager after
const formManager = useFranchiseSettingsForm(accountId, {
  priceMappingsManager,
  selectedAttributes,
  targetObjectsMeta: ref({}),
  metadataManager: null
})

const {
  accountName,
  loading,
  saving,
  error,
  saveSuccess,
  settings
} = formManager

// Target objects metadata manager (initialized after settings ref is available)
const metadataManager = useTargetObjectsMetadata(settings, {
  organizations,
  stores,
  projects,
  employees,
  customerOrderStates,
  salesChannels,
  purchaseOrderStates
})

const targetObjectsMeta = metadataManager.metadata

// Load target objects functions (using composables)
const loadOrganizations = () => organizationsLoader.load()
const loadStores = () => storesLoader.load()
const loadProjects = () => projectsLoader.load()
const loadEmployees = () => employeesLoader.load()
const loadSalesChannels = () => salesChannelsLoader.load()
const loadCustomerOrderStates = () => customerOrderStatesLoader.load()

const loadPurchaseOrderStates = async () => {
  // ВАЖНО: purchaseorder в child → customerorder в main
  // Поэтому загружаем customerorder states вместо purchaseorder
  if (customerOrderStates.value.length > 0) {
    // Если customerOrderStates уже загружены, используем их
    purchaseOrderStates.value = customerOrderStates.value
    return
  }

  // Используем composable для загрузки
  await purchaseOrderStatesLoader.load()
  // Синхронизируем с customerOrderStates для консистентности
  if (purchaseOrderStates.value.length > 0) {
    customerOrderStates.value = purchaseOrderStates.value
  }
}

// Clear handlers (using metadata manager)
const clearTargetStore = () => metadataManager.clearMetadata('target_store_id')
const clearTargetProject = () => metadataManager.clearMetadata('target_project_id')
const clearResponsibleEmployee = () => metadataManager.clearMetadata('responsible_employee_id')
const clearCustomerOrderState = () => metadataManager.clearMetadata('customer_order_state_id')
const clearCustomerOrderSalesChannel = () => metadataManager.clearMetadata('customer_order_sales_channel_id')
const clearRetailDemandState = () => metadataManager.clearMetadata('retail_demand_state_id')
const clearRetailDemandSalesChannel = () => metadataManager.clearMetadata('retail_demand_sales_channel_id')
const clearPurchaseOrderState = () => metadataManager.clearMetadata('purchase_order_state_id')
const clearPurchaseOrderSalesChannel = () => metadataManager.clearMetadata('purchase_order_sales_channel_id')

// Unified modal creation handler (replaces 6 similar functions - 142 lines → 50 lines)
const handleEntityCreated = async (entityType, data) => {
  const config = {
    project: {
      apiMethod: 'createProject',
      loader: projectsLoader,
      settingKey: 'target_project_id',
      modalRef: createProjectModalRef,
      modalShow: showCreateProjectModal,
      errorMsg: 'Не удалось создать проект'
    },
    store: {
      apiMethod: 'createStore',
      loader: storesLoader,
      settingKey: 'target_store_id',
      modalRef: createStoreModalRef,
      modalShow: showCreateStoreModal,
      errorMsg: 'Не удалось создать склад'
    },
    salesChannel: {
      apiMethod: 'createSalesChannel',
      loader: salesChannelsLoader,
      settingKey: null,
      modalRef: createSalesChannelModalRef,
      modalShow: showCreateSalesChannelModal,
      errorMsg: 'Не удалось создать канал продаж'
    },
    customerOrderState: {
      apiMethod: 'createState',
      apiParams: ['customerorder'],
      loader: customerOrderStatesLoader,
      settingKey: 'customer_order_state_id',
      modalRef: createCustomerOrderStateModalRef,
      modalShow: showCreateCustomerOrderStateModal,
      errorMsg: 'Не удалось создать статус'
    },
    retailDemandState: {
      apiMethod: 'createState',
      apiParams: ['customerorder'],
      loader: customerOrderStatesLoader,
      settingKey: 'retail_demand_state_id',
      modalRef: createRetailDemandStateModalRef,
      modalShow: showCreateRetailDemandStateModal,
      errorMsg: 'Не удалось создать статус'
    },
    purchaseOrderState: {
      apiMethod: 'createState',
      apiParams: ['customerorder'],
      loaders: [customerOrderStatesLoader, purchaseOrderStatesLoader],
      settingKey: 'purchase_order_state_id',
      modalRef: createPurchaseOrderStateModalRef,
      modalShow: showCreatePurchaseOrderStateModal,
      errorMsg: 'Не удалось создать статус'
    }
  }

  const cfg = config[entityType]
  if (!cfg) {
    console.error(`Unknown entity type: ${entityType}`)
    return
  }

  try {
    cfg.modalRef.value?.setLoading(true)

    // Call API
    const apiArgs = [accountId.value, ...(cfg.apiParams || []), data]
    const response = await api.syncSettings[cfg.apiMethod](...apiArgs)
    const created = response.data.data

    // Add to loader(s)
    if (cfg.loaders) {
      cfg.loaders.forEach(loader => loader.addItem(created))
    } else {
      cfg.loader.addItem(created)
    }

    // Auto-select if settingKey provided
    if (cfg.settingKey) {
      settings.value[cfg.settingKey] = created.id
      metadataManager.updateMetadata(cfg.settingKey, created.id, created.name)
    }

    // Hide modal
    cfg.modalShow.value = false

  } catch (err) {
    console.error(`Failed to create ${entityType}:`, err)
    cfg.modalRef.value?.setError(err.response?.data?.error || cfg.errorMsg)
  } finally {
    cfg.modalRef.value?.setLoading(false)
  }
}

// Specific handlers (for template compatibility)
const handleProjectCreated = (data) => handleEntityCreated('project', data)
const handleStoreCreated = (data) => handleEntityCreated('store', data)
const handleSalesChannelCreated = (data) => handleEntityCreated('salesChannel', data)
const handleCustomerOrderStateCreated = (data) => handleEntityCreated('customerOrderState', data)
const handleRetailDemandStateCreated = (data) => handleEntityCreated('retailDemandState', data)
const handlePurchaseOrderStateCreated = (data) => handleEntityCreated('purchaseOrderState', data)

// VAT settings update handler
const handleVatSettingsUpdate = (vatSettings) => {
  settings.value.sync_vat = vatSettings.sync_vat
  settings.value.vat_sync_mode = vatSettings.vat_sync_mode
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

// Save settings (with proper data preparation)
const saveSettings = async () => {
  try {
    saving.value = true

    // Prepare settings with all data
    const dataToSave = {
      ...settings.value,
      price_mappings: priceMappingsManager.getMappingsForSave(),
      attribute_sync_list: selectedAttributes.value.length > 0 ? selectedAttributes.value : null,
      target_objects_meta: Object.keys(targetObjectsMeta.value).length > 0 ? targetObjectsMeta.value : null
    }

    // Save to API
    await api.syncSettings.update(accountId.value, dataToSave)

    // Show success message
    saveSuccess.value = true
    setTimeout(() => {
      saveSuccess.value = false
    }, 3000)

  } catch (err) {
    console.error('Failed to save settings:', err)

    // Show error
    const errorMessage = err.response?.data?.error || err.message
    error.value = 'Не удалось сохранить настройки: ' + errorMessage
    alert('Не удалось сохранить настройки: ' + errorMessage)

    throw err

  } finally {
    saving.value = false
  }
}

// Initialize component
onMounted(async () => {
  // Load settings with callback to load extended data
  await formManager.loadSettings(async () => {
    // Load extended data (priceTypes, attributes, folders) in parallel
    await settingsData.loadAll()
  })
})
</script>
