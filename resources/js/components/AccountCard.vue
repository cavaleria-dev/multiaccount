<template>
  <div class="bg-white rounded-xl shadow-md hover:shadow-xl transition-all duration-300 p-6 border border-gray-100">
    <!-- Header with Icon and Status -->
    <div class="flex items-start justify-between mb-4">
      <div class="flex items-center space-x-3">
        <!-- Gradient Icon -->
        <div class="w-12 h-12 rounded-lg bg-gradient-to-r from-indigo-500 to-purple-600 flex items-center justify-center flex-shrink-0">
          <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
          </svg>
        </div>

        <!-- Account Name -->
        <div>
          <h3 class="text-lg font-semibold text-gray-900">{{ account.account_name }}</h3>
          <p class="text-xs text-gray-500 mt-0.5">ID: {{ truncatedAccountId }}</p>
        </div>
      </div>

      <!-- Status Badge -->
      <span
        :class="statusClasses"
        class="px-3 py-1 rounded-full text-xs font-medium"
      >
        {{ statusText }}
      </span>
    </div>

    <!-- Sync Toggle -->
    <div class="mb-4 p-3 bg-gray-50 rounded-lg">
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2">
          <svg
            :class="account.sync_enabled ? 'text-green-600' : 'text-gray-400'"
            class="w-5 h-5"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <span class="text-sm font-medium text-gray-700">Синхронизация</span>
        </div>

        <Toggle
          :model-value="account.sync_enabled"
          @update:model-value="$emit('toggle-sync', account.id, $event)"
          size="small"
          :color="account.sync_enabled ? 'green' : 'gray'"
          :disabled="loading"
        />
      </div>
    </div>

    <!-- Stats (optional - if you want to show some numbers) -->
    <div v-if="account.products_synced !== undefined || account.orders_synced !== undefined" class="mb-4 grid grid-cols-2 gap-3">
      <div v-if="account.products_synced !== undefined" class="text-center p-2 bg-indigo-50 rounded-lg">
        <p class="text-xs text-indigo-600 font-medium">Товары</p>
        <p class="text-lg font-bold text-indigo-900">{{ account.products_synced || 0 }}</p>
      </div>
      <div v-if="account.orders_synced !== undefined" class="text-center p-2 bg-purple-50 rounded-lg">
        <p class="text-xs text-purple-600 font-medium">Заказы</p>
        <p class="text-lg font-bold text-purple-900">{{ account.orders_synced || 0 }}</p>
      </div>
    </div>

    <!-- Configure Button -->
    <button
      @click="$emit('configure', account.id)"
      class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-medium py-2.5 px-4 rounded-lg transition-all duration-200 flex items-center justify-center space-x-2 shadow-sm hover:shadow-md"
    >
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
      <span>Настроить</span>
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import Toggle from './Toggle.vue'

const props = defineProps({
  account: {
    type: Object,
    required: true
  },
  loading: {
    type: Boolean,
    default: false
  }
})

defineEmits(['configure', 'toggle-sync'])

// Truncate account ID for display
const truncatedAccountId = computed(() => {
  if (!props.account.account_id) return ''
  const id = props.account.account_id
  return id.length > 16 ? `${id.substring(0, 8)}...${id.substring(id.length - 4)}` : id
})

// Status badge styling
const statusClasses = computed(() => {
  const status = props.account.status
  return {
    'activated': 'bg-green-100 text-green-800',
    'suspended': 'bg-yellow-100 text-yellow-800',
    'uninstalled': 'bg-red-100 text-red-800'
  }[status] || 'bg-gray-100 text-gray-800'
})

const statusText = computed(() => {
  const status = props.account.status
  return {
    'activated': 'Активен',
    'suspended': 'Приостановлен',
    'uninstalled': 'Удален'
  }[status] || status
})
</script>
