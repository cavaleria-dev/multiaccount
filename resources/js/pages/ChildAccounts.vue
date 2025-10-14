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
      <div class="bg-white rounded-lg p-6 max-w-lg w-full max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Добавить дочерний аккаунт</h3>

        <!-- Загрузка -->
        <div v-if="loadingAvailable" class="py-8 text-center">
          <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
          <p class="mt-2 text-sm text-gray-500">Загрузка доступных аккаунтов...</p>
        </div>

        <!-- Список доступных аккаунтов -->
        <div v-else-if="availableAccounts.length > 0" class="space-y-3">
          <p class="text-sm text-gray-700">Выберите аккаунт для подключения:</p>
          <div
            v-for="account in availableAccounts"
            :key="account.account_id"
            @click="selectAccount(account)"
            :class="[
              'border-2 rounded-lg p-3 cursor-pointer transition-all',
              selectedAccount?.account_id === account.account_id ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-indigo-300',
              account.availability === 'connected' ? 'opacity-50 cursor-not-allowed' : '',
              account.availability === 'connected_to_other' ? 'opacity-70' : ''
            ]"
          >
            <div class="flex items-start justify-between">
              <div class="flex-1">
                <p class="font-medium text-gray-900">{{ account.account_name || 'Без названия' }}</p>
                <p class="text-xs text-gray-500 font-mono mt-1">{{ account.account_id }}</p>
              </div>
              <div>
                <span v-if="account.availability === 'connected'" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                  Уже подключен
                </span>
                <span v-else-if="account.availability === 'connected_to_other'" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                  Занят
                </span>
                <span v-else class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                  Доступен
                </span>
              </div>
            </div>
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
              type="button"
              @click="addAccount"
              :disabled="!selectedAccount || selectedAccount.availability !== 'available'"
              class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Добавить
            </button>
          </div>
        </div>

        <!-- Нет доступных аккаунтов -->
        <div v-else class="py-8 text-center">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
          </svg>
          <p class="mt-2 text-sm text-gray-900 font-medium">Нет доступных аккаунтов</p>
          <p class="mt-1 text-xs text-gray-500">Установите приложение на других аккаунтах МойСклад</p>
          <button
            type="button"
            @click="closeAddModal"
            class="mt-4 inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
          >
            Закрыть
          </button>
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
const loadingAvailable = ref(false)
const availableAccounts = ref([])
const selectedAccount = ref(null)

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

// Загрузка доступных аккаунтов при открытии модального окна
watch(showAddModal, async (newValue) => {
  if (newValue) {
    try {
      loadingAvailable.value = true
      const response = await api.childAccounts.available()
      availableAccounts.value = response.data.data || []
      selectedAccount.value = null
    } catch (err) {
      console.error('Failed to load available accounts:', err)
      alert('Не удалось загрузить список доступных аккаунтов')
    } finally {
      loadingAvailable.value = false
    }
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

function selectAccount(account) {
  if (account.availability === 'connected') {
    return // Уже подключен
  }
  selectedAccount.value = account
}

function closeAddModal() {
  showAddModal.value = false
  selectedAccount.value = null
}

async function addAccount() {
  if (!selectedAccount.value || selectedAccount.value.availability !== 'available') {
    return
  }

  try {
    await api.childAccounts.create({
      child_account_id: selectedAccount.value.account_id
    })
    showAddModal.value = false
    selectedAccount.value = null
    await loadAccounts()
  } catch (err) {
    console.error('Failed to add account:', err)
    alert('Не удалось добавить аккаунт: ' + (err.response?.data?.error || err.message))
  }
}
</script>
