<template>
  <div class="relative">
    <label v-if="label" class="block text-sm font-medium text-gray-700 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>

    <div class="relative">
      <!-- Selected value display -->
      <div
        @click="toggleDropdown"
        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 cursor-pointer bg-white hover:border-gray-400 transition-colors"
        :class="{
          'ring-2 ring-indigo-500 border-indigo-500': isOpen,
          'opacity-50 cursor-not-allowed': disabled
        }"
      >
        <div class="flex items-center justify-between">
          <span v-if="selectedOption" class="text-gray-900">
            {{ selectedOption.name }}
          </span>
          <span v-else class="text-gray-400">
            {{ placeholder }}
          </span>

          <div class="flex items-center space-x-2">
            <!-- Clear button -->
            <button
              v-if="selectedOption && !disabled && !loading"
              @click.stop="clearSelection"
              class="text-gray-400 hover:text-gray-600 transition-colors"
              type="button"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>

            <!-- Loading spinner -->
            <div
              v-if="loading"
              class="animate-spin rounded-full h-4 w-4 border-2 border-indigo-600 border-t-transparent"
              title="Загрузка..."
            ></div>

            <!-- Dropdown arrow -->
            <svg
              v-else
              class="w-5 h-5 text-gray-400 transition-transform duration-200"
              :class="{ 'transform rotate-180': isOpen }"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Dropdown menu -->
      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="transform opacity-0 scale-95"
        enter-to-class="transform opacity-100 scale-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="transform opacity-100 scale-100"
        leave-to-class="transform opacity-0 scale-95"
      >
        <div
          v-if="isOpen"
          class="absolute z-50 mt-1 w-full bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-auto"
        >
          <!-- Options list -->
          <button
            v-for="option in options"
            :key="option.id"
            @click="selectOption(option)"
            type="button"
            class="w-full px-4 py-2 text-left hover:bg-indigo-50 transition-colors flex items-center justify-between"
            :class="{ 'bg-indigo-100': modelValue === option.id }"
          >
            <span class="text-gray-900">{{ option.name }}</span>
            <svg
              v-if="modelValue === option.id"
              class="w-5 h-5 text-indigo-600 flex-shrink-0 ml-2"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
          </button>
        </div>
      </Transition>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: [String, Number, Boolean],
    default: null
  },
  label: {
    type: String,
    default: ''
  },
  placeholder: {
    type: String,
    default: 'Выберите значение'
  },
  options: {
    type: Array,
    default: () => [],
    required: true
  },
  disabled: {
    type: Boolean,
    default: false
  },
  required: {
    type: Boolean,
    default: false
  },
  loading: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue'])

const isOpen = ref(false)

// Selected option from current options list
const selectedOption = computed(() => {
  if (props.modelValue !== null && props.modelValue !== undefined && props.modelValue !== '') {
    return props.options.find(opt => opt.id === props.modelValue)
  }
  return null
})

// Toggle dropdown open/closed
const toggleDropdown = () => {
  if (props.disabled) return
  isOpen.value = !isOpen.value
}

// Select an option
const selectOption = (option) => {
  emit('update:modelValue', option.id)
  isOpen.value = false
}

// Clear selection
const clearSelection = () => {
  emit('update:modelValue', null)
}

// Close dropdown when clicking outside
const handleClickOutside = (event) => {
  const dropdown = event.target.closest('.relative')
  if (!dropdown && isOpen.value) {
    isOpen.value = false
  }
}

// Watch for dropdown state changes
watch(isOpen, (newValue) => {
  if (newValue) {
    document.addEventListener('click', handleClickOutside)
  } else {
    document.removeEventListener('click', handleClickOutside)
  }
})
</script>
