import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
})

// Добавить contextKey в заголовки всех запросов
api.interceptors.request.use(
  config => {
    const contextKey = sessionStorage.getItem('moysklad_context_key')
    if (contextKey) {
      config.headers['X-MoySklad-Context-Key'] = contextKey
    }
    return config
  },
  error => Promise.reject(error)
)

// Обработка ошибок
api.interceptors.response.use(
  response => response,
  error => {
    console.error('API Error:', error)
    if (error.response?.status === 401) {
      console.error('Context expired, please reload')
    }
    return Promise.reject(error)
  }
)

export default {
  // Дочерние аккаунты
  childAccounts: {
    list() {
      return api.get('/child-accounts')
    },
    get(accountId) {
      return api.get(`/child-accounts/${accountId}`)
    },
    create(data) {
      return api.post('/child-accounts', data)
    },
    update(accountId, data) {
      return api.put(`/child-accounts/${accountId}`, data)
    },
    delete(accountId) {
      return api.delete(`/child-accounts/${accountId}`)
    },
    available() {
      return api.get('/child-accounts-available')
    },
    checkAvailability(accountId) {
      return api.get(`/child-accounts-check/${accountId}`)
    }
  },

  // Настройки синхронизации
  syncSettings: {
    get(accountId) {
      return api.get(`/sync-settings/${accountId}`)
    },
    getBatch(accountId) {
      return api.get(`/sync-settings/${accountId}/batch`)
    },
    update(accountId, data) {
      return api.put(`/sync-settings/${accountId}`, data)
    },
    getPriceTypes(accountId) {
      return api.get(`/sync-settings/${accountId}/price-types`)
    },
    createPriceType(accountId, data) {
      return api.post(`/sync-settings/${accountId}/price-types`, data)
    },
    getAttributes(accountId) {
      return api.get(`/sync-settings/${accountId}/attributes`)
    },
    getFolders(accountId) {
      return api.get(`/sync-settings/${accountId}/folders`)
    },
    // Справочники для целевых объектов
    getOrganizations(accountId) {
      return api.get(`/sync-settings/${accountId}/organizations`)
    },
    getStores(accountId) {
      return api.get(`/sync-settings/${accountId}/stores`)
    },
    getProjects(accountId) {
      return api.get(`/sync-settings/${accountId}/projects`)
    },
    getEmployees(accountId) {
      return api.get(`/sync-settings/${accountId}/employees`)
    },
    getSalesChannels(accountId) {
      return api.get(`/sync-settings/${accountId}/sales-channels`)
    },
    getStates(accountId, entityType) {
      return api.get(`/sync-settings/${accountId}/states/${entityType}`)
    },
    // Создание целевых объектов
    createProject(accountId, data) {
      return api.post(`/sync-settings/${accountId}/projects`, data)
    },
    createStore(accountId, data) {
      return api.post(`/sync-settings/${accountId}/stores`, data)
    },
    createSalesChannel(accountId, data) {
      return api.post(`/sync-settings/${accountId}/sales-channels`, data)
    },
    createState(accountId, entityType, data) {
      return api.post(`/sync-settings/${accountId}/states/${entityType}`, data)
    }
  },

  // Действия синхронизации
  syncActions: {
    syncAllProducts(accountId) {
      return api.post(`/sync/${accountId}/products/all`)
    }
  },

  // Статистика
  stats: {
    dashboard() {
      return api.get('/stats/dashboard')
    },
    childAccount(accountId) {
      return api.get(`/stats/child-account/${accountId}`)
    }
  },

  // Логи синхронизации
  syncLogs: {
    list(params) {
      return api.get('/sync-logs', { params })
    }
  }
}
