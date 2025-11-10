<template>
  <div class="space-y-6">
    <!-- Loading -->
    <div v-if="loading" class="bg-white shadow rounded-lg p-8 text-center">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
      <p class="mt-2 text-sm text-gray-500">Загрузка настроек...</p>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4">
      <p class="text-sm text-red-800 font-medium">{{ error }}</p>
    </div>

    <!-- Form -->
    <form v-else @submit.prevent="saveSettings" class="space-y-6">
      <!-- Main Sync Toggle -->
      <div
        class="shadow-lg rounded-xl p-6 transition-all duration-300"
        :class="settings.sync_enabled ? 'bg-gradient-to-r from-indigo-600 to-purple-700' : 'bg-gradient-to-r from-gray-400 to-gray-500'"
      >
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-4">
            <div class="bg-white/90 rounded-lg p-3 shadow">
              <svg
                class="h-8 w-8 transition-colors duration-300"
                :class="settings.sync_enabled ? 'text-indigo-600' : 'text-gray-500'"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
            </div>
            <div>
              <h3 class="text-lg font-semibold text-white">Глобальная синхронизация</h3>
              <p class="text-sm text-white/80 mt-1">Управление всей синхронизацией для этого аккаунта</p>
            </div>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input
              v-model="settings.sync_enabled"
              type="checkbox"
              class="sr-only peer"
            />
            <div class="w-16 h-8 bg-white/30 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-white/40 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:rounded-full after:h-7 after:w-7 after:transition-all after:shadow-md peer-checked:bg-white/90"></div>
            <span class="ml-3 text-base font-medium text-white">{{ settings.sync_enabled ? 'Включена' : 'Выключена' }}</span>
          </label>
        </div>
      </div>

      <!-- VAT Sync Section -->
      <VatSyncSection
        :settings="settings"
        @update:settings="handleVatSettingsUpdate"
      />

      <!-- Auto-create Section -->
      <AutoCreateSection v-model:settings="settings" />

      <!-- Save Button -->
      <div class="flex justify-end">
        <button
          type="submit"
          :disabled="saving"
          class="inline-flex justify-center rounded-lg border border-transparent bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 px-6 py-2.5 text-sm font-medium text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 transition-all duration-200"
        >
          <svg v-if="saving" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span v-if="saving">Сохранение...</span>
          <span v-else>Сохранить настройки</span>
        </button>
      </div>

      <!-- Danger Zone -->
      <div class="border-t-2 border-gray-200 pt-6 mt-8">
        <div class="bg-red-50 border-2 border-red-200 rounded-xl p-6">
          <div class="flex items-start">
            <div class="flex-shrink-0">
              <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <div class="ml-4 flex-1">
              <h3 class="text-lg font-semibold text-red-900">Опасная зона</h3>
              <p class="mt-2 text-sm text-red-700">
                Удаление дочернего аккаунта приведет к отключению синхронизации и удалению всех настроек.
                Это действие невозможно отменить.
              </p>
              <button
                type="button"
                @click="confirmDelete"
                class="mt-4 inline-flex items-center px-4 py-2 border border-red-300 rounded-lg text-sm font-medium text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-all duration-200"
              >
                <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Удалить дочерний аккаунт
              </button>
            </div>
          </div>
        </div>
      </div>
    </form>

    <!-- Success Message -->
    <div v-if="saveSuccess" class="fixed bottom-4 right-4 bg-green-50 border border-green-200 rounded-lg p-4 shadow-lg animate-slide-up z-50">
      <div class="flex items-center">
        <svg class="h-5 w-5 text-green-400 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-sm text-green-800 font-medium">Настройки успешно сохранены</p>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteConfirm" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg p-6 max-w-md w-full shadow-xl">
        <div class="flex items-start">
          <div class="flex-shrink-0">
            <svg class="h-8 w-8 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </div>
          <div class="ml-4">
            <h3 class="text-lg font-semibold text-gray-900">Подтверждение удаления</h3>
            <p class="mt-2 text-sm text-gray-600">
              Вы уверены, что хотите удалить этот дочерний аккаунт?
              Все настройки синхронизации будут потеряны.
            </p>
            <div class="mt-6 flex space-x-3">
              <button
                @click="showDeleteConfirm = false"
                class="flex-1 inline-flex justify-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
              >
                Отмена
              </button>
              <button
                @click="deleteAccount"
                :disabled="deleting"
                class="flex-1 inline-flex justify-center px-4 py-2 border border-transparent rounded-lg text-sm font-medium text-white bg-red-600 hover:bg-red-700 disabled:opacity-50"
              >
                <span v-if="deleting">Удаление...</span>
                <span v-else>Удалить</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../api'
import VatSyncSection from '../../components/franchise-settings/VatSyncSection.vue'
import AutoCreateSection from '../../components/franchise-settings/AutoCreateSection.vue'

const props = defineProps({
  accountId: {
    type: String,
    required: true
  }
})

const router = useRouter()

// State
const loading = ref(false)
const saving = ref(false)
const deleting = ref(false)
const error = ref(null)
const saveSuccess = ref(false)
const showDeleteConfirm = ref(false)

const settings = ref({
  sync_enabled: true,
  sync_vat: false,
  vat_sync_mode: 'preserve_child',
  create_product_folders: true,
  auto_create_attributes: true,
  auto_create_characteristics: true,
  auto_create_price_types: true
})

// Load settings
const loadSettings = async () => {
  try {
    loading.value = true
    error.value = null

    const response = await api.syncSettings.get(props.accountId)
    const data = response.data

    // Update settings
    Object.keys(settings.value).forEach(key => {
      if (data[key] !== undefined) {
        settings.value[key] = data[key]
      }
    })

  } catch (err) {
    console.error('Failed to load settings:', err)
    error.value = 'Не удалось загрузить настройки'
  } finally {
    loading.value = false
  }
}

// VAT settings update handler
const handleVatSettingsUpdate = (updates) => {
  settings.value = { ...settings.value, ...updates }
}

// Save settings
const saveSettings = async () => {
  try {
    saving.value = true
    error.value = null

    await api.syncSettings.update(props.accountId, settings.value)

    // Show success message
    saveSuccess.value = true
    setTimeout(() => {
      saveSuccess.value = false
    }, 3000)
  } catch (err) {
    console.error('Failed to save settings:', err)
    error.value = err.response?.data?.error || 'Не удалось сохранить настройки'
  } finally {
    saving.value = false
  }
}

// Delete confirmation
const confirmDelete = () => {
  showDeleteConfirm.value = true
}

// Delete account
const deleteAccount = async () => {
  try {
    deleting.value = true

    await api.childAccounts.delete(props.accountId)

    // Redirect to accounts list
    router.push('/app/accounts')
  } catch (err) {
    console.error('Failed to delete account:', err)
    alert('Не удалось удалить аккаунт: ' + (err.response?.data?.error || err.message))
  } finally {
    deleting.value = false
    showDeleteConfirm.value = false
  }
}

onMounted(() => {
  loadSettings()
})
</script>

<style scoped>
@keyframes slide-up {
  from {
    transform: translateY(100%);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}

.animate-slide-up {
  animation: slide-up 0.3s ease-out;
}
</style>
