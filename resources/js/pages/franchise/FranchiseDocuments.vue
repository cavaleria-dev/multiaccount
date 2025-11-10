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
      <!-- Document Sync Section -->
      <DocumentSyncSection
        v-model:settings="settings"
        :organizations="organizations.items.value"
        :stores="stores.items.value"
        :projects="projects.items.value"
        :employees="employees.items.value"
        :sales-channels="salesChannels.items.value"
        :customer-order-states="customerOrderStates.items.value"
        :purchase-order-states="purchaseOrderStates.items.value"
        :loading-organizations="organizations.loading.value"
        :loading-stores="stores.loading.value"
        :loading-projects="projects.loading.value"
        :loading-employees="employees.loading.value"
        :loading-sales-channels="salesChannels.loading.value"
        :loading-customer-order-states="customerOrderStates.loading.value"
        :loading-purchase-order-states="purchaseOrderStates.loading.value"
        :organizations-error="organizations.error.value"
        :stores-error="stores.error.value"
        :projects-error="projects.error.value"
        :employees-error="employees.error.value"
        :sales-channels-error="salesChannels.error.value"
        :customer-order-states-error="customerOrderStates.error.value"
        :purchase-order-states-error="purchaseOrderStates.error.value"
        :target-objects-meta="metadata"
        @load-organizations="organizations.load()"
        @load-stores="stores.load()"
        @load-projects="projects.load()"
        @load-employees="employees.load()"
        @load-sales-channels="salesChannels.load()"
        @load-customer-order-states="customerOrderStates.load()"
        @load-purchase-order-states="purchaseOrderStates.load()"
        @create-customer-order-state="showCreateCustomerOrderStateModal = true"
        @create-retail-demand-state="showCreateRetailDemandStateModal = true"
        @create-purchase-order-state="showCreatePurchaseOrderStateModal = true"
        @create-sales-channel="showCreateSalesChannelModal = true"
        @create-store="showCreateStoreModal = true"
        @create-project="showCreateProjectModal = true"
        @clear-target-store="clearMetadata('target_store_id')"
        @clear-target-project="clearMetadata('target_project_id')"
        @clear-responsible-employee="clearMetadata('responsible_employee_id')"
        @clear-customer-order-state="clearMetadata('customer_order_state_id')"
        @clear-customer-order-sales-channel="clearMetadata('customer_order_sales_channel_id')"
        @clear-retail-demand-state="clearMetadata('retail_demand_state_id')"
        @clear-retail-demand-sales-channel="clearMetadata('retail_demand_sales_channel_id')"
        @clear-purchase-order-state="clearMetadata('purchase_order_state_id')"
        @clear-purchase-order-sales-channel="clearMetadata('purchase_order_sales_channel_id')"
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

    <!-- Modals -->
    <CreateProjectModal
      v-model="showCreateProjectModal"
      :account-id="accountId"
      @created="handleProjectCreated"
    />

    <CreateStoreModal
      v-model="showCreateStoreModal"
      :account-id="accountId"
      @created="handleStoreCreated"
    />

    <CreateSalesChannelModal
      v-model="showCreateSalesChannelModal"
      :account-id="accountId"
      @created="handleSalesChannelCreated"
    />

    <CreateStateModal
      v-model="showCreateCustomerOrderStateModal"
      :account-id="accountId"
      entity-type="customerorder"
      @created="handleCustomerOrderStateCreated"
    />

    <CreateStateModal
      v-model="showCreateRetailDemandStateModal"
      :account-id="accountId"
      entity-type="customerorder"
      @created="handleRetailDemandStateCreated"
    />

    <CreateStateModal
      v-model="showCreatePurchaseOrderStateModal"
      :account-id="accountId"
      entity-type="purchaseorder"
      @created="handlePurchaseOrderStateCreated"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'
import { useMoyskladEntities } from '../../composables/useMoyskladEntities'
import { useTargetObjectsMetadata } from '../../composables/useTargetObjectsMetadata'
import DocumentSyncSection from '../../components/franchise-settings/DocumentSyncSection.vue'
import CreateProjectModal from '../../components/CreateProjectModal.vue'
import CreateStoreModal from '../../components/CreateStoreModal.vue'
import CreateSalesChannelModal from '../../components/CreateSalesChannelModal.vue'
import CreateStateModal from '../../components/CreateStateModal.vue'

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
  sync_customer_orders: false,
  sync_retail_demands: false,
  sync_purchase_orders: false,
  target_organization_id: null,
  target_store_id: null,
  target_project_id: null,
  responsible_employee_id: null,
  customer_order_state_id: null,
  customer_order_sales_channel_id: null,
  retail_demand_state_id: null,
  retail_demand_sales_channel_id: null,
  purchase_order_state_id: null,
  purchase_order_sales_channel_id: null
})

// Entity loaders
const organizations = useMoyskladEntities(props.accountId, 'organizations')
const stores = useMoyskladEntities(props.accountId, 'stores')
const projects = useMoyskladEntities(props.accountId, 'projects')
const employees = useMoyskladEntities(props.accountId, 'employees')
const salesChannels = useMoyskladEntities(props.accountId, 'salesChannels')
const customerOrderStates = useMoyskladEntities(props.accountId, 'customerOrderStates')
const purchaseOrderStates = useMoyskladEntities(props.accountId, 'purchaseOrderStates')

// Metadata management
const { metadata, updateMetadata, clearMetadata: clearMeta, initializeMetadata } = useTargetObjectsMetadata(
  settings,
  {
    organizations: organizations.items,
    stores: stores.items,
    projects: projects.items,
    employees: employees.items,
    salesChannels: salesChannels.items,
    customerOrderStates: customerOrderStates.items,
    purchaseOrderStates: purchaseOrderStates.items
  }
)

// Modal state
const showCreateProjectModal = ref(false)
const showCreateStoreModal = ref(false)
const showCreateSalesChannelModal = ref(false)
const showCreateCustomerOrderStateModal = ref(false)
const showCreateRetailDemandStateModal = ref(false)
const showCreatePurchaseOrderStateModal = ref(false)

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

    // Initialize metadata
    if (data.target_objects_meta) {
      initializeMetadata(data.target_objects_meta)
    }

  } catch (err) {
    console.error('Failed to load settings:', err)
    error.value = 'Не удалось загрузить настройки'
  } finally {
    loading.value = false
  }
}

// Clear metadata wrapper
const clearMetadata = (field) => {
  clearMeta(field)
}

// Save settings
const saveSettings = async () => {
  try {
    saving.value = true
    error.value = null

    const data = {
      ...settings.value,
      target_objects_meta: metadata.value
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

// Modal handlers
const handleProjectCreated = (project) => {
  projects.addItem(project)
  showCreateProjectModal.value = false
}

const handleStoreCreated = (store) => {
  stores.addItem(store)
  showCreateStoreModal.value = false
}

const handleSalesChannelCreated = (channel) => {
  salesChannels.addItem(channel)
  showCreateSalesChannelModal.value = false
}

const handleCustomerOrderStateCreated = (state) => {
  customerOrderStates.addItem(state)
  showCreateCustomerOrderStateModal.value = false
}

const handleRetailDemandStateCreated = (state) => {
  customerOrderStates.addItem(state)
  showCreateRetailDemandStateModal.value = false
}

const handlePurchaseOrderStateCreated = (state) => {
  purchaseOrderStates.addItem(state)
  showCreatePurchaseOrderStateModal.value = false
}

onMounted(() => {
  loadSettings()
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
