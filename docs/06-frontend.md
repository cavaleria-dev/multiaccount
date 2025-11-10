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
- `useToast.js` - Global toast notification system
  * Methods: `success()`, `error()`, `warning()`, `info()`, `show()`, `remove()`, `clear()`
  * Auto-dismisses after 3 seconds (configurable)
  * Replaces blocking browser `alert()` with non-blocking toasts
  * Global state management for multiple toasts

**Pages** (`resources/js/pages/`):
- `Dashboard.vue` - Statistics overview + franchise tiles grid with sync toggles + account management
  * **Context validation**: Checks `sessionStorage` for context key before navigating to settings
  * Auto-reload with error message if context expired (prevents 404 errors)
- `GeneralSettings.vue` - App-wide settings (account type: main/child)
- `FranchiseSettings.vue` - **Unified franchise settings with tabbed interface**
  * Single page with 3 tabs: Products / Documents / General
  * All data loads once, instant tab switching with v-show
  * Displays account name in header + "Back to accounts" link
  * Single save button for all settings across all tabs
  * **Enhanced error handling**:
    - 404 error → Shows message + auto-redirect to Dashboard after 2s
    - 401 error → Shows message + auto-reload app after 2s
    - Generic errors → Shows detailed error message

**Notes:**
- `ChildAccounts.vue` was removed - all account management consolidated in Dashboard
- Modular pages (`FranchiseProducts`, `FranchiseDocuments`, `FranchiseGeneral`) removed - consolidated into tabbed FranchiseSettings
- `FranchiseLayout.vue` removed - no longer needed with single-page design

**API Client** (`resources/js/api/index.js`):
- Axios instance with interceptor that auto-adds `X-MoySklad-Context-Key` from sessionStorage
- **Auto-reload on 401**: Response interceptor detects expired context (401 error) and automatically reloads the app after 1.5s
  * Duplicate prevention: Uses `reloadScheduled` flag to ensure single reload
  * User-friendly: Shows console message before reload
- Request interceptor adds context key from `sessionStorage.getItem('moysklad_context_key')`

**Component Architecture:**

Settings pages use modular component structure for maintainability:

**Unified Franchise Settings with Tabs** (single-page design):
- `FranchiseSettings.vue` (~900 lines) - Main page with tabbed interface
  * **Tab 1: Products** - Product and service sync settings
    - `ProductSyncSection.vue` (131 lines) - Product sync checkboxes + advanced settings
    - `PriceMappingsSection.vue` (254 lines) - Price type mappings + attribute selection
    - `ProductFiltersSection.vue` (77 lines) - Product filters toggle + ProductFilterBuilder
  * **Tab 2: Documents** - Document sync settings
    - `DocumentSyncSection.vue` (356 lines) - Document sync options + target objects
  * **Tab 3: General** - General settings and master toggle
    - Master sync toggle (inline) - Global on/off switch
    - `AutoCreateSection.vue` (72 lines) - Auto-creation settings
    - `VatSyncSection.vue` - VAT sync settings

**Component pattern:** "Dumb" section components that only render UI and emit events. All business logic in FranchiseSettings parent page. This approach:
- All settings load once, visible in single view with tabs
- Instant tab switching without reloading data (v-show)
- Single form submit saves all settings across all tabs
- Maintains single source of truth for data and logic
- Easy to add/remove/reorder sections within tabs

**Reusable UI Components:**

`AccountCard.vue` - Franchise tile card component:
- Props: `account` (object), `loading` (boolean)
- Displays: account name, status badge, truncated ID, sync toggle, stats (products/orders)
- Emits: `configure` (navigate to settings), `toggle-sync` (update sync_enabled)
- Features: Gradient icon, hover effects, responsive grid, loading state for toggle
- Usage: Dashboard franchise tiles grid (1/2/3 columns adaptive)
- **Fixed:** Uses `account.account_id` (UUID) instead of `account.id` for all events

`SimpleSelect.vue` - Custom select dropdown with loading state support:
- Props: `modelValue`, `label`, `placeholder`, `options`, `disabled`, `required`, `loading`
- **Loading prop**: Shows animated spinner instead of dropdown arrow when `loading=true`
- Features: Clear button, dropdown animation, click-outside handling
- Loading state: Disables clear button, shows indigo spinner (4x4px)
- **Fixed:** Memory leak (added `onBeforeUnmount` cleanup)
- **Fixed:** Click-outside detection (uses template ref instead of `.closest()`)

`SearchableSelect.vue` - Advanced select with search functionality:
- Features: Real-time search filter, color indicators, create new option
- **Fixed:** Memory leak (added `onBeforeUnmount` cleanup)
- **Fixed:** Click-outside detection (uses template ref instead of `.closest()`)

`Toast.vue` - Global toast notification container:
- Displays stacked toast notifications (success, error, warning, info)
- Auto-dismiss with animation (slide-in from right, fade-out up)
- Manual dismiss with close button
- Non-blocking UX (replaces browser `alert()`)

`ProductFilterBuilder.vue` - Visual filter constructor for product filtering:
- **Fixed:** Key antipattern (uses unique ID instead of array index)

`ProductFolderPicker.vue` - Hierarchical folder tree picker

**Router Structure** (`resources/js/router/index.js`):

Simplified flat routing structure:

```
/app → Dashboard (main page with all accounts)
/app/accounts → redirects to /app (backwards compatibility)
/app/accounts/:accountId/settings → FranchiseSettings.vue (with tabs)
```

**Route features:**
- Simple flat routing (no nested routes)
- Single settings page with internal tab navigation
- Supports `?tab=<name>` query parameter for direct tab navigation
  - Example: `/app/accounts/123/settings?tab=documents` opens Documents tab
- Props: `accountId` passed as prop to FranchiseSettings
- All "Back" links point to `/app` (Dashboard)

