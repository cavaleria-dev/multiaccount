<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition ease-out duration-200"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition ease-in duration-150"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto"
        @click.self="closeModal"
      >
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"></div>

        <!-- Modal -->
        <div class="flex min-h-full items-center justify-center p-4">
          <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
          >
            <div
              v-if="show"
              class="relative bg-white rounded-lg shadow-xl w-full max-w-md transform transition-all"
            >
              <!-- Header -->
              <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                  <h3 class="text-lg font-semibold text-gray-900">
                    Создать новый канал продаж
                  </h3>
                  <button
                    @click="closeModal"
                    type="button"
                    class="text-gray-400 hover:text-gray-600 transition-colors"
                  >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Body -->
              <form @submit.prevent="handleSubmit">
                <div class="px-6 py-4 space-y-4">
                  <!-- Name field -->
                  <div>
                    <label for="channel-name" class="block text-sm font-medium text-gray-700 mb-1">
                      Название <span class="text-red-500">*</span>
                    </label>
                    <input
                      id="channel-name"
                      v-model="formData.name"
                      type="text"
                      required
                      maxlength="255"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                      :class="{ 'border-red-500': errors.name }"
                      placeholder="Введите название канала продаж"
                    />
                    <p v-if="errors.name" class="mt-1 text-sm text-red-600">
                      {{ errors.name }}
                    </p>
                  </div>

                  <!-- Type field -->
                  <div>
                    <SimpleSelect
                      v-model="formData.type"
                      label="Тип"
                      placeholder="Выберите тип"
                      :options="channelTypeOptions"
                      required
                    />
                    <p v-if="errors.type" class="mt-1 text-sm text-red-600">
                      {{ errors.type }}
                    </p>
                  </div>

                  <!-- Description field -->
                  <div>
                    <label for="channel-description" class="block text-sm font-medium text-gray-700 mb-1">
                      Описание
                    </label>
                    <textarea
                      id="channel-description"
                      v-model="formData.description"
                      rows="3"
                      maxlength="4096"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                      placeholder="Введите описание канала продаж (необязательно)"
                    ></textarea>
                  </div>

                  <!-- Error message -->
                  <div v-if="errors.general" class="p-3 bg-red-50 border border-red-200 rounded-lg">
                    <p class="text-sm text-red-600">{{ errors.general }}</p>
                  </div>
                </div>

                <!-- Footer -->
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                  <button
                    @click="closeModal"
                    type="button"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
                    :disabled="loading"
                  >
                    Отмена
                  </button>
                  <button
                    type="submit"
                    class="px-4 py-2 text-sm font-medium text-white bg-gradient-to-r from-indigo-500 to-purple-600 rounded-lg hover:from-indigo-600 hover:to-purple-700 transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-2"
                    :disabled="loading"
                  >
                    <svg
                      v-if="loading"
                      class="animate-spin h-4 w-4"
                      fill="none"
                      viewBox="0 0 24 24"
                    >
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>{{ loading ? 'Создание...' : 'Создать' }}</span>
                  </button>
                </div>
              </form>
            </div>
          </Transition>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, watch } from 'vue'
import SimpleSelect from './SimpleSelect.vue'

// Channel type options
const channelTypeOptions = [
  { id: 'MESSENGER', name: 'Мессенджер' },
  { id: 'SOCIAL_NETWORK', name: 'Соцсеть' },
  { id: 'MARKETPLACE', name: 'Маркетплейс' },
  { id: 'ECOMMERCE', name: 'Интернет-магазин' },
  { id: 'CLASSIFIED_ADS', name: 'Доска объявлений' },
  { id: 'DIRECT_SALES', name: 'Прямые продажи' },
  { id: 'OTHER', name: 'Другое' }
]

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close', 'created'])

const loading = ref(false)
const formData = ref({
  name: '',
  type: '',
  description: ''
})
const errors = ref({
  name: null,
  type: null,
  description: null,
  general: null
})

// Reset form when modal is opened
watch(() => props.show, (newValue) => {
  if (newValue) {
    formData.value = {
      name: '',
      type: '',
      description: ''
    }
    errors.value = {
      name: null,
      type: null,
      description: null,
      general: null
    }
  }
})

const closeModal = () => {
  if (!loading.value) {
    emit('close')
  }
}

const handleSubmit = async () => {
  // Clear previous errors
  errors.value = {
    name: null,
    type: null,
    description: null,
    general: null
  }

  // Validate
  if (!formData.value.name || formData.value.name.trim().length === 0) {
    errors.value.name = 'Название обязательно для заполнения'
    return
  }

  if (formData.value.name.length > 255) {
    errors.value.name = 'Название не может быть длиннее 255 символов'
    return
  }

  if (!formData.value.type) {
    errors.value.type = 'Тип обязателен для выбора'
    return
  }

  if (formData.value.description && formData.value.description.length > 4096) {
    errors.value.description = 'Описание не может быть длиннее 4096 символов'
    return
  }

  // Emit data for parent to handle API call
  emit('created', {
    name: formData.value.name.trim(),
    type: formData.value.type,
    description: formData.value.description?.trim() || null
  })
}

// Expose method for parent to set loading state
defineExpose({
  setLoading: (value) => {
    loading.value = value
  },
  setError: (message) => {
    errors.value.general = message
  }
})
</script>
