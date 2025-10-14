<template>
  <!-- Modal backdrop -->
  <Transition name="fade">
    <div
      v-if="isOpen"
      class="fixed inset-0 z-50 overflow-y-auto"
      @click.self="cancel"
    >
      <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
        <!-- Modal backdrop overlay -->
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

        <!-- Modal panel -->
        <Transition name="modal">
          <div
            v-if="isOpen"
            class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl"
          >
            <!-- Header -->
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6">
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium leading-6 text-gray-900">
                  Выбор групп товаров
                </h3>
                <button
                  type="button"
                  @click="cancel"
                  class="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  <span class="sr-only">Закрыть</span>
                  <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              <!-- Content -->
              <div class="mt-3">
                <!-- Loading state -->
                <div v-if="loading" class="text-center py-12">
                  <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                  <p class="text-sm text-gray-500 mt-3">Загрузка групп товаров...</p>
                </div>

                <!-- Empty state -->
                <div v-else-if="folders.length === 0" class="text-center py-12">
                  <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                  </svg>
                  <p class="text-sm text-gray-500 mt-3">Групп товаров не найдено</p>
                </div>

                <!-- Folder tree -->
                <div v-else class="max-h-96 overflow-y-auto border border-gray-200 rounded-md p-4">
                  <FolderTreeNode
                    v-for="folder in folders"
                    :key="folder.id"
                    :folder="folder"
                    :selected="selectedIds"
                    @toggle="toggleFolder"
                  />
                </div>

                <!-- Selected count -->
                <div v-if="selectedIds.length > 0" class="mt-3 text-sm text-gray-600">
                  Выбрано групп: <span class="font-medium text-indigo-600">{{ selectedIds.length }}</span>
                </div>
              </div>
            </div>

            <!-- Footer -->
            <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
              <button
                type="button"
                @click="confirm"
                class="inline-flex w-full justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:ml-3 sm:w-auto sm:text-sm transition-colors"
              >
                Выбрать ({{ selectedIds.length }})
              </button>
              <button
                type="button"
                @click="cancel"
                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:mt-0 sm:w-auto sm:text-sm transition-colors"
              >
                Отмена
              </button>
            </div>
          </div>
        </Transition>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, watch } from 'vue'
import FolderTreeNode from './FolderTreeNode.vue'

const props = defineProps({
  modelValue: {
    type: Array,
    default: () => []
  },
  folders: {
    type: Array,
    default: () => []
  },
  loading: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue', 'confirm', 'cancel'])

const isOpen = ref(false)
const selectedIds = ref([...props.modelValue])

// Синхронизация с modelValue
watch(() => props.modelValue, (newVal) => {
  selectedIds.value = [...newVal]
})

const toggleFolder = (folderId) => {
  const index = selectedIds.value.indexOf(folderId)
  if (index > -1) {
    selectedIds.value.splice(index, 1)
  } else {
    selectedIds.value.push(folderId)
  }
}

const confirm = () => {
  emit('update:modelValue', selectedIds.value)
  emit('confirm', selectedIds.value)
  isOpen.value = false
}

const cancel = () => {
  // Сброс выбора к исходному состоянию
  selectedIds.value = [...props.modelValue]
  emit('cancel')
  isOpen.value = false
}

const open = () => {
  selectedIds.value = [...props.modelValue]
  isOpen.value = true
}

// Expose метод open для вызова из родительского компонента
defineExpose({ open })
</script>

<style scoped>
/* Transitions */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.modal-enter-active,
.modal-leave-active {
  transition: all 0.3s ease;
}

.modal-enter-from {
  opacity: 0;
  transform: scale(0.95);
}

.modal-leave-to {
  opacity: 0;
  transform: scale(0.95);
}
</style>
