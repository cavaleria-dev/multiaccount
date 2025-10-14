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

      // Получаем параметры из URL
      const urlParams = new URLSearchParams(window.location.search)
      const contextKey = urlParams.get('contextKey')
      const appUid = urlParams.get('appUid')

      if (!contextKey) {
        throw new Error('contextKey не найден в URL')
      }

      if (!appUid) {
        throw new Error('appUid не найден в URL')
      }

      console.log('Fetching context with:', { contextKey: contextKey.substring(0, 20) + '...', appUid })

      // Отправляем contextKey и appUid на сервер для получения контекста
      const response = await axios.post('/api/context', {
        contextKey,
        appUid
      })

      context.value = response.data

      // Сохраняем contextKey в sessionStorage для последующих запросов
      sessionStorage.setItem('moysklad_context_key', contextKey)

      console.log('Context loaded successfully:', context.value)

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
