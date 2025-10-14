<template>
  <div class="space-y-6">
    <div class="sm:flex sm:items-center">
      <div class="sm:flex-auto">
        <h1 class="text-2xl font-semibold text-gray-900">Дочерние аккаунты</h1>
        <p class="mt-2 text-sm text-gray-700">
          Управление франшизными аккаунтами и их настройками
        </p>
      </div>
      <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
        <button
          type="button"
          @click="showAddModal = true"
          class="inline-flex items-center justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:w-auto"
        >
          Добавить аккаунт
        </button>
      </div>
    </div>

    <!-- Таблица аккаунтов -->
    <div class="bg-white shadow overflow-hidden sm:rounded-lg">
      <!-- Loading state -->
      <div v-if="loading" class="px-6 py-12 text-center">
        <svg class="animate-spin h-8 w-8 mx-auto text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <p class="mt-2 text-sm text-gray-500">Загрузка...</p>
      </div>

      <!-- Error state -->
      <div v-else-if="error" class="px-6 py-8 text-center">
        <div class="text-red-600 mb-2">
          <svg class="h-8 w-8 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <p class="text-sm text-gray-700">{{ error }}</p>
        <button @click="loadAccounts" class="mt-3 text-indigo-600 hover:text-indigo-500 text-sm font-medium">
          Попробовать снова
        </button>
      </div>

      <!-- Table -->
      <table v-else class="min-w-full divide-y divide-gray-300">
        <thead class="bg-gray-50">
          <tr>
            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-6">
              Название
            </th>
            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
              ID аккаунта
            </th>
            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
              Статус
            </th>
            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
              Последняя синхронизация
            </th>
            <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
              <span class="sr-only">Действия</span>
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 bg-white">
          <tr v-for="account in accounts" :key="account.account_id">
            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 sm:pl-6">
              {{ account.account_name || 'Без названия' }}
            </td>
            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 font-mono">
              {{ account.account_id.substring(0, 8) }}...
            </td>
            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
              <span
                :class="[
                  'inline-flex rounded-full px-2 text-xs font-semibold leading-5',
                  account.status === 'activated' ? 'bg-green-100 text-green-800' : '',
                  account.status === 'suspended' ? 'bg-yellow-100 text-yellow-800' : '',
                  account.status === 'uninstalled' ? 'bg-red-100 text-red-800' : ''
                ]"
              >
                {{ getStatusLabel(account.status) }}
              </span>
            </td>
            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
              {{ formatDate(account.last_sync_at) }}
            </td>
            <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
              <button @click="editAccount(account)" class="text-indigo-600 hover:text-indigo-900 mr-4">
                Настроить
              </button>
              <button @click="deleteAccount(account)" class="text-red-600 hover:text-red-900">
                Удалить
              </button>
            </td>
          </tr>
          <tr v-if="accounts.length === 0">
            <td colspan="5" class="px-3 py-8 text-center text-sm text-gray-500">
              <div class="text-gray-400 mb-2">
                <svg class="h-12 w-12 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
              </div>
              Нет подключенных дочерних аккаунтов
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Модальное окно добавления аккаунта -->
    <div v-if="showAddModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg p-6 max-w-md w-full">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Добавить дочерний аккаунт</h3>

        <form @submit.prevent="addAccount" class="space-y-4">
          <!-- Error message -->
          <div v-if="addError" class="rounded-md bg-red-50 p-3">
            <div class="flex">
              <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
              </div>
              <div class="ml-3">
                <p class="text-sm text-red-800">{{ addError }}</p>
              </div>
            </div>
          </div>

          <div>
            <label for="account-name" class="block text-sm font-medium text-gray-700 mb-1">
              Название аккаунта
            </label>
            <input
              id="account-name"
              v-model="newAccountName"
              type="text"
              required
              placeholder="Введите название аккаунта МойСклад"
              class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            />
            <p class="mt-1 text-xs text-gray-500">
              Укажите точное название аккаунта, на котором установлено приложение
            </p>
          </div>

          <!-- Кнопки -->
          <div class="flex justify-end space-x-3 pt-4 border-t">
            <button
              type="button"
              @click="closeAddModal"
              :disabled="addingAccount"
              class="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Отмена
            </button>
            <button
              type="submit"
              :disabled="!newAccountName.trim() || addingAccount"
              class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="addingAccount" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span v-if="addingAccount">Добавление...</span>
              <span v-else>Добавить</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Success notification -->
    <div v-if="showSuccessNotification" class="fixed bottom-4 right-4 z-50 animate-slide-up">
      <div class="bg-green-50 border border-green-200 rounded-lg p-4 shadow-lg max-w-sm">
        <div class="flex items-start">
          <div class="flex-shrink-0">
            <svg class="h-6 w-6 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div class="ml-3 flex-1">
            <p class="text-sm font-medium text-green-800">
              Аккаунт успешно добавлен!
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router = useRouter()

const accounts = ref([])
const showAddModal = ref(false)
const loading = ref(false)
const error = ref(null)
const newAccountName = ref('')
const addingAccount = ref(false)
const addError = ref(null)
const showSuccessNotification = ref(false)

// Загрузка списка аккаунтов
const loadAccounts = async () => {
  try {
    loading.value = true
    error.value = null
    const response = await api.childAccounts.list()
    accounts.value = response.data.data || []
  } catch (err) {
    console.error('Failed to load child accounts:', err)
    error.value = 'Не удалось загрузить список аккаунтов'
  } finally {
    loading.value = false
  }
}

// Очистка формы при закрытии модального окна
watch(showAddModal, (newValue) => {
  if (!newValue) {
    newAccountName.value = ''
    addError.value = null
  }
})

onMounted(() => {
  loadAccounts()
})

function getStatusLabel(status) {
  const labels = {
    activated: 'Активен',
    suspended: 'Приостановлен',
    uninstalled: 'Удален'
  }
  return labels[status] || status
}

function formatDate(date) {
  if (!date) return 'Никогда'
  return new Date(date).toLocaleString('ru-RU')
}

function editAccount(account) {
  // Переход на страницу настроек франшизы
  router.push(`/app/accounts/${account.account_id}/settings`)
}

async function deleteAccount(account) {
  if (!confirm(`Удалить связь с аккаунтом "${account.account_name}"?`)) {
    return
  }

  try {
    await api.childAccounts.delete(account.account_id)
    await loadAccounts()
  } catch (err) {
    console.error('Failed to delete account:', err)
    alert('Не удалось удалить аккаунт')
  }
}

function closeAddModal() {
  showAddModal.value = false
  newAccountName.value = ''
  addError.value = null
}

async function addAccount() {
  if (!newAccountName.value.trim()) {
    return
  }

  try {
    addingAccount.value = true
    addError.value = null

    await api.childAccounts.create({
      account_name: newAccountName.value.trim()
    })

    // Закрыть модалку
    showAddModal.value = false
    newAccountName.value = ''

    // Показать уведомление об успехе
    showSuccessNotification.value = true
    setTimeout(() => {
      showSuccessNotification.value = false
    }, 3000)

    // Перезагрузить список аккаунтов
    await loadAccounts()
  } catch (err) {
    console.error('Failed to add account:', err)
    addError.value = err.response?.data?.error || err.message || 'Не удалось добавить аккаунт'
  } finally {
    addingAccount.value = false
  }
}
</script>

<style scoped>
@keyframes slide-up {
  from {
    transform: translateY(100%);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}

.animate-slide-up {
  animation: slide-up 0.3s ease-out;
}
</style>
