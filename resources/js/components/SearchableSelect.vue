<template>
  <div class="relative">
    <label v-if="label" class="block text-sm font-medium text-gray-700 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>

    <div class="relative">
      <!-- Selected value display / Search input -->
      <div
        @click="toggleDropdown"
        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 cursor-pointer bg-white hover:border-gray-400 transition-colors"
        :class="{ 'ring-2 ring-indigo-500 border-indigo-500': isOpen }"
      >
        <div class="flex items-center justify-between">
          <div v-if="selectedOption" class="flex items-center space-x-2">
            <div
              v-if="showColor && selectedOption.color"
              class="w-4 h-4 rounded-full border border-gray-300 flex-shrink-0"
              :style="{ backgroundColor: intToHex(selectedOption.color) }"
            ></div>
            <span class="text-gray-900">{{ selectedOption.name }}</span>
          </div>
          <span v-else class="text-gray-400">
            {{ placeholder }}
          </span>

          <div class="flex items-center space-x-2">
            <!-- Clear button -->
            <button
              v-if="selectedOption && !disabled"
              @click.stop="clearSelection"
              class="text-gray-400 hover:text-gray-600 transition-colors"
              type="button"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>

            <!-- Dropdown arrow -->
            <svg
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
          class="absolute z-50 mt-1 w-full bg-white border border-gray-300 rounded-lg shadow-lg max-h-80 overflow-y-auto"
        >
          <!-- Search input -->
          <div class="p-2 border-b border-gray-200">
            <input
              ref="searchInput"
              v-model="searchQuery"
              type="text"
              placeholder="Поиск..."
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              @click.stop
            />
          </div>

          <!-- Create new button -->
          <div v-if="canCreate" class="p-2 border-b border-gray-200">
            <button
              @click.stop="$emit('create')"
              type="button"
              class="w-full px-3 py-2 text-sm font-medium text-indigo-600 bg-indigo-50 rounded-md hover:bg-indigo-100 transition-colors flex items-center justify-center space-x-2"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              <span>Создать новый</span>
            </button>
          </div>

          <!-- Options list -->
          <div class="max-h-full">
            <!-- Loading state -->
            <div v-if="loading" class="p-4 text-center text-gray-500">
              <svg class="animate-spin h-5 w-5 mx-auto text-indigo-500" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span class="block mt-2 text-sm">Загрузка...</span>
            </div>

            <!-- Error state -->
            <div v-else-if="error" class="p-4 text-center text-red-500">
              <svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span class="block mt-2 text-sm">{{ error }}</span>
            </div>

            <!-- Empty state -->
            <div v-else-if="filteredOptions.length === 0" class="p-4 text-center text-gray-500">
              <span class="text-sm">Ничего не найдено</span>
            </div>

            <!-- Options -->
            <button
              v-else
              v-for="option in filteredOptions"
              :key="option.id"
              @click="selectOption(option)"
              type="button"
              class="w-full px-4 py-2 text-left hover:bg-indigo-50 transition-colors flex items-center justify-between"
              :class="{ 'bg-indigo-100': modelValue === option.id }"
            >
              <div class="flex items-center space-x-2 flex-1 min-w-0">
                <div
                  v-if="showColor && option.color"
                  class="w-4 h-4 rounded-full border border-gray-300 flex-shrink-0"
                  :style="{ backgroundColor: intToHex(option.color) }"
                ></div>
                <span class="text-gray-900 truncate">{{ option.name }}</span>
              </div>
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
        </div>
      </Transition>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'

const props = defineProps({
  modelValue: {
    type: String,
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
    default: () => []
  },
  loading: {
    type: Boolean,
    default: false
  },
  error: {
    type: String,
    default: null
  },
  canCreate: {
    type: Boolean,
    default: false
  },
  required: {
    type: Boolean,
    default: false
  },
  disabled: {
    type: Boolean,
    default: false
  },
  // Initial display value (from target_objects_meta)
  initialName: {
    type: String,
    default: null
  },
  // Show color indicator for options with color property
  showColor: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue', 'open', 'create', 'clear'])

// Convert RGB integer to hex color for display
// МойСклад format: RGB as single integer (R << 16) | (G << 8) | B
const intToHex = (colorInt) => {
  if (!colorInt && colorInt !== 0) {
    return '#cccccc'
  }

  const r = (colorInt >> 16) & 0xFF
  const g = (colorInt >> 8) & 0xFF
  const b = colorInt & 0xFF

  return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`
}

const isOpen = ref(false)
const searchQuery = ref('')
const searchInput = ref(null)

// Selected option from current options list or initial value
const selectedOption = computed(() => {
  if (props.modelValue) {
    const found = props.options.find(opt => opt.id === props.modelValue)
    if (found) {
      return found
    }
    // If value is set but option not in list, show initial name
    if (props.initialName) {
      return { id: props.modelValue, name: props.initialName }
    }
  }
  return null
})

// Filter options based on search query
const filteredOptions = computed(() => {
  if (!searchQuery.value) {
    return props.options
  }
  const query = searchQuery.value.toLowerCase()
  return props.options.filter(opt =>
    opt.name.toLowerCase().includes(query)
  )
})

// Toggle dropdown open/closed
const toggleDropdown = async () => {
  if (props.disabled) return

  isOpen.value = !isOpen.value

  if (isOpen.value) {
    // Emit open event to trigger lazy loading
    emit('open')

    // Focus search input after dropdown opens
    await nextTick()
    searchInput.value?.focus()
  } else {
    // Clear search when closing
    searchQuery.value = ''
  }
}

// Select an option
const selectOption = (option) => {
  emit('update:modelValue', option.id)
  isOpen.value = false
  searchQuery.value = ''
}

// Clear selection
const clearSelection = () => {
  emit('update:modelValue', null)
  emit('clear')
}

// Close dropdown when clicking outside
const handleClickOutside = (event) => {
  const dropdown = event.target.closest('.relative')
  if (!dropdown && isOpen.value) {
    isOpen.value = false
    searchQuery.value = ''
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
