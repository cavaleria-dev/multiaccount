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
- `Dashboard.vue` - Statistics overview + franchise tiles grid with sync toggles
- `ChildAccounts.vue` - Franchise table (simplified: name, status, actions only)
- `GeneralSettings.vue` - App-wide settings (account type: main/child)
- `FranchiseSettings.vue` - ⚠️ DEPRECATED: Redirects to new modular pages
- `franchise/FranchiseProducts.vue` - Product sync settings (products, prices, filters)
- `franchise/FranchiseDocuments.vue` - Document sync settings with target objects
- `franchise/FranchiseGeneral.vue` - General settings (main toggle, VAT, auto-create, delete button)

**Layouts** (`resources/js/layouts/`):
- `FranchiseLayout.vue` - Sidebar layout for franchise settings with 3-tab navigation

**API Client** (`resources/js/api/index.js`):
- Axios instance with interceptor that auto-adds `X-MoySklad-Context-Key` from sessionStorage

**Component Architecture:**

Settings pages use modular component structure for maintainability:

**Modular Franchise Settings** (split across 3 pages):
- `FranchiseProducts.vue` (~350 lines) - Uses:
  - `ProductSyncSection.vue` (131 lines) - Product sync checkboxes + advanced settings
  - `PriceMappingsSection.vue` (254 lines) - Price type mappings + attribute selection
  - `ProductFiltersSection.vue` (77 lines) - Product filters toggle + ProductFilterBuilder
- `FranchiseDocuments.vue` (~280 lines) - Uses:
  - `DocumentSyncSection.vue` (356 lines) - Document sync options + target objects
- `FranchiseGeneral.vue` (~240 lines) - Uses:
  - `AutoCreateSection.vue` (72 lines) - Auto-creation settings
  - `VatSyncSection.vue` - VAT sync settings

**Component pattern:** "Dumb" components that only render UI and emit events. All business logic in parent pages. This approach:
- Reduces complexity by splitting 1000+ line file into 3 focused pages
- Improves navigation with sidebar menu
- Maintains single source of truth for data and logic per section
- Easy to add/remove/reorder sections

**Reusable UI Components:**

`AccountCard.vue` - Franchise tile card component:
- Props: `account` (object), `loading` (boolean)
- Displays: account name, status badge, truncated ID, sync toggle, stats (products/orders)
- Emits: `configure` (navigate to settings), `toggle-sync` (update sync_enabled)
- Features: Gradient icon, hover effects, responsive grid, loading state for toggle
- Usage: Dashboard franchise tiles grid (1/2/3 columns adaptive)

`SimpleSelect.vue` - Custom select dropdown with loading state support:
- Props: `modelValue`, `label`, `placeholder`, `options`, `disabled`, `required`, `loading`
- **Loading prop**: Shows animated spinner instead of dropdown arrow when `loading=true`
- Features: Clear button, dropdown animation, click-outside handling
- Loading state: Disables clear button, shows indigo spinner (4x4px)

`SearchableSelect.vue` - Advanced select with search functionality
`ProductFilterBuilder.vue` - Visual filter constructor for product filtering
`ProductFolderPicker.vue` - Hierarchical folder tree picker

**Router Structure** (`resources/js/router/index.js`):

Nested routing for franchise settings with sidebar navigation:

```
/app/accounts/:accountId (FranchiseLayout)
  ├─ /products (default) → FranchiseProducts.vue
  ├─ /documents → FranchiseDocuments.vue
  └─ /general → FranchiseGeneral.vue

Legacy route: /app/accounts/:accountId/settings → redirects to /products
```

**Route features:**
- Nested children routes with props passing
- FranchiseLayout provides sidebar navigation
- Default redirect to /products page
- Backwards compatibility redirect from old /settings route

