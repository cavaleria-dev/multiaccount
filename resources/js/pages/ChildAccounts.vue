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
        <form @submit.prevent="addAccount">
          <div class="space-y-4">
            <div>
              <label for="child_account_id" class="block text-sm font-medium text-gray-700">ID дочернего аккаунта</label>
              <input
                type="text"
                id="child_account_id"
                v-model="newAccount.child_account_id"
                required
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
              />
              <p class="mt-1 text-xs text-gray-500">UUID аккаунта из МойСклад</p>
            </div>
            <div>
              <label for="counterparty_id" class="block text-sm font-medium text-gray-700">ID контрагента франшизы</label>
              <input
                type="text"
                id="counterparty_id"
                v-model="newAccount.counterparty_id"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
              />
              <p class="mt-1 text-xs text-gray-500">ID контрагента франшизы в главном аккаунте (опционально)</p>
            </div>
            <div>
              <label for="supplier_counterparty_id" class="block text-sm font-medium text-gray-700">ID поставщика (главного офиса)</label>
              <input
                type="text"
                id="supplier_counterparty_id"
                v-model="newAccount.supplier_counterparty_id"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
              />
              <p class="mt-1 text-xs text-gray-500">ID контрагента главного офиса в дочернем аккаунте (опционально)</p>
            </div>
          </div>
          <div class="mt-6 flex justify-end space-x-3">
            <button
              type="button"
              @click="showAddModal = false"
              class="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
            >
              Отмена
            </button>
            <button
              type="submit"
              class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
            >
              Добавить
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const accounts = ref([])
const showAddModal = ref(false)
const loading = ref(false)
const error = ref(null)
const newAccount = ref({
  child_account_id: '',
  counterparty_id: '',
  supplier_counterparty_id: ''
})

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
  // TODO: Открыть модальное окно редактирования настроек
  console.log('Edit account:', account)
  // Переход на страницу настроек для этого аккаунта
  // router.push(`/app/settings/${account.account_id}`)
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

async function addAccount() {
  try {
    await api.childAccounts.create(newAccount.value)
    showAddModal.value = false
    newAccount.value = {
      child_account_id: '',
      counterparty_id: '',
      supplier_counterparty_id: ''
    }
    await loadAccounts()
  } catch (err) {
    console.error('Failed to add account:', err)
    alert('Не удалось добавить аккаунт: ' + (err.response?.data?.error || err.message))
  }
}
</script>
