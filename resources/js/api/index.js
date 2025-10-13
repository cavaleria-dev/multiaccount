import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
})

// Обработка ошибок
api.interceptors.response.use(
  response => response,
  error => {
    console.error('API Error:', error)
    return Promise.reject(error)
  }
)

export default {
  // Дочерние аккаунты
  childAccounts: {
    list() {
      return api.get('/child-accounts')
    },
    create(data) {
      return api.post('/child-accounts', data)
    },
    update(id, data) {
      return api.put(`/child-accounts/${id}`, data)
    },
    delete(id) {
      return api.delete(`/child-accounts/${id}`)
    }
  },

  // Настройки синхронизации
  syncSettings: {
    get() {
      return api.get('/sync-settings')
    },
    update(data) {
      return api.put('/sync-settings', data)
    }
  },

  // Статистика
  stats: {
    dashboard() {
      return api.get('/stats/dashboard')
    }
  },

  // Логи синхронизации
  syncLogs: {
    list(params) {
      return api.get('/sync-logs', { params })
    }
  }
}
