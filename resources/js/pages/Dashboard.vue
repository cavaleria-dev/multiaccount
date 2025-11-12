<template>
  <div class="space-y-4">
    <!-- Статистика -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
      <!-- Дочерних аккаунтов -->
      <div class="bg-white overflow-hidden shadow rounded-lg hover:shadow-md transition-shadow duration-200">
        <div class="p-4">
          <div class="flex items-center">
            <div class="flex-shrink-0 p-2.5 bg-blue-100 rounded-lg">
              <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
            </div>
            <div class="ml-3 flex-1">
              <p class="text-xs font-medium text-gray-500">Дочерних аккаунтов</p>
              <p class="text-2xl font-bold text-gray-900">{{ stats.childAccounts }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Активных -->
      <div class="bg-white overflow-hidden shadow rounded-lg hover:shadow-md transition-shadow duration-200">
        <div class="p-4">
          <div class="flex items-center">
            <div class="flex-shrink-0 p-2.5 bg-green-100 rounded-lg">
              <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <div class="ml-3 flex-1">
              <p class="text-xs font-medium text-gray-500">Активных</p>
              <p class="text-2xl font-bold text-gray-900">{{ stats.activeAccounts }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Синхронизаций -->
      <div class="bg-white overflow-hidden shadow rounded-lg hover:shadow-md transition-shadow duration-200">
        <div class="p-4">
          <div class="flex items-center">
            <div class="flex-shrink-0 p-2.5 bg-purple-100 rounded-lg">
              <svg class="h-6 w-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
            </div>
            <div class="ml-3 flex-1">
              <p class="text-xs font-medium text-gray-500">Синхронизаций</p>
              <p class="text-2xl font-bold text-gray-900">{{ stats.syncsToday }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Дочерние аккаунты -->
    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-bold text-gray-900 flex items-center">
          <svg class="h-5 w-5 mr-2 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
          </svg>
          Франшизы
        </h2>
        <router-link
          to="/app/accounts/create"
          class="inline-flex items-center space-x-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-r from-indigo-500 to-purple-600 rounded-lg hover:from-indigo-600 hover:to-purple-700 transition-all shadow-sm hover:shadow-md"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          <span>Добавить франшизу</span>
        </router-link>
      </div>

      <!-- Loading state -->
      <div v-if="loadingAccounts" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div v-for="i in 3" :key="i" class="bg-white rounded-xl shadow-md p-6 animate-pulse">
          <div class="h-12 w-12 bg-gray-200 rounded-lg mb-4"></div>
          <div class="h-4 bg-gray-200 rounded w-3/4 mb-2"></div>
          <div class="h-3 bg-gray-200 rounded w-1/2"></div>
        </div>
      </div>

      <!-- Accounts grid -->
      <div v-else-if="childAccounts.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <AccountCard
          v-for="account in childAccounts"
          :key="account.account_id"
          :account="account"
          :loading="togglingSync === account.account_id"
          @configure="configureAccount"
          @toggle-sync="toggleAccountSync"
        />
      </div>

      <!-- Empty state -->
      <div v-else class="bg-white rounded-xl shadow-md p-12 text-center">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
          <svg class="h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
          </svg>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-2">Нет дочерних аккаунтов</h3>
        <p class="text-sm text-gray-500 mb-6">Добавьте первый дочерний аккаунт для начала работы</p>
        <router-link
          to="/app/accounts"
          class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-medium rounded-lg transition-all duration-200 shadow-sm hover:shadow-md"
        >
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Добавить аккаунт
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, defineProps, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'
import AccountCard from '../components/AccountCard.vue'
import { useToast } from '../composables/useToast'

const router = useRouter()
const { error: showError } = useToast()

const props = defineProps({
  context: Object,
  loading: Boolean
})

const stats = ref({
  childAccounts: 0,
  activeAccounts: 0,
  syncsToday: 0,
  totalProductsSynced: 0,
  totalOrdersSynced: 0,
  queuedTasks: 0,
  recentErrors: 0
})

const loadingStats = ref(false)
const childAccounts = ref([])
const loadingAccounts = ref(false)
const togglingSync = ref(null)

// Проверка типа аккаунта и редирект на welcome screen если не установлен
const checkAccountType = async () => {
  try {
    const response = await api.account.getType()
    const accountType = response.data.account_type

    // Если тип аккаунта не установлен, редиректим на welcome screen
    if (accountType === null || accountType === undefined) {
      router.push('/app/welcome')
    }
  } catch (error) {
    console.error('Error checking account type:', error)
    // Не блокируем показ дашборда при ошибке
  }
}

// Загрузка статистики
const fetchStats = async () => {
  if (!props.context) return

  try {
    loadingStats.value = true
    const response = await api.stats.dashboard()
    stats.value = response.data
  } catch (error) {
    console.error('Error fetching stats:', error)
    // Показываем заглушку при ошибке
    stats.value = {
      childAccounts: 0,
      activeAccounts: 0,
      syncsToday: 0,
      totalProductsSynced: 0,
      totalOrdersSynced: 0,
      queuedTasks: 0,
      recentErrors: 0
    }
  } finally {
    loadingStats.value = false
  }
}

// Загрузка дочерних аккаунтов
const fetchChildAccounts = async () => {
  if (!props.context) return

  try {
    loadingAccounts.value = true
    const response = await api.childAccounts.list()
    childAccounts.value = response.data.data || []
  } catch (error) {
    console.error('Error fetching child accounts:', error)
    childAccounts.value = []
  } finally {
    loadingAccounts.value = false
  }
}

// Переключение синхронизации для аккаунта
const toggleAccountSync = async (accountId, enabled) => {
  try {
    togglingSync.value = accountId

    // Update via API
    await api.childAccounts.update(accountId, { sync_enabled: enabled })

    // Update local state
    const account = childAccounts.value.find(a => a.account_id === accountId)
    if (account) {
      account.sync_enabled = enabled
    }

    // Refresh stats
    await fetchStats()
  } catch (error) {
    console.error('Error toggling sync:', error)
    showError('Ошибка при изменении настройки синхронизации')

    // Revert local state on error
    const account = childAccounts.value.find(a => a.account_id === accountId)
    if (account) {
      account.sync_enabled = !enabled
    }
  } finally {
    togglingSync.value = null
  }
}

// Перейти в настройки аккаунта
const configureAccount = (accountId) => {
  // Проверка контекста перед навигацией
  const contextKey = sessionStorage.getItem('moysklad_context_key')
  if (!contextKey) {
    showError('Сессия истекла. Перезагрузите страницу.')
    setTimeout(() => window.location.reload(), 2000)
    return
  }

  router.push(`/app/accounts/${accountId}/settings`)
}

onMounted(() => {
  if (props.context) {
    checkAccountType()
    fetchStats()
    fetchChildAccounts()
  }
})

// Обновить данные когда появляется контекст
watch(() => props.context, (newContext) => {
  if (newContext) {
    checkAccountType()
    fetchStats()
    fetchChildAccounts()
  }
})
</script>
