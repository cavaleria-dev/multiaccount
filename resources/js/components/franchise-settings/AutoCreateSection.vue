<template>
  <div class="bg-white shadow rounded-lg p-6">
    <h3 class="text-lg font-medium text-gray-900 mb-4">Автоматическое создание</h3>
    <div class="space-y-4">
      <div class="flex items-start">
        <div class="flex items-center h-5">
          <input
            id="auto_create_attributes"
            type="checkbox"
            :checked="settings.auto_create_attributes"
            @change="updateSetting('auto_create_attributes', $event.target.checked)"
            class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
          />
        </div>
        <div class="ml-3 text-sm">
          <label for="auto_create_attributes" class="font-medium text-gray-700">Дополнительные поля</label>
          <p class="text-gray-500">Автоматически создавать доп. поля, если их нет в дочернем аккаунте</p>
        </div>
      </div>

      <div class="flex items-start">
        <div class="flex items-center h-5">
          <input
            id="auto_create_characteristics"
            type="checkbox"
            :checked="settings.auto_create_characteristics"
            @change="updateSetting('auto_create_characteristics', $event.target.checked)"
            class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
          />
        </div>
        <div class="ml-3 text-sm">
          <label for="auto_create_characteristics" class="font-medium text-gray-700">Характеристики</label>
          <p class="text-gray-500">Автоматически создавать характеристики для модификаций</p>
        </div>
      </div>

      <div class="flex items-start">
        <div class="flex items-center h-5">
          <input
            id="auto_create_price_types"
            type="checkbox"
            :checked="settings.auto_create_price_types"
            @change="updateSetting('auto_create_price_types', $event.target.checked)"
            class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
          />
        </div>
        <div class="ml-3 text-sm">
          <label for="auto_create_price_types" class="font-medium text-gray-700">Типы цен</label>
          <p class="text-gray-500">Автоматически создавать типы цен, если их нет в дочернем аккаунте</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  settings: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['update:settings'])

const updateSetting = (key, value) => {
  emit('update:settings', {
    ...props.settings,
    [key]: value
  })
}
</script>
