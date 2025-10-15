import { ref, watch } from 'vue'

/**
 * Composable for managing target objects metadata
 * Automatically watches settings fields and updates metadata when selections change
 *
 * @param {Ref} settings - Settings reactive object
 * @param {object} entities - Object containing entity arrays { organizations, stores, projects, employees, customerOrderStates, salesChannels, purchaseOrderStates }
 * @returns {object} { metadata, updateMetadata, clearMetadata }
 */
export function useTargetObjectsMetadata(settings, entities) {
  const metadata = ref({})

  // Configuration for fields to watch
  const fieldConfigs = [
    { field: 'target_organization_id', entityList: 'organizations' },
    { field: 'target_store_id', entityList: 'stores' },
    { field: 'target_project_id', entityList: 'projects' },
    { field: 'responsible_employee_id', entityList: 'employees' },
    { field: 'customer_order_state_id', entityList: 'customerOrderStates' },
    { field: 'customer_order_sales_channel_id', entityList: 'salesChannels' },
    { field: 'retail_demand_state_id', entityList: 'customerOrderStates' },
    { field: 'retail_demand_sales_channel_id', entityList: 'salesChannels' },
    { field: 'purchase_order_state_id', entityList: 'purchaseOrderStates' },
    { field: 'purchase_order_sales_channel_id', entityList: 'salesChannels' }
  ]

  /**
   * Update metadata for a specific field
   * @param {string} fieldName - Field name
   * @param {string|null} id - Entity ID
   * @param {string|null} name - Entity name
   */
  const updateMetadata = (fieldName, id, name) => {
    if (!metadata.value) {
      metadata.value = {}
    }

    if (id && name) {
      metadata.value[fieldName] = { id, name }
    } else {
      delete metadata.value[fieldName]
    }
  }

  /**
   * Clear metadata for a specific field
   * @param {string} fieldName - Field name to clear
   */
  const clearMetadata = (fieldName) => {
    if (metadata.value && metadata.value[fieldName]) {
      delete metadata.value[fieldName]
    }
  }

  /**
   * Clear all metadata
   */
  const clearAllMetadata = () => {
    metadata.value = {}
  }

  /**
   * Initialize metadata from loaded settings
   * @param {object} loadedMetadata - Metadata object from API
   */
  const initializeMetadata = (loadedMetadata) => {
    metadata.value = loadedMetadata || {}
  }

  /**
   * Get metadata for a specific field
   * @param {string} fieldName - Field name
   * @returns {object|null} Metadata object { id, name } or null
   */
  const getMetadata = (fieldName) => {
    return metadata.value?.[fieldName] || null
  }

  // Setup watchers for all configured fields
  fieldConfigs.forEach(({ field, entityList }) => {
    watch(
      () => settings.value[field],
      (newValue) => {
        if (!newValue) {
          clearMetadata(field)
          return
        }

        const entityArray = entities[entityList]
        if (!entityArray || !entityArray.value) {
          console.warn(`Entity list ${entityList} not found or not reactive`)
          return
        }

        const entity = entityArray.value.find(item => item.id === newValue)
        if (entity) {
          updateMetadata(field, entity.id, entity.name)
        }
      },
      { immediate: false } // Don't run on mount, only when value changes
    )
  })

  return {
    metadata,
    updateMetadata,
    clearMetadata,
    clearAllMetadata,
    initializeMetadata,
    getMetadata
  }
}
