<template>
  <div class="product-filter-builder">
    <!-- Header -->
    <div class="mb-4">
      <h4 class="text-sm font-medium text-gray-900">Фильтры товаров</h4>
      <p class="text-xs text-gray-500 mt-1">
        Условия внутри группы объединяются по И, группы между собой по ИЛИ
      </p>
    </div>

    <!-- Empty state -->
    <div v-if="filterGroups.length === 0" class="text-center py-8 border-2 border-dashed border-gray-300 rounded-lg">
      <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
      </svg>
      <p class="text-sm text-gray-500 mt-3">Фильтры не настроены</p>
      <p class="text-xs text-gray-400 mt-1">Нажмите "Добавить группу" для создания первого фильтра</p>
      <button
        @click="addGroup"
        type="button"
        class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors mt-4"
      >
        <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Добавить группу
      </button>
    </div>

    <!-- Filter groups -->
    <div v-else class="space-y-4">
      <template v-for="(group, groupIndex) in filterGroups" :key="`group-${groupIndex}`">
      <div
        class="border-2 border-solid rounded-lg p-4 bg-white"
        style="border-color: rgb(171, 171, 171);"
      >
        <!-- Group header -->
        <div class="flex items-center justify-between mb-3">
          <span class="text-sm font-medium text-gray-700">
            Группа {{ groupIndex + 1 }}
          </span>
          <button
            @click="removeGroup(groupIndex)"
            type="button"
            class="text-gray-400 hover:text-red-600 focus:outline-none transition-colors"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Conditions -->
        <div class="space-y-3">
          <template v-for="(condition, condIndex) in group.conditions" :key="`condition-${groupIndex}-${condIndex}`">
          <div
            class="bg-gray-50 rounded-md p-3 border-2 border-dashed"
            style="border-color: rgb(171, 171, 171);"
          >
            <div class="flex items-start gap-3">
              <!-- Condition type selector -->
              <div class="flex-shrink-0 w-40">
                <SimpleSelect
                  :model-value="condition.type"
                  @update:model-value="(val) => updateConditionType(groupIndex, condIndex, val)"
                  placeholder="Тип условия"
                  :options="conditionTypeOptions"
                />
              </div>

              <!-- Condition value -->
              <div class="flex-grow">
                <!-- Folder condition -->
                <div v-if="condition.type === 'folder'">
                  <!-- Button to open picker -->
                  <button
                    @click="openFolderPicker(groupIndex, condIndex)"
                    type="button"
                    class="w-full text-left px-3 py-2 border border-dashed border-gray-300 rounded-md text-sm hover:border-indigo-400 hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-colors"
                  >
                    <span v-if="!condition.folder_ids || condition.folder_ids.length === 0" class="text-gray-400">
                      Выберите группы товаров...
                    </span>
                    <span v-else class="text-gray-600">
                      Выбрано групп: <span class="font-medium text-indigo-600">{{ condition.folder_ids.length }}</span>
                    </span>
                  </button>

                  <!-- Selected folders as tags -->
                  <div v-if="condition.folder_ids && condition.folder_ids.length > 0" class="flex flex-wrap gap-1.5 mt-2">
                    <span
                      v-for="folderId in condition.folder_ids"
                      :key="folderId"
                      class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium bg-green-100 border border-gray-300 rounded-md"
                    >
                      {{ getFolderName(folderId) }}
                      <button
                        @click.stop="removeFolderFromCondition(groupIndex, condIndex, folderId)"
                        type="button"
                        class="hover:text-gray-700 transition-colors"
                      >
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                      </button>
                    </span>
                  </div>
                </div>

                <!-- Attribute flag condition -->
                <div v-else-if="condition.type === 'attribute_flag'" class="flex gap-2">
                  <div class="flex-grow">
                    <SimpleSelect
                      :model-value="condition.attribute_id"
                      @update:model-value="(val) => updateConditionAttribute(groupIndex, condIndex, val)"
                      placeholder="Выберите атрибут"
                      :options="flagAttributeOptions"
                    />
                  </div>
                  <div class="w-32">
                    <SimpleSelect
                      :model-value="condition.value"
                      @update:model-value="(val) => updateConditionValue(groupIndex, condIndex, val)"
                      placeholder="Значение"
                      :options="booleanValueOptions"
                    />
                  </div>
                </div>

                <!-- Attribute customentity condition -->
                <div v-else-if="condition.type === 'attribute_customentity'" class="flex gap-2">
                  <div class="flex-grow">
                    <SimpleSelect
                      :model-value="condition.attribute_id"
                      @update:model-value="(val) => onCustomEntityAttributeChange(groupIndex, condIndex, val)"
                      placeholder="Выберите справочник"
                      :options="customEntityAttributeOptions"
                    />
                  </div>
                  <div class="flex-grow">
                    <SimpleSelect
                      v-if="condition.attribute_id"
                      :model-value="condition.value"
                      @update:model-value="(val) => updateConditionValue(groupIndex, condIndex, val)"
                      placeholder="Выберите значение"
                      :options="getCustomEntityElementOptions(condition.attribute_id)"
                      :disabled="loadingCustomEntityElements[customEntityAttributeOptions.find(a => a.id === condition.attribute_id)?.customEntityId]"
                    />
                    <div v-else class="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-400 bg-gray-50">
                      Сначала выберите справочник
                    </div>
                  </div>
                </div>
              </div>

              <!-- Remove condition button -->
              <button
                @click="removeCondition(groupIndex, condIndex)"
                type="button"
                class="flex-shrink-0 text-gray-400 hover:text-red-600 focus:outline-none transition-colors"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </div>

          <!-- AND indicator (not for last condition) -->
          <div v-if="condIndex < group.conditions.length - 1" class="flex justify-center -my-1.5">
            <span class="px-3 py-1 text-xs font-medium text-white bg-gray-500 rounded-md">
              И
            </span>
          </div>
          </template>

          <!-- Add condition button -->
          <button
            @click="addCondition(groupIndex)"
            type="button"
            class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400 transition-colors"
          >
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Добавить условие
          </button>
        </div>
      </div>

      <!-- OR indicator (not for last group) -->
      <div v-if="groupIndex < filterGroups.length - 1" class="flex justify-center -my-2">
        <span class="px-4 py-1.5 text-xs font-medium text-white bg-purple-600 rounded-md">
          ИЛИ
        </span>
      </div>
      </template>

      <!-- Add group button -->
      <button
        v-if="filterGroups.length < 10"
        @click="addGroup"
        type="button"
        class="inline-flex items-center justify-center w-full px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
      >
        <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Добавить группу
      </button>
    </div>

    <!-- Folder picker modal -->
    <ProductFolderPicker
      ref="folderPicker"
      v-model="tempFolderSelection"
      :folders="folders"
      :loading="loadingFolders"
      @confirm="onFolderPickerConfirm"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import ProductFolderPicker from './ProductFolderPicker.vue'
import SimpleSelect from './SimpleSelect.vue'
import api from '../api'

// Condition type options
const conditionTypeOptions = [
  { id: 'folder', name: 'Группа товаров' },
  { id: 'attribute_flag', name: 'Признак (флаг)' },
  { id: 'attribute_customentity', name: 'Справочник' }
]

// Boolean value options
const booleanValueOptions = [
  { id: true, name: 'Да' },
  { id: false, name: 'Нет' }
]

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({ groups: [] })
  },
  accountId: {
    type: String,
    required: true
  },
  attributes: {
    type: Array,
    default: () => []
  },
  folders: {
    type: Array,
    default: () => []
  },
  loadingFolders: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue'])

// Local state
const filterGroups = ref([])
const tempFolderSelection = ref([])
const currentEditingCondition = ref(null) // { groupIndex, condIndex }
const folderPicker = ref(null)
const customEntityElements = ref({}) // { customEntityId: [{id, name}] }
const loadingCustomEntityElements = ref({}) // { customEntityId: boolean }

// Computed
const flagAttributes = computed(() => {
  return props.attributes.filter(attr => attr.type === 'boolean')
})

const flagAttributeOptions = computed(() => {
  return flagAttributes.value.map(attr => ({
    id: attr.id,
    name: attr.name
  }))
})

const customEntityAttributes = computed(() => {
  return props.attributes.filter(attr => attr.type === 'customentity' && attr.customEntityMeta)
})

const customEntityAttributeOptions = computed(() => {
  return customEntityAttributes.value.map(attr => ({
    id: attr.id,
    name: attr.name,
    customEntityId: extractCustomEntityId(attr.customEntityMeta?.href)
  }))
})

// Initialize from modelValue
watch(() => props.modelValue, (newVal) => {
  if (newVal && newVal.groups) {
    filterGroups.value = JSON.parse(JSON.stringify(newVal.groups))
  } else {
    filterGroups.value = []
  }
}, { immediate: true, deep: true })

// Methods
const emitUpdate = () => {
  emit('update:modelValue', { groups: filterGroups.value })
}
const addGroup = () => {
  if (filterGroups.value.length < 10) {
    filterGroups.value.push({
      conditions: [
        {
          type: 'folder',
          folder_ids: []
        }
      ]
    })
    emitUpdate()
  }
}

const removeGroup = (groupIndex) => {
  filterGroups.value.splice(groupIndex, 1)
  emitUpdate()
}

const addCondition = (groupIndex) => {
  filterGroups.value[groupIndex].conditions.push({
    type: 'folder',
    folder_ids: []
  })
  emitUpdate()
}

const removeCondition = (groupIndex, condIndex) => {
  const group = filterGroups.value[groupIndex]
  group.conditions.splice(condIndex, 1)

  // Remove group if no conditions left
  if (group.conditions.length === 0) {
    removeGroup(groupIndex)
  } else {
    emitUpdate()
  }
}

const onConditionTypeChange = (groupIndex, condIndex) => {
  const condition = filterGroups.value[groupIndex].conditions[condIndex]

  // Reset condition data based on new type
  if (condition.type === 'folder') {
    condition.folder_ids = []
    delete condition.attribute_id
    delete condition.value
  } else if (condition.type === 'attribute_flag') {
    condition.attribute_id = ''
    condition.value = true
    delete condition.folder_ids
  } else if (condition.type === 'attribute_customentity') {
    condition.attribute_id = ''
    condition.value = ''
    delete condition.folder_ids
  }

  emitUpdate()
}

const openFolderPicker = (groupIndex, condIndex) => {
  currentEditingCondition.value = { groupIndex, condIndex }
  const condition = filterGroups.value[groupIndex].conditions[condIndex]
  tempFolderSelection.value = [...(condition.folder_ids || [])]
  folderPicker.value.open()
}

const onFolderPickerConfirm = (selectedIds) => {
  if (currentEditingCondition.value) {
    const { groupIndex, condIndex } = currentEditingCondition.value
    filterGroups.value[groupIndex].conditions[condIndex].folder_ids = [...selectedIds]
    currentEditingCondition.value = null
    emitUpdate()
  }
}

const updateConditionType = (groupIndex, condIndex, val) => {
  filterGroups.value[groupIndex].conditions[condIndex].type = val
  onConditionTypeChange(groupIndex, condIndex)
}

const updateConditionAttribute = (groupIndex, condIndex, val) => {
  filterGroups.value[groupIndex].conditions[condIndex].attribute_id = val
  emitUpdate()
}

const updateConditionValue = (groupIndex, condIndex, val) => {
  filterGroups.value[groupIndex].conditions[condIndex].value = val
  emitUpdate()
}

// Получить имя папки по ID
const getFolderName = (folderId) => {
  const findFolder = (folders) => {
    for (const folder of folders) {
      if (folder.id === folderId) {
        return folder.name
      }
      if (folder.children) {
        const found = findFolder(folder.children)
        if (found) return found
      }
    }
    return null
  }

  return findFolder(props.folders) || folderId
}

// Удалить папку из условия
const removeFolderFromCondition = (groupIndex, condIndex, folderId) => {
  const condition = filterGroups.value[groupIndex].conditions[condIndex]
  const index = condition.folder_ids.indexOf(folderId)
  if (index > -1) {
    condition.folder_ids.splice(index, 1)
    emitUpdate()
  }
}

// Извлечь ID справочника из href
const extractCustomEntityId = (href) => {
  if (!href) return null
  const match = href.match(/\/entity\/customentity\/([a-f0-9-]+)/)
  return match ? match[1] : null
}

// Загрузить элементы справочника
const loadCustomEntityElements = async (customEntityId) => {
  if (!customEntityId || customEntityElements.value[customEntityId]) {
    return // Уже загружены
  }

  try {
    loadingCustomEntityElements.value[customEntityId] = true
    const response = await api.syncSettings.getCustomEntityElements(props.accountId, customEntityId)
    customEntityElements.value[customEntityId] = response.data.data || []
  } catch (error) {
    console.error('Failed to load custom entity elements:', error)
    customEntityElements.value[customEntityId] = []
  } finally {
    loadingCustomEntityElements.value[customEntityId] = false
  }
}

// Получить опции для выбора элемента справочника
const getCustomEntityElementOptions = (attributeId) => {
  const attr = customEntityAttributeOptions.value.find(a => a.id === attributeId)
  if (!attr || !attr.customEntityId) return []

  const elements = customEntityElements.value[attr.customEntityId] || []
  return elements.map(el => ({
    id: el.id,
    name: el.name
  }))
}

// Обработать изменение атрибута справочника
const onCustomEntityAttributeChange = async (groupIndex, condIndex, attributeId) => {
  updateConditionAttribute(groupIndex, condIndex, attributeId)

  // Загрузить элементы справочника
  const attr = customEntityAttributeOptions.value.find(a => a.id === attributeId)
  if (attr && attr.customEntityId) {
    await loadCustomEntityElements(attr.customEntityId)
  }
}
</script>

<style scoped>
.product-filter-builder {
  /* Component styles handled by Tailwind */
}
</style>
