<template>
  <div id="app" class="min-h-screen bg-gray-50">
    <!-- Навигация -->
    <nav class="bg-white border-b border-gray-200">
      <div class="px-4 sm:px-6">
        <div class="flex justify-between h-14">
          <div class="flex">
            <div class="flex-shrink-0 flex items-center">
              <div class="flex items-center space-x-2">
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-lg p-1.5">
                  <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                  </svg>
                </div>
                <h1 class="text-lg font-bold text-gray-900">
                  Мультиаккаунты
                </h1>
              </div>
            </div>
            <div class="ml-6 flex space-x-1">
              <router-link
                to="/app"
                class="inline-flex items-center px-2.5 py-1.5 rounded-md text-xs font-medium transition-colors"
                :class="$route.path === '/app' ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
              >
                <svg class="h-3.5 w-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                Главная
              </router-link>
              <router-link
                to="/app/accounts"
                class="inline-flex items-center px-2.5 py-1.5 rounded-md text-xs font-medium transition-colors"
                :class="$route.path === '/app/accounts' ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
              >
                <svg class="h-3.5 w-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                Аккаунты
              </router-link>
              <router-link
                to="/app/settings"
                class="inline-flex items-center px-2.5 py-1.5 rounded-md text-xs font-medium transition-colors"
                :class="$route.path === '/app/settings' ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
              >
                <svg class="h-3.5 w-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Настройки
              </router-link>
            </div>
          </div>

          <!-- Информация об аккаунте -->
          <div class="flex items-center">
            <div v-if="loading" class="flex items-center space-x-1.5 px-2.5 py-1 bg-gray-50 rounded-md">
              <svg class="animate-spin h-3.5 w-3.5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span class="text-xs font-medium text-gray-700">Загрузка...</span>
            </div>

            <div v-else-if="error" class="flex items-center space-x-1.5 px-2.5 py-1 bg-red-50 rounded-md">
              <svg class="h-3.5 w-3.5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span class="text-xs font-medium text-red-700">Ошибка</span>
            </div>

            <div v-else-if="context" class="flex items-center space-x-2 px-2.5 py-1 bg-indigo-50 rounded-md border border-indigo-100">
              <div class="text-right">
                <div class="text-xs font-semibold text-gray-900">
                  {{ context.accountName }}
                </div>
                <div class="text-[10px] text-gray-500 font-mono">
                  {{ context.accountId }}
                </div>
              </div>
              <div class="h-7 w-7 rounded-full bg-gradient-to-r from-indigo-500 to-purple-600 flex items-center justify-center">
                <svg class="h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
              </div>
            </div>
          </div>
        </div>
      </div>
    </nav>

    <!-- Контент -->
    <div class="px-4 sm:px-6 py-4">
      <router-view :context="context" :loading="loading"></router-view>
    </div>
  </div>
</template>

<script setup>
import { useMoyskladContext } from './composables/useMoyskladContext'

const { context, loading, error } = useMoyskladContext()
</script>