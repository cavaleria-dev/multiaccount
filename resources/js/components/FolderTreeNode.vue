<template>
  <div class="folder-node">
    <label
      class="flex items-center py-1 px-2 hover:bg-gray-50 rounded cursor-pointer transition-colors"
      :class="{ 'bg-gray-50': isSelected }"
    >
      <input
        type="checkbox"
        :checked="isSelected"
        @change="$emit('toggle', folder.id)"
        class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded mr-2"
      />
      <span class="text-sm text-gray-900">{{ folder.name }}</span>
      <span v-if="folder.children && folder.children.length > 0" class="ml-1 text-xs text-gray-500">
        ({{ folder.children.length }})
      </span>
    </label>

    <!-- Рекурсивно отображаем дочерние папки -->
    <div
      v-if="folder.children && folder.children.length > 0"
      class="pl-4 border-l-2 border-gray-200 ml-2 mt-1"
    >
      <FolderTreeNode
        v-for="child in folder.children"
        :key="child.id"
        :folder="child"
        :selected="selected"
        @toggle="$emit('toggle', $event)"
      />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  folder: {
    type: Object,
    required: true
  },
  selected: {
    type: Array,
    default: () => []
  }
})

defineEmits(['toggle'])

const isSelected = computed(() => props.selected.includes(props.folder.id))
</script>

<style scoped>
.folder-node {
  user-select: none;
}
</style>
