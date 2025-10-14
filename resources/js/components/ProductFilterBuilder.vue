<template>
  <div class="product-filter-builder">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <div>
        <h4 class="text-sm font-medium text-gray-900">Фильтры товаров</h4>
        <p class="text-xs text-gray-500 mt-1">
          Условия внутри группы объединяются по ИЛИ, группы между собой по И
        </p>
      </div>
      <button
        v-if="filterGroups.length < 10"
        @click="addGroup"
        type="button"
        class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
      >
        <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Добавить группу
      </button>
    </div>

    <!-- Empty state -->
    <div v-if="filterGroups.length === 0" class="text-center py-8 border-2 border-dashed border-gray-300 rounded-lg">
      <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
      </svg>
      <p class="text-sm text-gray-500 mt-3">Фильтры не настроены</p>
      <p class="text-xs text-gray-400 mt-1">Нажмите "Добавить группу" для создания первого фильтра</p>
    </div>

    <!-- Filter groups -->
    <div v-else class="space-y-4">
      <div
        v-for="(group, groupIndex) in filterGroups"
        :key="`group-${groupIndex}`"
        class="border border-gray-200 rounded-lg p-4 bg-gray-50"
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
          <div
            v-for="(condition, condIndex) in group.conditions"
            :key="`condition-${groupIndex}-${condIndex}`"
            class="bg-white rounded-md p-3 border border-gray-200"
          >
            <div class="flex items-start gap-3">
              <!-- Condition type selector -->
              <div class="flex-shrink-0 w-40">
                <select
                  v-model="condition.type"
                  @change="onConditionTypeChange(groupIndex, condIndex)"
                  class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                >
                  <option value="folder">Группа товаров</option>
                  <option value="attribute_flag">Признак (флаг)</option>
                </select>
              </div>

              <!-- Condition value -->
              <div class="flex-grow">
                <!-- Folder condition -->
                <div v-if="condition.type === 'folder'">
                  <button
                    @click="openFolderPicker(groupIndex, condIndex)"
                    type="button"
                    class="w-full text-left px-3 py-2 border border-gray-300 rounded-md text-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-colors"
                  >
                    <span v-if="!condition.folder_ids || condition.folder_ids.length === 0" class="text-gray-400">
                      Выберите группы товаров...
                    </span>
                    <span v-else class="text-gray-700">
                      Выбрано групп: <span class="font-medium text-indigo-600">{{ condition.folder_ids.length }}</span>
                    </span>
                  </button>
                </div>

                <!-- Attribute flag condition -->
                <div v-else-if="condition.type === 'attribute_flag'" class="flex gap-2">
                  <select
                    v-model="condition.attribute_id"
                    class="flex-grow block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                  >
                    <option value="">Выберите атрибут...</option>
                    <option
                      v-for="attr in flagAttributes"
                      :key="attr.id"
                      :value="attr.id"
                    >
                      {{ attr.name }}
                    </option>
                  </select>
                  <select
                    v-model="condition.value"
                    class="w-32 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                  >
                    <option :value="true">Да</option>
                    <option :value="false">Нет</option>
                  </select>
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

            <!-- OR indicator (not for last condition) -->
            <div v-if="condIndex < group.conditions.length - 1" class="text-center mt-2">
              <span class="inline-block px-2 py-1 text-xs font-medium text-purple-700 bg-purple-100 rounded">
                ИЛИ
              </span>
            </div>
          </div>

          <!-- Add condition button -->
          <button
            @click="addCondition(groupIndex)"
            type="button"
            class="w-full px-3 py-2 border border-dashed border-gray-300 rounded-md text-sm text-gray-600 hover:border-indigo-500 hover:text-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-colors"
          >
            + Добавить условие
          </button>
        </div>

        <!-- AND indicator (not for last group) -->
        <div v-if="groupIndex < filterGroups.length - 1" class="text-center mt-4 -mb-2">
          <span class="inline-block px-3 py-1 text-xs font-medium text-indigo-700 bg-indigo-100 rounded">
            И
          </span>
        </div>
      </div>
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

// Computed
const flagAttributes = computed(() => {
  return props.attributes.filter(attr => attr.type === 'boolean')
})

// Initialize from modelValue
watch(() => props.modelValue, (newVal) => {
  if (newVal && newVal.groups) {
    filterGroups.value = JSON.parse(JSON.stringify(newVal.groups))
  } else {
    filterGroups.value = []
  }
}, { immediate: true, deep: true })

// Emit changes
watch(filterGroups, (newVal) => {
  emit('update:modelValue', { groups: newVal })
}, { deep: true })

// Methods
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
  }
}

const removeGroup = (groupIndex) => {
  filterGroups.value.splice(groupIndex, 1)
}

const addCondition = (groupIndex) => {
  filterGroups.value[groupIndex].conditions.push({
    type: 'folder',
    folder_ids: []
  })
}

const removeCondition = (groupIndex, condIndex) => {
  const group = filterGroups.value[groupIndex]
  group.conditions.splice(condIndex, 1)

  // Remove group if no conditions left
  if (group.conditions.length === 0) {
    removeGroup(groupIndex)
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
  }
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
  }
}
</script>

<style scoped>
.product-filter-builder {
  /* Component styles handled by Tailwind */
}
</style>
