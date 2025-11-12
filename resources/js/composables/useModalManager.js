import { ref } from 'vue'

/**
 * Composable for managing multiple modals with unified interface
 * Replaces multiple show* and *Ref variables with structured object
 *
 * @returns {object} Modal management interface
 */
export function useModalManager() {
  // Modal types configuration
  const modalTypes = [
    'project',
    'store',
    'salesChannel',
    'priceType',
    'customerOrderState',
    'retailDemandState',
    'purchaseOrderState'
  ]

  // Modal state (show/hide) - using individual refs
  const modals = {}

  // Modal refs - using individual refs
  const modalRefs = {}

  // Initialize modals with individual refs (not inside reactive)
  modalTypes.forEach(type => {
    modals[type] = ref(false)
    modalRefs[type] = ref(null)
  })

  /**
   * Show specific modal
   * @param {string} modalType - Modal type (project, store, etc.)
   */
  const show = (modalType) => {
    if (modals[modalType]) {
      modals[modalType].value = true
    } else {
      console.warn(`Unknown modal type: ${modalType}`)
    }
  }

  /**
   * Hide specific modal
   * @param {string} modalType - Modal type
   */
  const hide = (modalType) => {
    if (modals[modalType]) {
      modals[modalType].value = false
    }
  }

  /**
   * Hide all modals
   */
  const hideAll = () => {
    modalTypes.forEach(type => {
      modals[type].value = false
    })
  }

  /**
   * Check if any modal is open
   * @returns {boolean}
   */
  const isAnyOpen = () => {
    return modalTypes.some(type => modals[type].value === true)
  }

  /**
   * Get ref for specific modal
   * @param {string} modalType - Modal type
   * @returns {Ref}
   */
  const getRef = (modalType) => {
    return modalRefs[modalType]
  }

  /**
   * Set loading state for specific modal
   * @param {string} modalType - Modal type
   * @param {boolean} loading - Loading state
   */
  const setLoading = (modalType, loading) => {
    const ref = modalRefs[modalType]?.value
    if (ref && typeof ref.setLoading === 'function') {
      ref.setLoading(loading)
    }
  }

  /**
   * Set error for specific modal
   * @param {string} modalType - Modal type
   * @param {string} error - Error message
   */
  const setError = (modalType, error) => {
    const ref = modalRefs[modalType]?.value
    if (ref && typeof ref.setError === 'function') {
      ref.setError(error)
    }
  }

  /**
   * Clear error for specific modal
   * @param {string} modalType - Modal type
   */
  const clearError = (modalType) => {
    const ref = modalRefs[modalType]?.value
    if (ref && typeof ref.setError === 'function') {
      ref.setError(null)
    }
  }

  // Computed properties for backward compatibility
  // These can be used directly in template as v-model
  const showCreateProjectModal = modals.project
  const showCreateStoreModal = modals.store
  const showCreateSalesChannelModal = modals.salesChannel
  const showCreatePriceTypeModal = modals.priceType
  const showCreateCustomerOrderStateModal = modals.customerOrderState
  const showCreateRetailDemandStateModal = modals.retailDemandState
  const showCreatePurchaseOrderStateModal = modals.purchaseOrderState

  const createProjectModalRef = modalRefs.project
  const createStoreModalRef = modalRefs.store
  const createSalesChannelModalRef = modalRefs.salesChannel
  const createPriceTypeModalRef = modalRefs.priceType
  const createCustomerOrderStateModalRef = modalRefs.customerOrderState
  const createRetailDemandStateModalRef = modalRefs.retailDemandState
  const createPurchaseOrderStateModalRef = modalRefs.purchaseOrderState

  return {
    // Core API
    modals,
    modalRefs,
    show,
    hide,
    hideAll,
    isAnyOpen,
    getRef,
    setLoading,
    setError,
    clearError,

    // Backward compatibility refs (for template usage)
    showCreateProjectModal,
    showCreateStoreModal,
    showCreateSalesChannelModal,
    showCreatePriceTypeModal,
    showCreateCustomerOrderStateModal,
    showCreateRetailDemandStateModal,
    showCreatePurchaseOrderStateModal,

    createProjectModalRef,
    createStoreModalRef,
    createSalesChannelModalRef,
    createPriceTypeModalRef,
    createCustomerOrderStateModalRef,
    createRetailDemandStateModalRef,
    createPurchaseOrderStateModalRef
  }
}
