import { ref } from 'vue'
import api from '../api'

/**
 * Universal composable for loading МойСклад entities with caching
 *
 * @param {string} accountId - Account ID
 * @param {string} entityType - Entity type (organizations, stores, projects, employees, salesChannels, customerOrderStates, purchaseOrderStates, attributes, folders, priceTypes)
 * @returns {object} { items, loading, error, load, reload, clear }
 */
export function useMoyskladEntities(accountId, entityType) {
  const items = ref([])
  const loading = ref(false)
  const error = ref(null)
  const loaded = ref(false)

  // Map entity types to API methods
  const apiMethods = {
    organizations: () => api.syncSettings.getOrganizations(accountId),
    stores: () => api.syncSettings.getStores(accountId),
    projects: () => api.syncSettings.getProjects(accountId),
    employees: () => api.syncSettings.getEmployees(accountId),
    salesChannels: () => api.syncSettings.getSalesChannels(accountId),
    customerOrderStates: () => api.syncSettings.getStates(accountId, 'customerorder'),
    purchaseOrderStates: () => api.syncSettings.getStates(accountId, 'customerorder'), // Same as customerOrderStates
    attributes: () => api.syncSettings.getAttributes(accountId),
    folders: () => api.syncSettings.getFolders(accountId),
    priceTypes: () => api.syncSettings.getPriceTypes(accountId)
  }

  // Error messages map
  const errorMessages = {
    organizations: 'Не удалось загрузить организации',
    stores: 'Не удалось загрузить склады',
    projects: 'Не удалось загрузить проекты',
    employees: 'Не удалось загрузить сотрудников',
    salesChannels: 'Не удалось загрузить каналы продаж',
    customerOrderStates: 'Не удалось загрузить статусы заказов',
    purchaseOrderStates: 'Не удалось загрузить статусы заказов',
    attributes: 'Не удалось загрузить атрибуты',
    folders: 'Не удалось загрузить группы товаров',
    priceTypes: 'Не удалось загрузить типы цен'
  }

  /**
   * Load entities with caching
   * @param {boolean} force - Force reload even if already loaded
   */
  const load = async (force = false) => {
    // Skip if already loaded and not forcing
    if (loaded.value && !force && items.value.length > 0) {
      return
    }

    const apiMethod = apiMethods[entityType]
    if (!apiMethod) {
      console.error(`Unknown entity type: ${entityType}`)
      error.value = `Неизвестный тип сущности: ${entityType}`
      return
    }

    try {
      loading.value = true
      error.value = null

      const response = await apiMethod()

      // Handle different response formats
      if (entityType === 'priceTypes') {
        // priceTypes returns { main: [...], child: [...] }
        items.value = response.data
      } else {
        // Other entities return { data: [...] }
        items.value = response.data.data || []
      }

      loaded.value = true

    } catch (err) {
      console.error(`Failed to load ${entityType}:`, err)
      error.value = errorMessages[entityType] || `Не удалось загрузить ${entityType}`
    } finally {
      loading.value = false
    }
  }

  /**
   * Force reload entities
   */
  const reload = async () => {
    await load(true)
  }

  /**
   * Clear cached data
   */
  const clear = () => {
    items.value = []
    error.value = null
    loaded.value = false
  }

  /**
   * Add new item to the list
   * @param {object} item - New item to add
   */
  const addItem = (item) => {
    if (entityType === 'priceTypes') {
      console.warn('Cannot add items to priceTypes using addItem. Use addChildPriceType instead.')
      return
    }

    // Avoid duplicates
    if (!items.value.find(i => i.id === item.id)) {
      items.value.push(item)
    }
  }

  /**
   * Add new price type to child account
   * @param {object} priceType - New price type to add
   */
  const addChildPriceType = (priceType) => {
    if (entityType !== 'priceTypes') {
      console.warn('addChildPriceType can only be used with priceTypes entity type')
      return
    }

    if (!items.value.child) {
      items.value.child = []
    }

    // Avoid duplicates
    if (!items.value.child.find(pt => pt.id === priceType.id)) {
      items.value.child.push(priceType)
    }
  }

  return {
    items,
    loading,
    error,
    loaded,
    load,
    reload,
    clear,
    addItem,
    addChildPriceType
  }
}
