<template>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <!-- Синхронизация документов (left column) -->
    <div class="bg-white shadow rounded-lg p-5">
      <h3 class="text-base font-medium text-gray-900 mb-3">Синхронизация документов</h3>
      <div class="space-y-3">
        <Toggle
          :model-value="settings.sync_customer_orders"
          @update:model-value="updateSetting('sync_customer_orders', $event)"
          label="Заказы покупателей"
          description="Синхронизировать заказы покупателей из дочернего в главный"
          size="small"
          color="purple"
        />

        <div v-if="settings.sync_customer_orders" class="ml-7 space-y-2">
          <SearchableSelect
            :model-value="settings.customer_order_state_id"
            @update:model-value="updateSetting('customer_order_state_id', $event)"
            label="Статус заказа"
            placeholder="Выберите статус"
            :options="customerOrderStates"
            :loading="loadingCustomerOrderStates"
            :error="customerOrderStatesError"
            :initial-name="targetObjectsMeta?.customer_order_state_id?.name"
            :can-create="true"
            :show-color="true"
            @open="$emit('load-customer-order-states')"
            @create="$emit('create-customer-order-state')"
            @clear="$emit('clear-customer-order-state')"
          />
          <SearchableSelect
            :model-value="settings.customer_order_sales_channel_id"
            @update:model-value="updateSetting('customer_order_sales_channel_id', $event)"
            label="Канал продаж"
            placeholder="Выберите канал продаж"
            :options="salesChannels"
            :loading="loadingSalesChannels"
            :error="salesChannelsError"
            :initial-name="targetObjectsMeta?.customer_order_sales_channel_id?.name"
            :can-create="true"
            @open="$emit('load-sales-channels')"
            @create="$emit('create-sales-channel')"
            @clear="$emit('clear-customer-order-sales-channel')"
          />
        </div>

        <Toggle
          :model-value="settings.sync_retail_demands"
          @update:model-value="updateSetting('sync_retail_demands', $event)"
          label="Розничные продажи"
          description="Синхронизировать розничные продажи из дочернего в главный"
          size="small"
          color="purple"
        />

        <div v-if="settings.sync_retail_demands" class="ml-7 space-y-2">
          <SearchableSelect
            :model-value="settings.retail_demand_state_id"
            @update:model-value="updateSetting('retail_demand_state_id', $event)"
            label="Статус розничной продажи"
            placeholder="Выберите статус"
            :options="customerOrderStates"
            :loading="loadingCustomerOrderStates"
            :error="customerOrderStatesError"
            :initial-name="targetObjectsMeta?.retail_demand_state_id?.name"
            :can-create="true"
            :show-color="true"
            @open="$emit('load-customer-order-states')"
            @create="$emit('create-retail-demand-state')"
            @clear="$emit('clear-retail-demand-state')"
          />
          <SearchableSelect
            :model-value="settings.retail_demand_sales_channel_id"
            @update:model-value="updateSetting('retail_demand_sales_channel_id', $event)"
            label="Канал продаж"
            placeholder="Выберите канал продаж"
            :options="salesChannels"
            :loading="loadingSalesChannels"
            :error="salesChannelsError"
            :initial-name="targetObjectsMeta?.retail_demand_sales_channel_id?.name"
            :can-create="true"
            @open="$emit('load-sales-channels')"
            @create="$emit('create-sales-channel')"
            @clear="$emit('clear-retail-demand-sales-channel')"
          />
        </div>

        <Toggle
          :model-value="settings.sync_purchase_orders"
          @update:model-value="updateSetting('sync_purchase_orders', $event)"
          label="Заказы поставщику"
          description="Синхронизировать заказы поставщику из дочернего в главный"
          size="small"
          color="purple"
        />

        <div v-if="settings.sync_purchase_orders" class="ml-7 space-y-2">
          <SearchableSelect
            :model-value="settings.purchase_order_state_id"
            @update:model-value="updateSetting('purchase_order_state_id', $event)"
            label="Статус заказа поставщику"
            placeholder="Выберите статус"
            :options="purchaseOrderStates"
            :loading="loadingPurchaseOrderStates"
            :error="purchaseOrderStatesError"
            :initial-name="targetObjectsMeta?.purchase_order_state_id?.name"
            :can-create="true"
            :show-color="true"
            @open="$emit('load-purchase-order-states')"
            @create="$emit('create-purchase-order-state')"
            @clear="$emit('clear-purchase-order-state')"
          />
          <SearchableSelect
            :model-value="settings.purchase_order_sales_channel_id"
            @update:model-value="updateSetting('purchase_order_sales_channel_id', $event)"
            label="Канал продаж для заказов поставщику"
            placeholder="Выберите канал продаж"
            :options="salesChannels"
            :loading="loadingSalesChannels"
            :error="salesChannelsError"
            :initial-name="targetObjectsMeta?.purchase_order_sales_channel_id?.name"
            :can-create="true"
            @open="$emit('load-sales-channels')"
            @create="$emit('create-sales-channel')"
            @clear="$emit('clear-purchase-order-sales-channel')"
          />
          <div class="bg-yellow-50 border border-yellow-200 rounded-md p-2">
            <p class="text-xs text-yellow-800">
              <strong>⚠️ Примечание:</strong> ID контрагента-поставщика (supplier_counterparty_id) в данный момент не поддерживает выбор через интерфейс и должен быть настроен в базе данных.
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Целевые объекты в главном аккаунте (right column) -->
    <div class="bg-white shadow rounded-lg p-5">
      <h3 class="text-base font-medium text-gray-900 mb-3">Целевые объекты в главном аккаунте</h3>
      <div class="space-y-3">
      <SearchableSelect
        :model-value="settings.target_organization_id"
        @update:model-value="updateSetting('target_organization_id', $event)"
        label="Организация"
        placeholder="Выберите организацию"
        :options="organizations"
        :loading="loadingOrganizations"
        :error="organizationsError"
        :initial-name="targetObjectsMeta?.target_organization_id?.name"
        required
        @open="$emit('load-organizations')"
      />
      <p class="mt-1 text-xs text-gray-500">Организация для создаваемых документов</p>

      <SearchableSelect
        :model-value="settings.target_store_id"
        @update:model-value="updateSetting('target_store_id', $event)"
        label="Склад"
        placeholder="Выберите склад"
        :options="stores"
        :loading="loadingStores"
        :error="storesError"
        :initial-name="targetObjectsMeta?.target_store_id?.name"
        :can-create="true"
        @open="$emit('load-stores')"
        @create="$emit('create-store')"
        @clear="$emit('clear-target-store')"
      />
      <p class="mt-1 text-xs text-gray-500">Склад для создаваемых документов (опционально)</p>

      <SearchableSelect
        :model-value="settings.target_project_id"
        @update:model-value="updateSetting('target_project_id', $event)"
        label="Проект"
        placeholder="Выберите проект"
        :options="projects"
        :loading="loadingProjects"
        :error="projectsError"
        :initial-name="targetObjectsMeta?.target_project_id?.name"
        :can-create="true"
        @open="$emit('load-projects')"
        @create="$emit('create-project')"
        @clear="$emit('clear-target-project')"
      />
      <p class="mt-1 text-xs text-gray-500">Проект для создаваемых документов (опционально)</p>

      <SearchableSelect
        :model-value="settings.responsible_employee_id"
        @update:model-value="updateSetting('responsible_employee_id', $event)"
        label="Ответственный сотрудник"
        placeholder="Выберите сотрудника"
        :options="employees"
        :loading="loadingEmployees"
        :error="employeesError"
        :initial-name="targetObjectsMeta?.responsible_employee_id?.name"
        @open="$emit('load-employees')"
        @clear="$emit('clear-responsible-employee')"
      />
      <p class="mt-1 text-xs text-gray-500">Ответственный за создаваемые документы</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import SearchableSelect from '../SearchableSelect.vue'
import Toggle from '../Toggle.vue'

const props = defineProps({
  settings: {
    type: Object,
    required: true
  },
  organizations: {
    type: Array,
    required: true
  },
  stores: {
    type: Array,
    required: true
  },
  projects: {
    type: Array,
    required: true
  },
  employees: {
    type: Array,
    required: true
  },
  salesChannels: {
    type: Array,
    required: true
  },
  customerOrderStates: {
    type: Array,
    required: true
  },
  purchaseOrderStates: {
    type: Array,
    required: true
  },
  loadingOrganizations: {
    type: Boolean,
    default: false
  },
  loadingStores: {
    type: Boolean,
    default: false
  },
  loadingProjects: {
    type: Boolean,
    default: false
  },
  loadingEmployees: {
    type: Boolean,
    default: false
  },
  loadingSalesChannels: {
    type: Boolean,
    default: false
  },
  loadingCustomerOrderStates: {
    type: Boolean,
    default: false
  },
  loadingPurchaseOrderStates: {
    type: Boolean,
    default: false
  },
  organizationsError: {
    type: String,
    default: null
  },
  storesError: {
    type: String,
    default: null
  },
  projectsError: {
    type: String,
    default: null
  },
  employeesError: {
    type: String,
    default: null
  },
  salesChannelsError: {
    type: String,
    default: null
  },
  customerOrderStatesError: {
    type: String,
    default: null
  },
  purchaseOrderStatesError: {
    type: String,
    default: null
  },
  targetObjectsMeta: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits([
  'update:settings',
  'load-organizations',
  'load-stores',
  'load-projects',
  'load-employees',
  'load-sales-channels',
  'load-customer-order-states',
  'load-purchase-order-states',
  'create-customer-order-state',
  'create-retail-demand-state',
  'create-purchase-order-state',
  'create-sales-channel',
  'create-store',
  'create-project',
  'clear-customer-order-state',
  'clear-customer-order-sales-channel',
  'clear-retail-demand-state',
  'clear-retail-demand-sales-channel',
  'clear-purchase-order-state',
  'clear-purchase-order-sales-channel',
  'clear-target-store',
  'clear-target-project',
  'clear-responsible-employee'
])

const updateSetting = (key, value) => {
  emit('update:settings', {
    ...props.settings,
    [key]: value
  })
}
</script>
