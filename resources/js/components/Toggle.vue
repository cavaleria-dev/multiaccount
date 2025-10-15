<template>
  <div class="flex items-start" :class="containerClass">
    <div class="flex items-center h-5">
      <button
        type="button"
        :disabled="disabled"
        @click="toggle"
        class="relative inline-flex flex-shrink-0 border-2 border-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2"
        :class="[
          sizeClasses,
          focusRingClass,
          disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer',
          modelValue ? bgColorClass : 'bg-gray-200'
        ]"
        role="switch"
        :aria-checked="modelValue"
      >
        <span
          aria-hidden="true"
          class="pointer-events-none inline-block rounded-full bg-white shadow transform ring-0 transition ease-in-out duration-200"
          :class="[
            switchSizeClasses,
            modelValue ? translateClass : 'translate-x-0'
          ]"
        ></span>
      </button>
    </div>
    <div v-if="label || description" class="ml-3 text-sm">
      <label v-if="label" @click="toggle" class="font-medium text-gray-700 cursor-pointer select-none">
        {{ label }}
      </label>
      <p v-if="description" class="text-gray-500 text-xs mt-0.5">
        {{ description }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  },
  label: {
    type: String,
    default: ''
  },
  description: {
    type: String,
    default: ''
  },
  disabled: {
    type: Boolean,
    default: false
  },
  size: {
    type: String,
    default: 'medium', // small, medium, large
    validator: (value) => ['small', 'medium', 'large'].includes(value)
  },
  color: {
    type: String,
    default: 'indigo', // indigo, green, purple, blue, red
    validator: (value) => ['indigo', 'green', 'purple', 'blue', 'red'].includes(value)
  },
  containerClass: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['update:modelValue'])

const toggle = () => {
  if (!props.disabled) {
    emit('update:modelValue', !props.modelValue)
  }
}

// Size classes
const sizeClasses = computed(() => {
  const sizes = {
    small: 'h-5 w-9',
    medium: 'h-6 w-11',
    large: 'h-7 w-14'
  }
  return sizes[props.size]
})

const switchSizeClasses = computed(() => {
  const sizes = {
    small: 'h-4 w-4',
    medium: 'h-5 w-5',
    large: 'h-6 w-6'
  }
  return sizes[props.size]
})

const translateClass = computed(() => {
  const translates = {
    small: 'translate-x-4',
    medium: 'translate-x-5',
    large: 'translate-x-7'
  }
  return translates[props.size]
})

// Color classes
const bgColorClass = computed(() => {
  const colors = {
    indigo: 'bg-indigo-600',
    green: 'bg-green-600',
    purple: 'bg-purple-600',
    blue: 'bg-blue-600',
    red: 'bg-red-600'
  }
  return colors[props.color]
})

const focusRingClass = computed(() => {
  const colors = {
    indigo: 'focus:ring-indigo-500',
    green: 'focus:ring-green-500',
    purple: 'focus:ring-purple-500',
    blue: 'focus:ring-blue-500',
    red: 'focus:ring-red-500'
  }
  return colors[props.color]
})
</script>
