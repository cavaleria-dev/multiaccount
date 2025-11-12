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
   * Update child price type for specific mapping
   * Used when a new price type is created
   * @param {number} index - Mapping index
   * @param {string} priceTypeId - Price type ID
   */
  const updateMappingChildPriceType = (index, priceTypeId) => {
    if (priceMappings.value[index]) {
      priceMappings.value[index].child_price_type_id = priceTypeId
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

    // Methods
    addPriceMapping,
    removePriceMapping,
    updateMappingChildPriceType,
    initializeMappings,
    getMappingsForSave,
    clearMappings,
    validateMappings
  }
}
