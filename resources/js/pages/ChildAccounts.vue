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
      <table class="min-w-full divide-y divide-gray-300">
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
            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
              {{ account.account_id }}
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
              class="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
            >
              Отмена
            </button>
            <button
              type="submit"
              :disabled="!newAccountName.trim() || addingAccount"
              class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <span v-if="addingAccount">Добавление...</span>
              <span v-else>Добавить</span>
            </button>
          </div>
        </form>
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
}

async function addAccount() {
  if (!newAccountName.value.trim()) {
    return
  }

  try {
    addingAccount.value = true
    await api.childAccounts.create({
      account_name: newAccountName.value.trim()
    })
    showAddModal.value = false
    newAccountName.value = ''
    await loadAccounts()
  } catch (err) {
    console.error('Failed to add account:', err)
    alert('Не удалось добавить аккаунт: ' + (err.response?.data?.error || err.message))
  } finally {
    addingAccount.value = false
  }
}
</script>
