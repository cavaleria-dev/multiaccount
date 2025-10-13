# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel 11 + Vue 3 application for managing МойСклад franchise networks. The application runs as an iframe inside МойСклад interface and uses Vendor API for user context retrieval.

**Stack:** PHP 8.2+, Laravel 11/12, PostgreSQL 18, Vue 3 Composition API, Tailwind CSS 3, Redis 7.x

## Essential Commands

### Development
```bash
# Setup project (first time)
composer setup                  # Runs install, .env copy, key:generate, migrate, npm install & build

# Development with hot reload (all services at once)
composer dev                    # Runs server, queue, logs (pail), and vite concurrently

# Individual services
php artisan serve               # Dev server on http://localhost:8000
npm run dev                     # Vite dev server with hot reload
php artisan queue:listen        # Queue worker
php artisan pail                # Real-time logs viewer
```

### Database
```bash
php artisan migrate             # Run migrations
php artisan migrate:rollback    # Rollback last batch
php artisan migrate:fresh       # WARNING: Drops all tables and re-runs migrations
php artisan tinker              # REPL for testing DB queries
```

### Cache Management
```bash
php artisan config:clear        # Clear config cache
php artisan cache:clear         # Clear application cache
php artisan view:clear          # Clear compiled views
php artisan route:list          # List all registered routes
```

### Frontend Build
```bash
npm run build                   # Production build (generates public/build/)
npm run dev                     # Development with hot reload
npm run preview                 # Preview production build
```

### Testing
```bash
composer test                   # Run PHPUnit tests
php artisan test                # Alternative test command
```

### Production Deployment
```bash
cd /var/www/app.cavaleria.ru
git pull origin main
npm install
npm run build
php artisan config:clear
php artisan cache:clear
```

## Architecture

### МойСклад Integration Flow

1. **Installation**: МойСклад sends PUT request to Vendor API endpoint with `accessToken`
2. **Context Retrieval**: Frontend extracts `contextKey` and `appUid` from URL parameters
3. **JWT Authentication**: Backend generates JWT token using `appUid` and `secretKey`
4. **API Communication**: Use JWT for Vendor API, use `accessToken` for JSON API 1.2

### Service Layer Pattern

**Controllers** (`app/Http/Controllers/Api/`) - Handle HTTP requests/responses only
- `MoySkladController.php` - Vendor API endpoints (install/uninstall/status)
- `ContextController.php` - Context retrieval and stats
- `WebhookController.php` - МойСклад webhook processing

**Services** (`app/Services/`) - Business logic and external API communication
- `VendorApiService.php` - Vendor API 1.0 (JWT-based, context retrieval)
- `MoySkladService.php` - JSON API 1.2 (Token-based, CRUD operations)

**Models** (`app/Models/`)
- `Account.php` - Installed applications
- Other models handle child accounts, sync settings, logs, webhooks

### Frontend Architecture (Vue 3)

```
resources/js/
├── composables/
│   └── useMoyskladContext.js    # Extracts contextKey/appUid, fetches context
├── pages/
│   ├── Dashboard.vue            # Main page with stats
│   ├── ChildAccounts.vue        # Child account management
│   └── SyncSettings.vue         # Synchronization settings
├── App.vue                      # Root component with navigation
└── router.js                    # Vue Router setup
```

### Database Schema

**Key tables:**
- `accounts` - Installed apps (app_id, account_id, access_token, status)
- `child_accounts` - Parent-child account relationships
- `sync_settings` - Synchronization configuration
- `sync_logs` - Operation logs
- `entity_mappings` - Entity ID mappings between accounts
- `webhooks` - Registered webhooks
- `accounts_archive` - Deleted account history

## Critical МойСклад API Specifics

### Vendor API JWT Generation

**CRITICAL:** Always use `JSON_UNESCAPED_SLASHES` when encoding JSON for JWT:

```php
// ✅ CORRECT
$header = json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES);
$payload = json_encode([
    'sub' => $appUid,
    'iat' => time(),
    'exp' => time() + 60,
    'jti' => bin2hex(random_bytes(12))
], JSON_UNESCAPED_SLASHES);

// ❌ WRONG - Will cause signature mismatch
$header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
```

See `VendorApiService::generateJWT()` for reference implementation.

### Vendor API Context Request

```php
// POST (not GET!) with empty array body
Http::withHeaders([
    'Authorization' => 'Bearer ' . $jwt,
    'Content-Type' => 'application/json; charset=utf-8',
])->post($url, []); // Empty array, not null!
```

### Webhook Events

- `Install` - Installation (includes `accessToken`)
- `Delete` - Uninstallation
- `TariffChanged` - Tariff change (**NO** `accessToken` in payload!)

## Code Standards

### PHP/Laravel

**Mandatory practices:**
- Strict typing: `public function method(Request $request): JsonResponse`
- Logging: `Log::info('Operation', ['data' => $data])`
- Try-catch with error logging for all external API calls
- Service Layer Pattern - business logic in services, not controllers
- PSR-12 coding standard

**Example:**
```php
public function getContext(Request $request): JsonResponse
{
    try {
        $data = $this->vendorApiService->getContext($contextKey, $appUid);
        Log::info('Context retrieved successfully', ['account_id' => $data['accountId']]);
        return response()->json($data);
    } catch (\Exception $e) {
        Log::error('Failed to get context', ['error' => $e->getMessage()]);
        return response()->json(['error' => 'Failed to retrieve context'], 500);
    }
}
```

### Vue 3

**Mandatory:**
- Composition API with `<script setup>` syntax only (no Options API)
- Composables for reusable logic in `resources/js/composables/`
- Loading and error state handling in all async operations

**Example:**
```vue
<script setup>
import { ref, onMounted } from 'vue'

const data = ref(null)
const loading = ref(false)
const error = ref(null)

const fetchData = async () => {
  loading.value = true
  try {
    const response = await axios.get('/api/endpoint')
    data.value = response.data
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

onMounted(fetchData)
</script>
```

### Tailwind CSS

**Design system for iframe context:**
- Colors: `indigo-500/600/700` (primary), `purple-500/600` (secondary)
- Gradients: `bg-gradient-to-r from-indigo-500 to-purple-600`
- Spacing: Compact design (small padding/margins for iframe)
- Borders: `rounded-lg`, `rounded-xl`
- Shadows: `shadow`, `shadow-md`, `shadow-lg`
- Always add transitions: `transition-shadow duration-200`

**Important:** Only use Tailwind utility classes. No custom CSS or inline styles.

**Version:** Tailwind CSS v3 (stable). Do NOT use v4 - it has class generation issues.

## Asset Loading

### Production vs Development

The application uses environment-based asset loading in `resources/views/app.blade.php`:

- **Development:** Uses `@vite()` directive with Vite dev server
- **Production:** Reads `public/build/manifest.json` and loads static assets

This is critical for iframe deployment - assets must load correctly in production.

## CORS Configuration

CORS is configured to allow iframe loading from МойСклад domains:
- `online.moysklad.ru` (production)
- `dev.moysklad.ru` (development)

CORS headers must be set for:
1. API endpoints (`app/Http/Middleware/CorsMiddleware.php`)
2. Static files (CSS/JS) in nginx configuration

## Security

- CORS restricted to МойСклад domains only
- CSRF protection disabled for `/api/*` routes
- Always validate incoming data
- Use Eloquent/Query Builder (never raw SQL)
- Never commit `.env` file
- Store sensitive data in `.env` (app_uid, secret_key, etc.)

## Git Commit Format

```
<type>: <description>

Types: feat, fix, style, refactor, docs
```

**Examples:**
```
feat: Add context retrieval endpoint
fix: Fix JWT signature generation for МойСклад
style: Improve dashboard layout for iframe
```

## Common Pitfalls

❌ **DON'T:**
- Use Options API in Vue (use Composition API)
- Write custom CSS (use Tailwind only)
- Put business logic in controllers (use services)
- Forget `JSON_UNESCAPED_SLASHES` for МойСклад JWT
- Use `var` in JavaScript (use `const`/`let`)
- Make DB queries in controllers
- Ignore errors (always use try-catch)
- Use Tailwind v4 (use v3 - it's stable)

✅ **DO:**
- Add strict typing to PHP methods
- Log all operations with context data
- Handle loading and error states in Vue
- Use Tailwind utility classes only
- Keep controllers thin, services fat
- Use Composition API with `<script setup>`
- Test in МойСклад iframe before deployment

## Additional Resources

- [МойСклад JSON API 1.2](https://dev.moysklad.ru/doc/api/remap/1.2/)
- [МойСклад Vendor API 1.0](https://dev.moysklad.ru/doc/api/vendor/1.0/)
- [Laravel 11 Documentation](https://laravel.com/docs/11.x)
- [Vue 3 Documentation](https://vuejs.org/)
- [Tailwind CSS Documentation](https://tailwindcss.com/)
- [МойСклад Developer Cabinet](https://apps.moysklad.ru/cabinet/)
