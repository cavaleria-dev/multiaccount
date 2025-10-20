# Frontend Architecture
### Frontend Architecture

**Vue 3 Composition API** - Options API is NOT used

**Key Composables** (`resources/js/composables/`):
- `useMoyskladContext.js` - Context management, loads from URL params, saves to sessionStorage
- `useMoyskladEntities.js` - Universal loader for МойСклад entities with caching
  * Supports 10 entity types: organizations, stores, projects, employees, salesChannels, customerOrderStates, purchaseOrderStates, attributes, folders, priceTypes
  * Auto-caching with `loaded` flag
  * Methods: `load(force)`, `reload()`, `clear()`, `addItem()`, `addChildPriceType()`
  * Reduces code duplication (~300 lines saved across components)
- `useTargetObjectsMetadata.js` - Metadata management for target objects
  * Auto-watches 10 settings fields (target_organization_id, target_store_id, etc.)
  * Methods: `updateMetadata()`, `clearMetadata()`, `initializeMetadata()`, `getMetadata()`
  * Replaces 9 identical watch handlers (~50 lines saved)

**Pages** (`resources/js/pages/`):
- `Dashboard.vue` - Statistics overview
- `ChildAccounts.vue` - Franchise management, add by account name
- `GeneralSettings.vue` - App-wide settings (account type: main/child)
- `FranchiseSettings.vue` - Per-franchise sync settings

**API Client** (`resources/js/api/index.js`):
- Axios instance with interceptor that auto-adds `X-MoySklad-Context-Key` from sessionStorage

**Component Architecture:**

Settings pages use modular component structure for maintainability:

`FranchiseSettings.vue` (983 lines) - Main settings page, composed of:
- `ProductSyncSection.vue` (131 lines) - Product sync checkboxes + advanced settings
- `PriceMappingsSection.vue` (254 lines) - Price type mappings + attribute selection
- `ProductFiltersSection.vue` (77 lines) - Product filters toggle + ProductFilterBuilder
- `DocumentSyncSection.vue` (356 lines) - Document sync options + target objects
- `AutoCreateSection.vue` (72 lines) - Auto-creation settings

**Component pattern:** "Dumb" components that only render UI and emit events. All business logic remains in parent component (FranchiseSettings.vue). This approach:
- Reduces main file size by 32% (1454 → 983 lines)
- Improves code organization and readability
- Maintains single source of truth for data and logic
- Easy to add/remove/reorder sections

**Reusable UI Components:**

`SimpleSelect.vue` - Custom select dropdown with loading state support:
- Props: `modelValue`, `label`, `placeholder`, `options`, `disabled`, `required`, `loading`
- **Loading prop**: Shows animated spinner instead of dropdown arrow when `loading=true`
- Features: Clear button, dropdown animation, click-outside handling
- Loading state: Disables clear button, shows indigo spinner (4x4px)
- Usage example:
  ```vue
  <SimpleSelect
    v-model="selectedId"
    :options="items"
    :loading="isLoadingItems"
    placeholder="Выберите значение"
  />
  ```

`SearchableSelect.vue` - Advanced select with search functionality
`ProductFilterBuilder.vue` - Visual filter constructor for product filtering
`ProductFolderPicker.vue` - Hierarchical folder tree picker

