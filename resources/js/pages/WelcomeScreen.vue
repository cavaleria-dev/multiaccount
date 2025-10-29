<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-indigo-50 to-purple-50 px-4">
    <div class="max-w-4xl w-full">
      <!-- Header -->
      <div class="text-center mb-12">
        <div class="flex justify-center mb-4">
          <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-2xl p-4">
            <svg class="h-12 w-12 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
          </div>
        </div>
        <h1 class="text-4xl font-bold text-gray-900 mb-3">Добро пожаловать!</h1>
        <p class="text-lg text-gray-600">Выберите тип вашего аккаунта для начала работы с системой мультиаккаунтов</p>
      </div>

      <!-- Account Type Cards -->
      <div class="grid md:grid-cols-2 gap-6 mb-8">
        <!-- Main Account Card -->
        <button
          @click="selectType('main')"
          :disabled="loading"
          class="group relative bg-white rounded-2xl shadow-lg hover:shadow-xl transition-all duration-300 p-8 text-left border-2"
          :class="[
            selectedType === 'main'
              ? 'border-indigo-500 ring-4 ring-indigo-100'
              : 'border-gray-200 hover:border-indigo-300',
            loading ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer'
          ]"
        >
          <!-- Selection Indicator -->
          <div v-if="selectedType === 'main'" class="absolute top-4 right-4">
            <div class="bg-indigo-500 rounded-full p-1">
              <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
              </svg>
            </div>
          </div>

          <!-- Icon -->
          <div class="flex justify-center mb-4">
            <div class="bg-indigo-100 rounded-xl p-3 group-hover:bg-indigo-200 transition-colors">
              <svg class="h-10 w-10 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
              </svg>
            </div>
          </div>

          <!-- Title -->
          <h3 class="text-2xl font-bold text-gray-900 mb-2 text-center">Главный аккаунт</h3>
          <p class="text-gray-600 text-center mb-4">Управляйте франшизной сетью</p>

          <!-- Features -->
          <ul class="space-y-2 text-sm text-gray-700">
            <li class="flex items-start">
              <svg class="h-5 w-5 text-indigo-500 mr-2 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <span>Управление дочерними аккаунтами</span>
            </li>
            <li class="flex items-start">
              <svg class="h-5 w-5 text-indigo-500 mr-2 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <span>Синхронизация товаров и услуг</span>
            </li>
            <li class="flex items-start">
              <svg class="h-5 w-5 text-indigo-500 mr-2 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <span>Контроль заказов франшиз</span>
            </li>
            <li class="flex items-start">
              <svg class="h-5 w-5 text-indigo-500 mr-2 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <span>Настройка параметров синхронизации</span>
            </li>
          </ul>
        </button>

        <!-- Child Account Card -->
        <button
          @click="selectType('child')"
          :disabled="loading"
          class="group relative bg-white rounded-2xl shadow-lg hover:shadow-xl transition-all duration-300 p-8 text-left border-2"
          :class="[
            selectedType === 'child'
              ? 'border-blue-500 ring-4 ring-blue-100'
              : 'border-gray-200 hover:border-blue-300',
            loading ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer'
          ]"
        >
          <!-- Selection Indicator -->
          <div v-if="selectedType === 'child'" class="absolute top-4 right-4">
            <div class="bg-blue-500 rounded-full p-1">
              <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
              </svg>
            </div>
          </div>

          <!-- Icon -->
          <div class="flex justify-center mb-4">
            <div class="bg-blue-100 rounded-xl p-3 group-hover:bg-blue-200 transition-colors">
              <svg class="h-10 w-10 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
            </div>
          </div>

          <!-- Title -->
          <h3 class="text-2xl font-bold text-gray-900 mb-2 text-center">Дочерний аккаунт</h3>
          <p class="text-gray-600 text-center mb-4">Часть франшизной сети</p>

          <!-- Features -->
          <ul class="space-y-2 text-sm text-gray-700">
            <li class="flex items-start">
              <svg class="h-5 w-5 text-blue-500 mr-2 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <span>Получение товаров и услуг</span>
            </li>
            <li class="flex items-start">
              <svg class="h-5 w-5 text-blue-500 mr-2 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <span>Автоматическая синхронизация</span>
            </li>
            <li class="flex items-start">
              <svg class="h-5 w-5 text-blue-500 mr-2 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <span>Отправка заказов в главный аккаунт</span>
            </li>
            <li class="flex items-start">
              <svg class="h-5 w-5 text-blue-500 mr-2 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <span>Работа с актуальным ассортиментом</span>
            </li>
          </ul>
        </button>
      </div>

      <!-- Error Message -->
      <div v-if="error" class="mb-6 bg-red-50 border-l-4 border-red-400 p-4 rounded">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
            </svg>
          </div>
          <div class="ml-3">
            <p class="text-sm text-red-700">{{ error }}</p>
          </div>
        </div>
      </div>

      <!-- Confirm Button -->
      <div class="text-center">
        <button
          @click="confirm"
          :disabled="!selectedType || loading"
          class="inline-flex items-center px-8 py-4 rounded-xl text-lg font-semibold text-white transition-all duration-200"
          :class="[
            !selectedType || loading
              ? 'bg-gray-400 cursor-not-allowed'
              : 'bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 shadow-lg hover:shadow-xl'
          ]"
        >
          <svg v-if="loading" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span v-if="loading">Настройка аккаунта...</span>
          <span v-else>Продолжить</span>
        </button>

        <p v-if="selectedType && !loading" class="mt-4 text-sm text-gray-500">
          Вы можете изменить тип аккаунта позже в настройках
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router = useRouter()
const selectedType = ref(null)
const loading = ref(false)
const error = ref(null)

function selectType(type) {
  if (!loading.value) {
    selectedType.value = type
    error.value = null
  }
}

async function confirm() {
  if (!selectedType.value || loading.value) {
    return
  }

  try {
    loading.value = true
    error.value = null

    const response = await api.account.setType(selectedType.value)

    if (response.data.success) {
      // Redirect to main app
      router.push('/app')
    } else {
      error.value = response.data.message || 'Не удалось установить тип аккаунта'
    }
  } catch (err) {
    console.error('Failed to set account type:', err)
    error.value = err.response?.data?.message || 'Произошла ошибка при установке типа аккаунта'
  } finally {
    loading.value = false
  }
}
</script>
