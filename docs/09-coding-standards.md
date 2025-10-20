# Coding Standards
## Coding Standards

### PHP/Laravel

1. **PSR-12** formatting
2. **Strict typing required:**
   ```php
   public function getContext(Request $request): JsonResponse
   ```
3. **Always log operations:**
   ```php
   Log::info('Operation completed', ['data' => $data]);
   ```
4. **Try-catch mandatory:**
   ```php
   try {
       // code
       Log::info('Success');
   } catch (\Exception $e) {
       Log::error('Error', ['error' => $e->getMessage()]);
       return response()->json(['error' => 'Message'], 500);
   }
   ```
5. **Service Layer Pattern** - Business logic in `app/Services/`, controllers only for HTTP handling
6. **Never use raw SQL** - Use Eloquent or Query Builder

### Vue 3

1. **Composition API only** - No Options API

2. **Component Structure Order:**
   ```vue
   <template>
     <!-- Template always first -->
   </template>

   <script setup>
   // 1. Imports (grouped: Vue → Router → API → Composables → Components)
   import { ref, computed, watch, onMounted } from 'vue'
   import { useRouter } from 'vue-router'
   import api from '@/api'
   import { useMoyskladEntities } from '@/composables/useMoyskladEntities'
   import MyComponent from '@/components/MyComponent.vue'

   // 2. Props
   const props = defineProps({
     accountId: { type: String, required: true }
   })

   // 3. Emits
   const emit = defineEmits(['update:settings'])

   // 4. Composables
   const router = useRouter()
   const loader = useMoyskladEntities(props.accountId, 'organizations')

   // 5. Reactive state
   const data = ref(null)
   const loading = ref(false)
   const error = ref(null)

   // 6. Computed properties
   const isValid = computed(() => data.value !== null)

   // 7. Watchers
   watch(() => props.accountId, loadData)

   // 8. Methods
   const loadData = async () => { /* ... */ }

   // 9. Lifecycle hooks
   onMounted(loadData)
   </script>
   ```

3. **Naming Conventions:**
   - Components: PascalCase (`MyComponent.vue`)
   - Composables: camelCase with `use` prefix (`useMoyskladEntities.js`)
   - Props: camelCase (`accountId`, `isLoading`)
   - Events: kebab-case (`update:settings`, `save-complete`)
   - Variables/functions: camelCase (`loadData`, `isValid`)
   - Constants: SCREAMING_SNAKE_CASE (`API_URL`, `MAX_RETRIES`)

4. **Reactive State Management:**
   ```javascript
   // ✅ CORRECT: Use ref for all values
   const count = ref(0)
   const user = ref({ name: 'John' })
   const items = ref([])

   // ✅ Access with .value in script
   count.value++
   console.log(user.value.name)

   // ✅ No .value in template
   <template>{{ count }}</template>

   // ❌ AVOID: reactive() for simple values
   const state = reactive({ count: 0 }) // Use ref(0) instead
   ```

5. **Error Handling:**
   ```javascript
   // ✅ ALWAYS handle loading, error, and success states
   const loading = ref(false)
   const error = ref(null)

   const loadData = async () => {
     try {
       loading.value = true
       error.value = null
       const response = await api.get('/data')
       data.value = response.data
     } catch (err) {
       console.error('Failed:', err)
       error.value = 'Не удалось загрузить данные'
     } finally {
       loading.value = false
     }
   }
   ```

6. **Component Communication:**
   ```javascript
   // ✅ Props down, events up
   // Parent passes data
   <ChildComponent :data="myData" @update="handleUpdate" />

   // Child emits events (never mutate props)
   const emit = defineEmits(['update'])
   emit('update', newValue)

   // ✅ Use v-model for two-way binding
   const props = defineProps({ modelValue: String })
   const emit = defineEmits(['update:modelValue'])
   emit('update:modelValue', newValue)
   ```

7. **Composable Patterns:**
   ```javascript
   // ✅ Return reactive refs and methods
   export function useMyFeature() {
     const data = ref(null)
     const loading = ref(false)

     const load = async () => { /* ... */ }

     return { data, loading, load }
   }
   ```

8. **Performance Optimization:**
   ```javascript
   // ✅ Use computed for derived state (cached)
   const fullName = computed(() => `${first.value} ${last.value}`)

   // ✅ Cache API calls in composables
   if (data.value.length > 0) return // Already loaded

   // ✅ Watch specific properties, not whole objects
   watch(() => obj.value.prop, callback) // Better than deep watch
   ```

### Code Organization Principles

**DRY (Don't Repeat Yourself):**
- Logic used in 2+ places → extract to composable
- UI pattern repeats 3+ times → create component
- API call repeats → add to `api/index.js`

**Component Design Patterns:**
```javascript
// ✅ "Dumb" Presentational Component:
// - Only renders UI
// - Emits events for interactions
// - No API calls or business logic
// - All data via props

// ✅ "Smart" Container Component:
// - Loads data via composables/API
// - Manages state and logic
// - Passes data to dumb components
```

**File Organization:**
```
resources/js/
├── api/index.js                    # API client
├── components/
│   ├── ProductCard.vue             # Reusable components
│   └── franchise-settings/         # Feature-specific
│       └── ProductSyncSection.vue
├── composables/
│   └── useMoyskladEntities.js      # Reusable logic
├── pages/
│   └── FranchiseSettings.vue       # Route pages
└── router/index.js                 # Routes
```

### Common Anti-Patterns to Avoid

**❌ DON'T:**
```javascript
// ❌ Mutate props directly
props.value = newValue

// ❌ Use reactive() for simple values
const count = reactive({ value: 0 }) // Use ref(0)

// ❌ Put business logic in template
<div v-if="items.filter(i => i.active).length > 0">

// ❌ Skip error handling
const data = await api.get() // What if it fails?

// ❌ Access .value in template
<div>{{ count.value }}</div> // Wrong!

// ❌ Copy-paste logic across components
// Extract to composable instead
```

**✅ DO:**
```javascript
// ✅ Emit events to update props
emit('update:modelValue', newValue)

// ✅ Use computed for derived state
const active = computed(() => items.value.filter(i => i.active))

// ✅ Always handle errors
try { /* ... */ } catch (err) { error.value = err }

// ✅ No .value in template
<div>{{ count }}</div>

// ✅ Use composables for shared logic
const { data, load } = useMoyskladEntities(id, 'type')
```

### Tailwind CSS

1. **Utility classes only** - No custom CSS
2. **Color scheme:**
   - Primary: `indigo-500` to `indigo-700`
   - Secondary: `purple-500` to `purple-600`
   - Gradients: `bg-gradient-to-r from-indigo-500 to-purple-600`
3. **Always add transitions** for hover states

