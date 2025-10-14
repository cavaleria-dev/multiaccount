<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold text-gray-900">Общие настройки</h1>
      <p class="mt-2 text-sm text-gray-700">
        Настройки приложения и параметры по умолчанию для всех франшиз
      </p>
    </div>

    <form @submit.prevent="saveSettings" class="space-y-6">
      <!-- Тип аккаунта -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Тип аккаунта</h3>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Этот аккаунт является:</label>
            <div class="flex items-center space-x-4">
              <label class="inline-flex items-center">
                <input
                  type="radio"
                  v-model="settings.account_type"
                  value="main"
                  class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300"
                />
                <span class="ml-2 text-sm text-gray-700">Главный аккаунт</span>
              </label>
              <label class="inline-flex items-center">
                <input
                  type="radio"
                  v-model="settings.account_type"
                  value="child"
                  class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300"
                />
                <span class="ml-2 text-sm text-gray-700">Дочерний аккаунт (франшиза)</span>
              </label>
            </div>
            <p class="mt-2 text-xs text-gray-500">
              <strong>Главный:</strong> управляет франшизами, отправляет товары<br>
              <strong>Дочерний:</strong> получает товары, отправляет заказы
            </p>
          </div>
        </div>
      </div>

      <!-- Настройки по умолчанию -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Настройки по умолчанию для новых франшиз</h3>
        <div class="space-y-4">
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="default_sync_enabled"
                v-model="settings.default_sync_enabled"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="default_sync_enabled" class="font-medium text-gray-700">Автоматически включать синхронизацию</label>
              <p class="text-gray-500">При добавлении новой франшизы синхронизация будет включена сразу</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="default_sync_products"
                v-model="settings.default_sync_products"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="default_sync_products" class="font-medium text-gray-700">Синхронизировать товары</label>
              <p class="text-gray-500">По умолчанию включать синхронизацию товаров</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="default_sync_orders"
                v-model="settings.default_sync_orders"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="default_sync_orders" class="font-medium text-gray-700">Синхронизировать заказы</label>
              <p class="text-gray-500">По умолчанию включать синхронизацию заказов покупателей</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="default_sync_images"
                v-model="settings.default_sync_images"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="default_sync_images" class="font-medium text-gray-700">Синхронизировать изображения</label>
              <p class="text-gray-500">По умолчанию включать синхронизацию изображений товаров</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Лимиты и производительность -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Лимиты и производительность</h3>
        <div class="space-y-4">
          <div>
            <label for="max_franchises" class="block text-sm font-medium text-gray-700">Максимум франшиз</label>
            <input
              type="number"
              id="max_franchises"
              v-model.number="settings.max_franchises"
              min="1"
              max="100"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            />
            <p class="mt-1 text-xs text-gray-500">Максимальное количество франшиз для этого аккаунта (1-100)</p>
          </div>

          <div>
            <label for="sync_interval" class="block text-sm font-medium text-gray-700">Интервал проверки очереди (секунды)</label>
            <input
              type="number"
              id="sync_interval"
              v-model.number="settings.sync_interval"
              min="10"
              max="300"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            />
            <p class="mt-1 text-xs text-gray-500">Как часто проверять очередь синхронизации (10-300 секунд)</p>
          </div>

          <div>
            <label for="batch_size" class="block text-sm font-medium text-gray-700">Размер батча</label>
            <input
              type="number"
              id="batch_size"
              v-model.number="settings.batch_size"
              min="10"
              max="1000"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            />
            <p class="mt-1 text-xs text-gray-500">Количество элементов для обработки за раз (10-1000)</p>
          </div>
        </div>
      </div>

      <!-- Уведомления -->
      <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Уведомления</h3>
        <div class="space-y-4">
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="notify_errors"
                v-model="settings.notify_errors"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="notify_errors" class="font-medium text-gray-700">Уведомлять об ошибках</label>
              <p class="text-gray-500">Отправлять уведомления при ошибках синхронизации</p>
            </div>
          </div>

          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="notify_success"
                v-model="settings.notify_success"
                type="checkbox"
                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
            </div>
            <div class="ml-3 text-sm">
              <label for="notify_success" class="font-medium text-gray-700">Уведомлять об успешной синхронизации</label>
              <p class="text-gray-500">Отправлять уведомления при успешной синхронизации</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Кнопки -->
      <div class="flex justify-end space-x-3">
        <button
          type="button"
          @click="resetSettings"
          class="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          Сбросить
        </button>
        <button
          type="submit"
          :disabled="saving"
          class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
        >
          <span v-if="saving">Сохранение...</span>
          <span v-else>Сохранить настройки</span>
        </button>
      </div>
    </form>

    <!-- Сообщение об успешном сохранении -->
    <div v-if="saveSuccess" class="fixed bottom-4 right-4 bg-green-50 border border-green-200 rounded-lg p-4 shadow-lg">
      <p class="text-sm text-green-800">✓ Настройки успешно сохранены</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const saving = ref(false)
const saveSuccess = ref(false)

const settings = ref({
  account_type: 'main',
  default_sync_enabled: true,
  default_sync_products: true,
  default_sync_orders: false,
  default_sync_images: true,
  max_franchises: 10,
  sync_interval: 60,
  batch_size: 100,
  notify_errors: true,
  notify_success: false
})

// Загрузка настроек
onMounted(() => {
  // TODO: Загрузка общих настроек из localStorage или API
  const saved = localStorage.getItem('general_settings')
  if (saved) {
    try {
      const parsed = JSON.parse(saved)
      Object.assign(settings.value, parsed)
    } catch (e) {
      console.error('Failed to load settings:', e)
    }
  }
})

function saveSettings() {
  try {
    saving.value = true

    // TODO: Сохранение через API
    // Пока сохраняем в localStorage
    localStorage.setItem('general_settings', JSON.stringify(settings.value))

    // Показать сообщение об успехе
    saveSuccess.value = true
    setTimeout(() => {
      saveSuccess.value = false
    }, 3000)

  } catch (err) {
    console.error('Failed to save settings:', err)
    alert('Не удалось сохранить настройки')
  } finally {
    saving.value = false
  }
}

function resetSettings() {
  if (confirm('Сбросить все настройки на значения по умолчанию?')) {
    settings.value = {
      account_type: 'main',
      default_sync_enabled: true,
      default_sync_products: true,
      default_sync_orders: false,
      default_sync_images: true,
      max_franchises: 10,
      sync_interval: 60,
      batch_size: 100,
      notify_errors: true,
      notify_success: false
    }
  }
}
</script>
