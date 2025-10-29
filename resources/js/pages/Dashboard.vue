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

    <!-- Быстрые действия -->
    <div class="bg-white shadow rounded-lg p-4">
      <h3 class="text-sm font-bold text-gray-900 mb-3 flex items-center">
        <svg class="h-4 w-4 mr-1.5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
        Быстрые действия
      </h3>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <router-link
          to="/app/accounts"
          class="group relative block w-full border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hover:border-indigo-400 hover:bg-indigo-50 focus:outline-none transition-all duration-200"
        >
          <div class="inline-flex items-center justify-center w-10 h-10 bg-indigo-100 rounded-full mb-2 group-hover:bg-indigo-200 transition-colors">
            <svg class="h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
          </div>
          <span class="block text-sm font-semibold text-gray-900 group-hover:text-indigo-700">Добавить аккаунт</span>
          <span class="block text-xs text-gray-500 mt-1">Создать дочерний аккаунт</span>
        </router-link>

        <router-link
          to="/app/settings"
          class="group relative block w-full border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hover:border-purple-400 hover:bg-purple-50 focus:outline-none transition-all duration-200"
        >
          <div class="inline-flex items-center justify-center w-10 h-10 bg-purple-100 rounded-full mb-2 group-hover:bg-purple-200 transition-colors">
            <svg class="h-5 w-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </div>
          <span class="block text-sm font-semibold text-gray-900 group-hover:text-purple-700">Настройки</span>
          <span class="block text-xs text-gray-500 mt-1">Синхронизация данных</span>
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, defineProps, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import api from '../api'

const router = useRouter()

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

// Проверка типа аккаунта и редирект на welcome screen если не установлен
const checkAccountType = async () => {
  try {
    const response = await axios.get('/api/account/type')
    const accountType = response.data.account_type

    // Если тип аккаунта не установлен, редиректим на welcome screen
    if (accountType === null || accountType === undefined) {
      console.log('Account type not set, redirecting to welcome screen')
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

onMounted(() => {
  if (props.context) {
    checkAccountType()
    fetchStats()
  }
})

// Обновить статистику когда появляется контекст
watch(() => props.context, (newContext) => {
  if (newContext) {
    checkAccountType()
    fetchStats()
  }
})
</script>
