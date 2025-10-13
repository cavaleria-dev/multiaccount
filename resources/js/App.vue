<template>
  <div id="app" class="min-h-screen bg-gray-50">
    <!-- Навигация -->
    <nav class="bg-white shadow-sm">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex">
            <div class="flex-shrink-0 flex items-center">
              <h1 class="text-xl font-bold text-gray-900">
                Франшиза МойСклад
              </h1>
            </div>
            <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
              <router-link
                to="/app"
                class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium"
                :class="$route.path === '/app' ? 'border-indigo-500 text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
              >
                Главная
              </router-link>
              <router-link
                to="/app/accounts"
                class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium"
                :class="$route.path === '/app/accounts' ? 'border-indigo-500 text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
              >
                Дочерние аккаунты
              </router-link>
              <router-link
                to="/app/settings"
                class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium"
                :class="$route.path === '/app/settings' ? 'border-indigo-500 text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
              >
                Настройки
              </router-link>
            </div>
          </div>

          <!-- Информация об аккаунте -->
          <div class="flex items-center">
            <div v-if="loading" class="flex items-center space-x-2 text-sm text-gray-500">
              <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span>Загрузка...</span>
            </div>

            <div v-else-if="error" class="flex items-center space-x-2 text-sm text-red-600">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span>{{ error }}</span>
            </div>

            <div v-else-if="context" class="flex items-center space-x-3">
              <div class="text-right">
                <div class="text-sm font-medium text-gray-900">
                  {{ context.accountName }}
                </div>
                <div class="text-xs text-gray-500">
                  ID: {{ context.accountId }}
                </div>
              </div>
              <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                <svg class="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
              </div>
            </div>
          </div>
        </div>
      </div>
    </nav>

    <!-- Контент -->
    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
      <router-view :context="context" :loading="loading"></router-view>
    </div>
  </div>
</template>

<script setup>
import { useMoyskladContext } from './composables/useMoyskladContext'

const { context, loading, error } = useMoyskladContext()
</script>