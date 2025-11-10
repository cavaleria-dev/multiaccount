import { ref, computed } from 'vue'

// Global state для toast notifications
const toasts = ref([])
let nextId = 0

/**
 * Composable для глобальных toast уведомлений
 *
 * @example
 * const { success, error } = useToast()
 * success('Данные успешно сохранены')
 * error('Произошла ошибка')
 */
export function useToast() {
  /**
   * Показать toast уведомление
   * @param {string} message - Текст сообщения
   * @param {string} type - Тип: 'success', 'error', 'warning', 'info'
   * @param {number} duration - Длительность показа в мс (0 = без автозакрытия)
   * @returns {number} ID созданного toast
   */
  const show = (message, type = 'info', duration = 3000) => {
    const id = nextId++
    const toast = {
      id,
      message,
      type,
      visible: true
    }

    toasts.value.push(toast)

    if (duration > 0) {
      setTimeout(() => {
        remove(id)
      }, duration)
    }

    return id
  }

  /**
   * Удалить toast по ID
   * @param {number} id - ID toast для удаления
   */
  const remove = (id) => {
    const index = toasts.value.findIndex(t => t.id === id)
    if (index > -1) {
      toasts.value.splice(index, 1)
    }
  }

  /**
   * Показать success toast
   * @param {string} message - Текст сообщения
   * @param {number} duration - Длительность показа в мс
   * @returns {number} ID созданного toast
   */
  const success = (message, duration = 3000) => show(message, 'success', duration)

  /**
   * Показать error toast
   * @param {string} message - Текст сообщения
   * @param {number} duration - Длительность показа в мс
   * @returns {number} ID созданного toast
   */
  const error = (message, duration = 3000) => show(message, 'error', duration)

  /**
   * Показать warning toast
   * @param {string} message - Текст сообщения
   * @param {number} duration - Длительность показа в мс
   * @returns {number} ID созданного toast
   */
  const warning = (message, duration = 3000) => show(message, 'warning', duration)

  /**
   * Показать info toast
   * @param {string} message - Текст сообщения
   * @param {number} duration - Длительность показа в мс
   * @returns {number} ID созданного toast
   */
  const info = (message, duration = 3000) => show(message, 'info', duration)

  /**
   * Очистить все toast
   */
  const clear = () => {
    toasts.value = []
  }

  return {
    // State
    toasts: computed(() => toasts.value),

    // Methods
    show,
    success,
    error,
    warning,
    info,
    remove,
    clear
  }
}
