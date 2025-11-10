import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

/**
 * Composable for managing franchise settings form
 * Handles loading, saving settings with proper data transformation
 *
 * @param {Ref<string>} accountId - Account ID ref
 * @param {object} deps - Dependencies { priceMappingsManager, selectedAttributes, targetObjectsMeta, metadataManager }
 * @returns {object} Form management interface
 */
export function useFranchiseSettingsForm(accountId, deps = {}) {
  const router = useRouter()

  // State
  const accountName = ref('')
  const loading = ref(false)
  const saving = ref(false)
  const error = ref(null)
  const saveSuccess = ref(false)

  // Settings with default values
  const settings = ref({
    sync_enabled: true,
    sync_products: true,
    sync_variants: true,
    sync_bundles: true,
    sync_services: true,
    sync_images: true,
    sync_images_all: false,
    sync_prices: true,
    sync_vat: false,
    vat_sync_mode: 'preserve_child',
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
    product_filters: { groups: [] },
    product_match_field: 'article',
    service_match_field: 'code',
    create_product_folders: true,
    price_mappings: null,
    attribute_sync_list: null,
    auto_create_attributes: true,
    auto_create_characteristics: true,
    auto_create_price_types: true
  })

  /**
   * Load settings from API
   * @param {Function} onDataLoaded - Optional callback after extended data loads
   */
  const loadSettings = async (onDataLoaded = null) => {
    if (!accountId.value) {
      error.value = 'ID аккаунта не указан'
      return
    }

    try {
      loading.value = true
      error.value = null

      // Load account info
      const accountResponse = await api.childAccounts.get(accountId.value)
      accountName.value = accountResponse.data.data.account_name || 'Без названия'

      // Load settings
      const response = await api.syncSettings.get(accountId.value)
      const loadedSettings = response.data.data

      // Populate settings
      Object.keys(settings.value).forEach(key => {
        if (loadedSettings[key] !== undefined) {
          // Special handling for product_filters - ensure it's always an object with groups array
          if (key === 'product_filters') {
            settings.value[key] = loadedSettings[key] || { groups: [] }
          } else {
            settings.value[key] = loadedSettings[key]
          }
        }
      })

      // Initialize price mappings if manager provided
      if (deps.priceMappingsManager && loadedSettings.price_mappings) {
        deps.priceMappingsManager.initializeMappings(loadedSettings.price_mappings)
      }

      // Initialize attribute sync list if ref provided
      if (deps.selectedAttributes && loadedSettings.attribute_sync_list) {
        deps.selectedAttributes.value = Array.isArray(loadedSettings.attribute_sync_list)
          ? loadedSettings.attribute_sync_list
          : []
      }

      // Initialize target objects metadata if manager provided
      if (deps.metadataManager && loadedSettings.target_objects_meta) {
        deps.metadataManager.initializeMetadata(loadedSettings.target_objects_meta)
      }

      // Call callback after settings loaded (for loading extended data)
      if (onDataLoaded && typeof onDataLoaded === 'function') {
        await onDataLoaded()
      }

    } catch (err) {
      console.error('Failed to load settings:', err)

      // Handle different error types
      if (err.response?.status === 404) {
        error.value = 'Аккаунт не найден или недоступен'
        // Redirect to Dashboard after 2 seconds
        setTimeout(() => {
          router.push('/app')
        }, 2000)
      } else if (err.response?.status === 401) {
        error.value = 'Сессия истекла. Перезагрузка страницы...'
        // Reload application
        setTimeout(() => {
          window.location.reload()
        }, 2000)
      } else {
        error.value = 'Не удалось загрузить настройки: ' + (err.response?.data?.error || err.message)
      }

      throw err

    } finally {
      loading.value = false
    }
  }

  /**
   * Prepare settings data for saving
   * Converts arrays and objects to proper format
   * @returns {object} Settings ready for API
   */
  const prepareSettingsForSave = () => {
    const preparedSettings = { ...settings.value }

    // Get price mappings from manager (null if empty)
    if (deps.priceMappingsManager) {
      preparedSettings.price_mappings = deps.priceMappingsManager.getMappingsForSave()
    }

    // Get attribute sync list (null if empty)
    if (deps.selectedAttributes) {
      preparedSettings.attribute_sync_list = deps.selectedAttributes.value.length > 0
        ? deps.selectedAttributes.value
        : null
    }

    // Get target objects metadata (null if empty)
    if (deps.targetObjectsMeta) {
      preparedSettings.target_objects_meta = Object.keys(deps.targetObjectsMeta.value).length > 0
        ? deps.targetObjectsMeta.value
        : null
    }

    return preparedSettings
  }

  /**
   * Save settings to API
   */
  const saveSettings = async () => {
    try {
      saving.value = true

      // Prepare data for saving (without mutating settings)
      const dataToSave = prepareSettingsForSave()

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

      // Also alert for immediate user feedback
      alert('Не удалось сохранить настройки: ' + errorMessage)

      throw err

    } finally {
      saving.value = false
    }
  }

  /**
   * Reset form to initial state
   */
  const resetForm = () => {
    accountName.value = ''
    loading.value = false
    saving.value = false
    error.value = null
    saveSuccess.value = false

    // Reset settings to defaults
    settings.value = {
      sync_enabled: true,
      sync_products: true,
      sync_variants: true,
      sync_bundles: true,
      sync_services: true,
      sync_images: true,
      sync_images_all: false,
      sync_prices: true,
      sync_vat: false,
      vat_sync_mode: 'preserve_child',
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
      product_filters: { groups: [] },
      product_match_field: 'article',
      service_match_field: 'code',
      create_product_folders: true,
      price_mappings: null,
      attribute_sync_list: null,
      auto_create_attributes: true,
      auto_create_characteristics: true,
      auto_create_price_types: true
    }
  }

  /**
   * Clear error
   */
  const clearError = () => {
    error.value = null
  }

  return {
    // State
    accountName,
    loading,
    saving,
    error,
    saveSuccess,
    settings,

    // Methods
    loadSettings,
    saveSettings,
    prepareSettingsForSave,
    resetForm,
    clearError
  }
}
