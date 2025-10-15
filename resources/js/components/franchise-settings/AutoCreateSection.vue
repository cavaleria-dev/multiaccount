<template>
  <div class="bg-white shadow rounded-lg p-6">
    <h3 class="text-lg font-medium text-gray-900 mb-4">Автоматическое создание</h3>
    <div class="space-y-3">
      <Toggle
        :model-value="settings.auto_create_attributes"
        @update:model-value="updateSetting('auto_create_attributes', $event)"
        label="Дополнительные поля"
        description="Автоматически создавать доп. поля, если их нет в дочернем аккаунте"
        size="small"
        color="green"
      />

      <Toggle
        :model-value="settings.auto_create_characteristics"
        @update:model-value="updateSetting('auto_create_characteristics', $event)"
        label="Характеристики"
        description="Автоматически создавать характеристики для модификаций"
        size="small"
        color="green"
      />

      <Toggle
        :model-value="settings.auto_create_price_types"
        @update:model-value="updateSetting('auto_create_price_types', $event)"
        label="Типы цен"
        description="Автоматически создавать типы цен, если их нет в дочернем аккаунте"
        size="small"
        color="green"
      />
    </div>
  </div>
</template>

<script setup>
import Toggle from '../Toggle.vue'

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
