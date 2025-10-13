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
          <tr v-for="account in accounts" :key="account.id">
            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 sm:pl-6">
              {{ account.name }}
            </td>
            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
              {{ account.accountId }}
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
              {{ formatDate(account.lastSync) }}
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
              <label for="accountId" class="block text-sm font-medium text-gray-700">ID аккаунта МойСклад</label>
              <input
                type="text"
                id="accountId"
                v-model="newAccount.accountId"
                required
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
              />
            </div>
            <div>
              <label for="name" class="block text-sm font-medium text-gray-700">Название (опционально)</label>
              <input
                type="text"
                id="name"
                v-model="newAccount.name"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="Франшиза Москва"
              />
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

const accounts = ref([])
const showAddModal = ref(false)
const newAccount = ref({
  accountId: '',
  name: ''
})

// Загрузка списка аккаунтов
onMounted(async () => {
  // TODO: Загрузка данных с API
  accounts.value = [
    {
      id: 1,
      name: 'Франшиза Москва',
      accountId: 'abc123-def456',
      status: 'activated',
      lastSync: new Date()
    }
  ]
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
  // TODO: Открыть модальное окно редактирования
  console.log('Edit account:', account)
}

function deleteAccount(account) {
  if (confirm(`Удалить аккаунт "${account.name}"?`)) {
    // TODO: Удаление через API
    console.log('Delete account:', account)
  }
}

function addAccount() {
  // TODO: Добавление через API
  console.log('Add account:', newAccount.value)
  showAddModal.value = false
  newAccount.value = { accountId: '', name: '' }
}
</script>
