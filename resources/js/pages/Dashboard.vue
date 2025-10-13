<template>
  <div class="space-y-8">
    <!-- Приветствие -->
    <div class="bg-gradient-to-r from-indigo-500 to-purple-600 shadow-lg rounded-2xl p-8 text-white">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-3xl font-bold mb-2">Панель управления</h2>
          <p class="text-indigo-100 text-lg">Управление дочерними аккаунтами МойСклад</p>
        </div>
        <div class="hidden md:block">
          <svg class="h-24 w-24 text-white opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
        </div>
      </div>
    </div>

    <!-- Статистика -->
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
      <!-- Дочерних аккаунтов -->
      <div class="bg-white overflow-hidden shadow-lg rounded-xl hover:shadow-xl transition-shadow duration-300">
        <div class="p-6">
          <div class="flex items-center justify-between">
            <div class="flex-1">
              <div class="flex items-center">
                <div class="flex-shrink-0 p-3 bg-blue-100 rounded-lg">
                  <svg class="h-8 w-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                  </svg>
                </div>
                <div class="ml-4">
                  <p class="text-sm font-medium text-gray-500">Дочерних аккаунтов</p>
                  <p class="text-3xl font-bold text-gray-900">{{ stats.childAccounts }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="bg-blue-50 px-6 py-3">
          <router-link to="/app/accounts" class="text-sm font-medium text-blue-600 hover:text-blue-800 flex items-center">
            Управление аккаунтами
            <svg class="ml-1 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </router-link>
        </div>
      </div>

      <!-- Активных -->
      <div class="bg-white overflow-hidden shadow-lg rounded-xl hover:shadow-xl transition-shadow duration-300">
        <div class="p-6">
          <div class="flex items-center justify-between">
            <div class="flex-1">
              <div class="flex items-center">
                <div class="flex-shrink-0 p-3 bg-green-100 rounded-lg">
                  <svg class="h-8 w-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <div class="ml-4">
                  <p class="text-sm font-medium text-gray-500">Активных</p>
                  <p class="text-3xl font-bold text-gray-900">{{ stats.activeAccounts }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="bg-green-50 px-6 py-3">
          <div class="text-sm font-medium text-green-600 flex items-center">
            Все системы работают
            <svg class="ml-1 h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Синхронизаций -->
      <div class="bg-white overflow-hidden shadow-lg rounded-xl hover:shadow-xl transition-shadow duration-300">
        <div class="p-6">
          <div class="flex items-center justify-between">
            <div class="flex-1">
              <div class="flex items-center">
                <div class="flex-shrink-0 p-3 bg-purple-100 rounded-lg">
                  <svg class="h-8 w-8 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                </div>
                <div class="ml-4">
                  <p class="text-sm font-medium text-gray-500">Синхронизаций сегодня</p>
                  <p class="text-3xl font-bold text-gray-900">{{ stats.syncsToday }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="bg-purple-50 px-6 py-3">
          <router-link to="/app/settings" class="text-sm font-medium text-purple-600 hover:text-purple-800 flex items-center">
            Настроить синхронизацию
            <svg class="ml-1 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </router-link>
        </div>
      </div>
    </div>

    <!-- Быстрые действия -->
    <div class="bg-white shadow-lg rounded-xl p-8">
      <h3 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
        <svg class="h-6 w-6 mr-2 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
        Быстрые действия
      </h3>
      <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
        <router-link
          to="/app/accounts"
          class="group relative block w-full border-2 border-dashed border-gray-300 rounded-xl p-8 text-center hover:border-indigo-400 hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all duration-200"
        >
          <div class="inline-flex items-center justify-center w-16 h-16 bg-indigo-100 rounded-full mb-4 group-hover:bg-indigo-200 transition-colors">
            <svg class="h-8 w-8 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
          </div>
          <span class="block text-base font-semibold text-gray-900 group-hover:text-indigo-700">Добавить дочерний аккаунт</span>
          <span class="block text-sm text-gray-500 mt-2">Создайте новый дочерний аккаунт для франшизы</span>
        </router-link>

        <router-link
          to="/app/settings"
          class="group relative block w-full border-2 border-dashed border-gray-300 rounded-xl p-8 text-center hover:border-purple-400 hover:bg-purple-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-all duration-200"
        >
          <div class="inline-flex items-center justify-center w-16 h-16 bg-purple-100 rounded-full mb-4 group-hover:bg-purple-200 transition-colors">
            <svg class="h-8 w-8 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </div>
          <span class="block text-base font-semibold text-gray-900 group-hover:text-purple-700">Настроить синхронизацию</span>
          <span class="block text-sm text-gray-500 mt-2">Управление параметрами синхронизации данных</span>
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, defineProps } from 'vue'
import axios from 'axios'

const props = defineProps({
  context: Object,
  loading: Boolean
})

const stats = ref({
  childAccounts: 0,
  activeAccounts: 0,
  syncsToday: 0
})

const loadingStats = ref(false)

// Загрузка статистики
const fetchStats = async () => {
  if (!props.context) return

  try {
    loadingStats.value = true
    const response = await axios.get('/api/stats')
    stats.value = response.data
  } catch (error) {
    console.error('Error fetching stats:', error)
    // Показываем заглушку при ошибке
    stats.value = {
      childAccounts: 0,
      activeAccounts: 0,
      syncsToday: 0
    }
  } finally {
    loadingStats.value = false
  }
}

onMounted(() => {
  if (props.context) {
    fetchStats()
  }
})
</script>
