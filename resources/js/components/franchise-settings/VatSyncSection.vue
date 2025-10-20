<template>
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">НДС и налогообложение</h3>

    <div class="space-y-4">
      <!-- Enable VAT Sync -->
      <div class="flex items-start">
        <div class="flex items-center h-5">
          <input
            id="sync-vat"
            type="checkbox"
            :checked="localSettings.sync_vat"
            @change="handleSyncVatChange"
            class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
          >
        </div>
        <div class="ml-3">
          <label for="sync-vat" class="font-medium text-gray-900">
            Синхронизировать НДС
          </label>
          <p class="text-sm text-gray-500">
            Включить синхронизацию настроек НДС из главного аккаунта
          </p>
        </div>
      </div>

      <!-- VAT Sync Mode (only visible when sync_vat enabled) -->
      <div v-if="localSettings.sync_vat" class="ml-7 p-4 bg-gray-50 rounded-lg border border-gray-200">
        <div class="space-y-3">
          <label class="block text-sm font-medium text-gray-900 mb-2">
            Режим синхронизации НДС
          </label>

          <!-- Mode: from_main -->
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="vat-from-main"
                type="radio"
                value="from_main"
                :checked="localSettings.vat_sync_mode === 'from_main'"
                @change="handleVatSyncModeChange"
                class="w-4 h-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
              >
            </div>
            <div class="ml-3">
              <label for="vat-from-main" class="font-medium text-gray-900">
                Брать НДС из главного аккаунта
              </label>
              <p class="text-sm text-gray-500">
                Значения НДС, включение НДС и использование родительского НДС будут взяты из главного аккаунта
              </p>
            </div>
          </div>

          <!-- Mode: preserve_child -->
          <div class="flex items-start">
            <div class="flex items-center h-5">
              <input
                id="vat-preserve-child"
                type="radio"
                value="preserve_child"
                :checked="localSettings.vat_sync_mode === 'preserve_child'"
                @change="handleVatSyncModeChange"
                class="w-4 h-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
              >
            </div>
            <div class="ml-3">
              <label for="vat-preserve-child" class="font-medium text-gray-900">
                Сохранять настройки дочернего аккаунта
              </label>
              <p class="text-sm text-gray-500">
                Настройки НДС дочернего аккаунта не будут изменены (по умолчанию)
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Info about other fields -->
      <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
            </svg>
          </div>
          <div class="ml-3 flex-1">
            <h3 class="text-sm font-medium text-blue-800">
              Автоматическая синхронизация дополнительных полей
            </h3>
            <div class="mt-2 text-sm text-blue-700">
              <p class="mb-2">Следующие поля синхронизируются автоматически (без настроек):</p>
              <ul class="list-disc list-inside space-y-1 ml-2">
                <li><strong>Система налогообложения</strong> (taxSystem)</li>
                <li><strong>Признак предмета расчета</strong> (paymentItemType)</li>
                <li><strong>Физические характеристики:</strong> вес, объём</li>
                <li><strong>Особенности учёта:</strong> весовой товар, разливной товар</li>
                <li><strong>Маркировка:</strong> тип маркировки, СИЗ, частичное выбытие, ТНВЭД</li>
                <li><strong>Алкогольная продукция</strong> (excise, type, strength, volume)</li>
                <li><strong>Узбекистан:</strong> маркировка и ТАСНИФ</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  settings: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['update:settings'])

// Local copy of settings
const localSettings = ref({
  sync_vat: props.settings.sync_vat ?? false,
  vat_sync_mode: props.settings.vat_sync_mode ?? 'preserve_child'
})

// Watch for external changes
watch(() => props.settings, (newSettings) => {
  localSettings.value = {
    sync_vat: newSettings.sync_vat ?? false,
    vat_sync_mode: newSettings.vat_sync_mode ?? 'preserve_child'
  }
}, { deep: true })

// Handlers
const handleSyncVatChange = (event) => {
  const enabled = event.target.checked
  localSettings.value.sync_vat = enabled

  // If disabling, reset mode to default
  if (!enabled) {
    localSettings.value.vat_sync_mode = 'preserve_child'
  }

  emit('update:settings', localSettings.value)
}

const handleVatSyncModeChange = (event) => {
  localSettings.value.vat_sync_mode = event.target.value
  emit('update:settings', localSettings.value)
}
</script>
