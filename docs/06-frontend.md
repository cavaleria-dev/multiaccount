# Frontend Architecture
### Frontend Architecture

**Vue 3 Composition API** - Options API is NOT used

**Key Composables** (`resources/js/composables/`):

**Core Composables:**
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

**Franchise Settings Composables** (modular architecture):
- `useFranchiseSettingsData.js` (178 lines) - Extended settings data loader
  * Manages: priceTypes, attributes, folders
  * Parallel loading with `loadAll()` - single Promise.all() call
  * Request cancellation with AbortController (prevents memory leaks)
  * Individual loading states + error states for each entity type
  * Methods: `loadAll()`, `loadPriceTypes()`, `loadAttributes()`, `loadFolders()`, `cancelRequests()`, `isLoading()`, `hasErrors()`
- `usePriceMappingsManager.js` (185 lines) - Price mappings management
  * CRUD operations for price type mappings
  * Creating new price types in child account
  * Auto-select newly created price type in mapping
  * Validation with `validateMappings()` (checks incomplete mappings)
  * Methods: `addPriceMapping()`, `removePriceMapping()`, `createNewPriceType()`, `initializeMappings()`, `getMappingsForSave()`, `validateMappings()`
- `useModalManager.js` (159 lines) - Unified modal management
  * Manages 6 modals (project, store, salesChannel, customerOrderState, retailDemandState, purchaseOrderState)
  * Single interface: `show(type)`, `hide(type)`, `hideAll()`, `isAnyOpen()`
  * Modal state management: `setLoading(type, bool)`, `setError(type, msg)`, `clearError(type)`
  * Backward compatibility refs for template v-model usage
  * Replaces 12 separate modal refs with structured object
- `useFranchiseSettingsForm.js` (282 lines) - Settings form management
  * Loads settings from API with error handling (404 → redirect, 401 → reload)
  * Saves settings with data composition (no props mutation!)
  * Initializes price mappings, attributes, metadata via provided managers
  * Methods: `loadSettings(onDataLoaded)`, `saveSettings()`, `prepareSettingsForSave()`, `resetForm()`, `clearError()`
  * Clean separation: loading logic + saving logic in one place

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

**Unified Franchise Settings with Tabs** (single-page design, refactored 2025):
- `FranchiseSettings.vue` (**686 lines**, refactored from 913) - Main page with tabbed interface
  * **Refactored architecture** (Jan 2025):
    - 227 lines removed (-24.9%) by extracting logic to composables
    - Modal handlers: 142 lines → ~100 lines (-30%) via unified `handleEntityCreated()`
    - Refs count: 60+ → ~20 (-66%) by using composables
    - Functions count: 25+ → ~12 (-52%) by delegation to composables
  * **Critical fixes**:
    - ✅ **Props mutation removed** - `saveSettings()` creates `dataToSave` object instead of mutating `settings.value`
    - ✅ **Request cancellation added** - AbortController prevents memory leaks on unmount
    - ✅ **Improved testability** - Business logic extracted to testable composables
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

**Component pattern:** "Dumb" section components that only render UI and emit events. Business logic delegated to specialized composables. This approach:
- All settings load once, visible in single view with tabs
- Instant tab switching without reloading data (v-show)
- Single form submit saves all settings across all tabs
- Composable-based architecture: `useFranchiseSettingsData`, `usePriceMappingsManager`, `useModalManager`, `useFranchiseSettingsForm`
- Single Responsibility Principle: each composable handles one concern
- Easy to test: composables are pure functions with clear inputs/outputs
- Easy to add/remove/reorder sections within tabs

**Refactoring improvements** (Jan 2025):
- Before: 913 lines monolith with 60+ refs and 25+ functions
- After: 686 lines coordinator + 4 composables (804 lines total, but reusable)
- Modal handlers: DRY with config-driven `handleEntityCreated()`
- Data preparation: Immutable with `dataToSave = { ...settings.value, ... }`
- Memory safety: AbortController cancels requests on unmount
- Code quality: Follows Vue 3 best practices (no props mutation, proper reactivity)

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

