<template>
  <div class="space-y-2">
    <label v-if="label" class="block text-sm font-medium text-gray-700">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>

    <div class="flex items-center space-x-3">
      <!-- Color input -->
      <div class="relative">
        <input
          type="color"
          :value="hexColor"
          @input="handleColorInput"
          class="w-16 h-10 rounded-lg cursor-pointer border-2 border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500 focus:outline-none"
        />
      </div>

      <!-- Color preview with border -->
      <div class="flex items-center space-x-2">
        <div
          class="w-10 h-10 rounded-lg border-2 border-gray-300 shadow-sm"
          :style="{ backgroundColor: hexColor }"
        ></div>
        <span class="text-sm font-mono text-gray-600">{{ hexColor.toUpperCase() }}</span>
      </div>

      <!-- Preset colors -->
      <div class="flex items-center space-x-2">
        <button
          v-for="preset in presetColors"
          :key="preset.hex"
          type="button"
          @click="selectPreset(preset.hex)"
          class="w-8 h-8 rounded-lg border-2 transition-all hover:scale-110"
          :class="hexColor.toLowerCase() === preset.hex.toLowerCase() ? 'border-indigo-500 ring-2 ring-indigo-300' : 'border-gray-300'"
          :style="{ backgroundColor: preset.hex }"
          :title="preset.name"
        ></button>
      </div>
    </div>

    <p v-if="description" class="text-xs text-gray-500">
      {{ description }}
    </p>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: Number, // RGB integer format (МойСклад: (R << 16) | (G << 8) | B)
    default: null
  },
  label: {
    type: String,
    default: ''
  },
  description: {
    type: String,
    default: ''
  },
  required: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue'])

// Preset colors for МойСклад statuses
const presetColors = [
  { name: 'Синий', hex: '#007AFF' },
  { name: 'Зелёный', hex: '#34C759' },
  { name: 'Оранжевый', hex: '#FF9500' },
  { name: 'Красный', hex: '#FF3B30' },
  { name: 'Фиолетовый', hex: '#AF52DE' },
  { name: 'Бирюзовый', hex: '#5AC8FA' },
  { name: 'Серый', hex: '#8E8E93' }
]

// Convert RGB integer to hex color
// МойСклад uses RGB format (not ARGB), example: rgb(162, 198, 23) = 10667543
const intToHex = (colorInt) => {
  if (!colorInt && colorInt !== 0) {
    return '#007AFF' // Default blue
  }

  // Extract RGB bytes (no alpha channel in МойСклад format)
  const r = (colorInt >> 16) & 0xFF
  const g = (colorInt >> 8) & 0xFF
  const b = colorInt & 0xFF

  return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`
}

// Convert hex color to RGB integer (МойСклад format)
// Example: #A2C617 → 10667543
const hexToInt = (hex) => {
  const r = parseInt(hex.slice(1, 3), 16)
  const g = parseInt(hex.slice(3, 5), 16)
  const b = parseInt(hex.slice(5, 7), 16)

  // МойСклад format: RGB as single integer (no alpha channel)
  return (r << 16) | (g << 8) | b
}

// Computed hex color from RGB integer
const hexColor = computed(() => intToHex(props.modelValue))

// Handle color input change
const handleColorInput = (event) => {
  const hex = event.target.value
  const colorInt = hexToInt(hex)
  emit('update:modelValue', colorInt)
}

// Select preset color
const selectPreset = (hex) => {
  const colorInt = hexToInt(hex)
  emit('update:modelValue', colorInt)
}

// Initialize with default if not set
watch(() => props.modelValue, (newValue) => {
  if (newValue === null || newValue === undefined) {
    // Default to blue
    emit('update:modelValue', hexToInt('#007AFF'))
  }
}, { immediate: true })
</script>
