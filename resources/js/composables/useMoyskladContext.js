import { ref, onMounted } from 'vue'
import axios from 'axios'

export function useMoyskladContext() {
  const context = ref(null)
  const loading = ref(true)
  const error = ref(null)

  const fetchContext = async () => {
    try {
      loading.value = true
      error.value = null

      // Получаем JWT токен из URL параметров
      const urlParams = new URLSearchParams(window.location.search)
      const token = urlParams.get('contextKey')

      if (!token) {
        throw new Error('JWT токен не найден в URL')
      }

      // Отправляем токен на сервер для получения контекста
      const response = await axios.post('/api/context', {
        contextKey: token
      })

      context.value = response.data

      // Сохраняем токен для последующих запросов
      axios.defaults.headers.common['X-Context-Key'] = token

    } catch (err) {
      error.value = err.message || 'Ошибка при получении контекста'
      console.error('Error fetching context:', err)
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    fetchContext()
  })

  return {
    context,
    loading,
    error,
    fetchContext
  }
}
