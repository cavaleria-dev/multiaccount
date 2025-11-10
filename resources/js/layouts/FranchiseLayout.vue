<template>
  <div class="flex h-full">
    <!-- Sidebar -->
    <aside class="w-64 bg-white border-r border-gray-200 flex-shrink-0">
      <div class="h-full flex flex-col">
        <!-- Header -->
        <div class="p-6 border-b border-gray-200">
          <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-lg bg-gradient-to-r from-indigo-500 to-purple-600 flex items-center justify-center flex-shrink-0">
              <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
              </svg>
            </div>
            <div class="flex-1 min-w-0">
              <h2 class="text-sm font-semibold text-gray-900 truncate">{{ accountName }}</h2>
              <p class="text-xs text-gray-500">Настройки франшизы</p>
            </div>
          </div>
        </div>

        <!-- Navigation Menu -->
        <nav class="flex-1 p-4 space-y-1 overflow-y-auto">
          <router-link
            v-for="item in menuItems"
            :key="item.path"
            :to="`/app/accounts/${accountId}/${item.path}`"
            :class="[
              'group flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-all duration-200',
              isActive(item.path)
                ? 'bg-gradient-to-r from-indigo-50 to-purple-50 text-indigo-700'
                : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900'
            ]"
          >
            <component
              :is="item.icon"
              :class="[
                'mr-3 h-5 w-5 flex-shrink-0',
                isActive(item.path)
                  ? 'text-indigo-600'
                  : 'text-gray-400 group-hover:text-gray-600'
              ]"
            />
            {{ item.label }}
          </router-link>
        </nav>

        <!-- Back Button -->
        <div class="p-4 border-t border-gray-200">
          <router-link
            to="/app/accounts"
            class="group flex items-center px-3 py-2 text-sm font-medium text-gray-700 rounded-lg hover:bg-gray-50 hover:text-gray-900 transition-all duration-200"
          >
            <svg class="mr-3 h-5 w-5 text-gray-400 group-hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Назад к списку
          </router-link>
        </div>
      </div>
    </aside>

    <!-- Content Area -->
    <main class="flex-1 overflow-y-auto bg-gray-50">
      <div class="container mx-auto p-6 max-w-7xl">
        <!-- Page Header -->
        <div class="mb-6">
          <h1 class="text-2xl font-bold text-gray-900">{{ currentPageTitle }}</h1>
          <p class="mt-1 text-sm text-gray-600">{{ currentPageDescription }}</p>
        </div>

        <!-- Content -->
        <router-view :account-id="accountId" />
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, h } from 'vue'
import { useRoute } from 'vue-router'
import api from '../api'

// Icons as functional components
const PackageIcon = () => h('svg', { class: 'h-5 w-5', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
  h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4' })
])

const DocumentIcon = () => h('svg', { class: 'h-5 w-5', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
  h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' })
])

const SettingsIcon = () => h('svg', { class: 'h-5 w-5', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
  h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z' }),
  h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M15 12a3 3 0 11-6 0 3 3 0 016 0z' })
])

const props = defineProps({
  accountId: {
    type: String,
    required: true
  }
})

const route = useRoute()
const accountName = ref('Загрузка...')

// Menu items
const menuItems = [
  {
    path: 'products',
    label: 'Товары',
    icon: PackageIcon,
    description: 'Настройки синхронизации товаров, цен и фильтров'
  },
  {
    path: 'documents',
    label: 'Документы',
    icon: DocumentIcon,
    description: 'Настройки синхронизации документов и целевых объектов'
  },
  {
    path: 'general',
    label: 'Общие',
    icon: SettingsIcon,
    description: 'Общие настройки, НДС, автосоздание'
  }
]

// Check if menu item is active
const isActive = (path) => {
  const currentPath = route.path.split('/').pop()
  return currentPath === path
}

// Get current page info
const currentPageTitle = computed(() => {
  const path = route.path.split('/').pop()
  const item = menuItems.find(i => i.path === path)
  return item?.label || 'Настройки'
})

const currentPageDescription = computed(() => {
  const path = route.path.split('/').pop()
  const item = menuItems.find(i => i.path === path)
  return item?.description || ''
})

// Load account info
const loadAccountInfo = async () => {
  try {
    const response = await api.childAccounts.get(props.accountId)
    accountName.value = response.data.account_name || 'Без названия'
  } catch (error) {
    console.error('Failed to load account info:', error)
    accountName.value = 'Аккаунт'
  }
}

onMounted(() => {
  loadAccountInfo()
})
</script>
