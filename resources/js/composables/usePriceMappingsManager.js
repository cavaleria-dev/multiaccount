import { ref } from 'vue'
import api from '../api'

/**
 * Composable for managing price type mappings
 * Handles adding, removing mappings and creating new price types
 *
 * @param {Ref<string>} accountId - Account ID ref
 * @param {Ref<object>} priceTypes - Price types object { main: [], child: [] }
 * @returns {object} Price mappings management interface
 */
export function usePriceMappingsManager(accountId, priceTypes) {
  // Price mappings array
  const priceMappings = ref([])

  // Create price type form state
  const creatingPriceTypeForIndex = ref(null)
  const newPriceTypeName = ref('')
  const creatingPriceType = ref(false)
  const createPriceTypeError = ref(null)

  /**
   * Add a new empty price mapping
   */
  const addPriceMapping = () => {
    priceMappings.value.push({
      main_price_type_id: '',
      child_price_type_id: ''
    })
  }

  /**
   * Remove a price mapping by index
   * @param {number} index - Index of mapping to remove
   */
  const removePriceMapping = (index) => {
    priceMappings.value.splice(index, 1)
  }

  /**
   * Show create price type form for specific mapping
   * @param {number} index - Mapping index
   */
  const showCreatePriceTypeForm = (index) => {
    creatingPriceTypeForIndex.value = index
    newPriceTypeName.value = ''
    createPriceTypeError.value = null
  }

  /**
   * Hide create price type form
   */
  const hideCreatePriceTypeForm = () => {
    creatingPriceTypeForIndex.value = null
    newPriceTypeName.value = ''
    createPriceTypeError.value = null
  }

  /**
   * Create a new price type in child account
   * @param {number} index - Mapping index to auto-select created price type
   */
  const createNewPriceType = async (index) => {
    // Validation
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

      // Add to child price types list
      if (priceTypes.value.child) {
        priceTypes.value.child.push({
          id: createdPriceType.id,
          name: createdPriceType.name
        })
      }

      // Auto-select created price type in the mapping
      if (index !== null && priceMappings.value[index]) {
        priceMappings.value[index].child_price_type_id = createdPriceType.id
      }

      // Hide form
      hideCreatePriceTypeForm()

      return createdPriceType

    } catch (err) {
      console.error('Failed to create price type:', err)

      // Handle specific errors
      if (err.response?.status === 409) {
        createPriceTypeError.value = 'Тип цены с таким названием уже существует'
      } else {
        createPriceTypeError.value = err.response?.data?.error || 'Не удалось создать тип цены'
      }

      throw err

    } finally {
      creatingPriceType.value = false
    }
  }

  /**
   * Initialize price mappings from loaded settings
   * @param {array} loadedMappings - Price mappings array from API
   */
  const initializeMappings = (loadedMappings) => {
    if (loadedMappings && Array.isArray(loadedMappings)) {
      priceMappings.value = loadedMappings
    } else {
      priceMappings.value = []
    }
  }

  /**
   * Get price mappings prepared for saving (null if empty)
   * @returns {array|null}
   */
  const getMappingsForSave = () => {
    return priceMappings.value.length > 0 ? priceMappings.value : null
  }

  /**
   * Clear all mappings
   */
  const clearMappings = () => {
    priceMappings.value = []
  }

  /**
   * Validate mappings
   * @returns {object} { valid: boolean, errors: array }
   */
  const validateMappings = () => {
    const errors = []

    priceMappings.value.forEach((mapping, index) => {
      if (!mapping.main_price_type_id && mapping.child_price_type_id) {
        errors.push(`Маппинг ${index + 1}: не выбран тип цены главного аккаунта`)
      }
      if (mapping.main_price_type_id && !mapping.child_price_type_id) {
        errors.push(`Маппинг ${index + 1}: не выбран тип цены дочернего аккаунта`)
      }
    })

    return {
      valid: errors.length === 0,
      errors
    }
  }

  return {
    // Data
    priceMappings,

    // Create form state
    creatingPriceTypeForIndex,
    newPriceTypeName,
    creatingPriceType,
    createPriceTypeError,

    // Methods
    addPriceMapping,
    removePriceMapping,
    showCreatePriceTypeForm,
    hideCreatePriceTypeForm,
    createNewPriceType,
    initializeMappings,
    getMappingsForSave,
    clearMappings,
    validateMappings
  }
}
