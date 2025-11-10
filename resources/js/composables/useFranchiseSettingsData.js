import { ref } from 'vue'
import api from '../api'

/**
 * Composable for loading franchise settings additional data
 * Manages loading of price types, attributes, and folders with request cancellation
 *
 * @param {Ref<string>} accountId - Account ID ref
 * @returns {object} { priceTypes, attributes, folders, loading states, loadAll(), cancelRequests() }
 */
export function useFranchiseSettingsData(accountId) {
  // Data refs
  const priceTypes = ref({ main: [], child: [] })
  const attributes = ref([])
  const folders = ref([])

  // Loading states
  const loadingPriceTypes = ref(false)
  const loadingAttributes = ref(false)
  const loadingFolders = ref(false)

  // Error states
  const priceTypesError = ref(null)
  const attributesError = ref(null)
  const foldersError = ref(null)

  // Request cancellation
  const abortController = ref(null)

  /**
   * Cancel all pending requests
   */
  const cancelRequests = () => {
    if (abortController.value) {
      abortController.value.abort()
      abortController.value = null
    }
  }

  /**
   * Load price types (both main and child account)
   */
  const loadPriceTypes = async (signal = null) => {
    try {
      loadingPriceTypes.value = true
      priceTypesError.value = null

      const response = await api.syncSettings.getPriceTypes(accountId.value, {
        signal
      })
      priceTypes.value = response.data

    } catch (err) {
      // Don't set error if request was cancelled
      if (err.name !== 'AbortError' && err.name !== 'CanceledError') {
        console.error('Failed to load price types:', err)
        priceTypesError.value = 'Не удалось загрузить типы цен'
      }
    } finally {
      loadingPriceTypes.value = false
    }
  }

  /**
   * Load attributes
   */
  const loadAttributes = async (signal = null) => {
    try {
      loadingAttributes.value = true
      attributesError.value = null

      const response = await api.syncSettings.getAttributes(accountId.value, {
        signal
      })
      attributes.value = response.data.data || []

    } catch (err) {
      if (err.name !== 'AbortError' && err.name !== 'CanceledError') {
        console.error('Failed to load attributes:', err)
        attributesError.value = 'Не удалось загрузить атрибуты'
      }
    } finally {
      loadingAttributes.value = false
    }
  }

  /**
   * Load folders (product groups)
   */
  const loadFolders = async (signal = null) => {
    try {
      loadingFolders.value = true
      foldersError.value = null

      const response = await api.syncSettings.getFolders(accountId.value, {
        signal
      })
      folders.value = response.data.data || []

    } catch (err) {
      if (err.name !== 'AbortError' && err.name !== 'CanceledError') {
        console.error('Failed to load folders:', err)
        foldersError.value = 'Не удалось загрузить группы товаров'
      }
    } finally {
      loadingFolders.value = false
    }
  }

  /**
   * Load all data in parallel with request cancellation support
   */
  const loadAll = async () => {
    // Cancel any pending requests
    cancelRequests()

    // Create new abort controller
    abortController.value = new AbortController()
    const { signal } = abortController.value

    // Load all data in parallel
    await Promise.all([
      loadPriceTypes(signal),
      loadAttributes(signal),
      loadFolders(signal)
    ])
  }

  /**
   * Check if any data is currently loading
   */
  const isLoading = () => {
    return loadingPriceTypes.value || loadingAttributes.value || loadingFolders.value
  }

  /**
   * Check if all data has errors
   */
  const hasErrors = () => {
    return !!(priceTypesError.value || attributesError.value || foldersError.value)
  }

  /**
   * Clear all errors
   */
  const clearErrors = () => {
    priceTypesError.value = null
    attributesError.value = null
    foldersError.value = null
  }

  return {
    // Data
    priceTypes,
    attributes,
    folders,

    // Loading states
    loadingPriceTypes,
    loadingAttributes,
    loadingFolders,

    // Error states
    priceTypesError,
    attributesError,
    foldersError,

    // Methods
    loadAll,
    loadPriceTypes,
    loadAttributes,
    loadFolders,
    cancelRequests,
    isLoading,
    hasErrors,
    clearErrors
  }
}
