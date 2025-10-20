# API Monitoring System (Admin Panel)
## API Monitoring System (Admin Panel)

**Purpose:** Centralized monitoring of ALL МойСклад API requests and responses for debugging and error tracking.

**Access:** `/admin` - Separate admin panel with independent authentication (NOT through МойСклад iframe).

### Features

**1. API Request Logging**
- Every API call to МойСклад is automatically logged to `moysklad_api_logs` table
- Captured data:
  - Account ID, direction (main_to_child/child_to_main/internal)
  - Entity type & ID (product, variant, customerorder, etc.)
  - HTTP method, endpoint, request payload
  - Response status, response body, error message
  - Rate limit info, request duration (ms)
- Logged via `ApiLogService` integrated into `MoySkladService`

**2. Admin Panel Pages**

- `/admin/login` - Login page (email + password, rate-limited)
- `/admin/logs` - API logs list with filters:
  - Status range (4xx, 5xx, 429 rate limit)
  - Date range (start_date, end_date)
  - Errors only checkbox
  - Entity type filter
  - Account ID filter
- `/admin/logs/{id}` - Detailed log view:
  - Full request/response data
  - Formatted JSON payloads
  - Rate limit information
  - Error messages and stack traces
- `/admin/statistics` - Statistics dashboard:
  - Total/success/error requests
  - Error rate percentage
  - Rate limit violations (429 errors)
  - Average request duration
  - Errors by HTTP status
  - Errors by entity type

**3. Authentication**

- **Model:** `AdminUser` (id, email, password, name)
- **Middleware:** `AdminAuth` - Session-based auth
- **Features:**
  - Bcrypt password hashing
  - Rate limiting (5 attempts/minute per IP)
  - Session timeout
  - Logging of login/logout events

### Database Tables

**`moysklad_api_logs`:**
```sql
- id (bigint)
- account_id (uuid) - Account making the request
- direction (enum: main_to_child, child_to_main, internal)
- related_account_id (uuid) - Related account (child or main)
- entity_type (string) - product, variant, bundle, customerorder, etc.
- entity_id (uuid) - МойСклад entity UUID
- method (string) - GET, POST, PUT, DELETE
- endpoint (text) - Full API URL
- request_payload (json) - Request body
- response_status (int) - HTTP status code
- response_body (json) - API response
- error_message (text) - Error description
- rate_limit_info (json) - Rate limit headers
- duration_ms (int) - Request duration in milliseconds
- created_at, updated_at
- Indexes: account_id, entity_type, response_status, created_at
```

**`admin_users`:**
```sql
- id (bigint)
- email (string, unique)
- password (string, hashed)
- name (string)
- created_at, updated_at
```

### Usage Examples

**Setting log context in sync services:**

```php
// In ProductSyncService::syncProduct()
$this->moySkladService
    ->setAccessToken($mainAccount->access_token)
    ->setLogContext(
        accountId: $mainAccountId,
        direction: 'main_to_child',
        relatedAccountId: $childAccountId,
        entityType: 'product',
        entityId: $productId
    );

// Make API calls - automatically logged
$productData = $this->moySkladService->get("entity/product/{$productId}");

// Clear context after operation
$this->moySkladService->clearLogContext();
```

**Querying logs programmatically:**

```php
use App\Services\ApiLogService;

$apiLogService = app(ApiLogService::class);

// Get error logs for specific account
$errorLogs = $apiLogService->getErrorLogs([
    'account_id' => $accountId,
    'start_date' => now()->subDays(7),
    'end_date' => now()
], perPage: 50);

// Get statistics for dashboard
$stats = $apiLogService->getStatistics([
    'start_date' => now()->subDays(30),
    'end_date' => now()
]);

// Cleanup old logs (30+ days)
$deleted = $apiLogService->cleanup(daysToKeep: 30);
```

### Management Commands

**Create admin user:**
```bash
php artisan admin:create-user
# Interactive prompts for name, email, password
```

**Cleanup old logs (run via cron):**
```php
// In app/Console/Kernel.php
$schedule->call(function () {
    app(ApiLogService::class)->cleanup(30);
})->daily();
```

### Routes

**Authentication:**
- `GET /admin/login` - Login form
- `POST /admin/login` - Process login
- `POST /admin/logout` - Logout (protected)

**Logs (protected by AdminAuth middleware):**
- `GET /admin/logs` - List logs with filters
- `GET /admin/logs/{id}` - Show log details
- `GET /admin/statistics` - Statistics dashboard

### Security Features

1. **Rate Limiting** - 5 login attempts per minute per IP
2. **Password Hashing** - Bcrypt with Laravel's built-in hashing
3. **Session Security** - CSRF protection, session regeneration on login
4. **Audit Logging** - Login/logout events logged to `storage/logs/laravel.log`
5. **Separate Auth** - Independent from МойСклад auth (no iframe, no contextKey)

### Performance Considerations

**Asynchronous Logging:**
- Logging doesn't block API requests
- Failures in logging don't fail the sync operation
- Try-catch wraps all logging calls

**Database Optimization:**
- Indexes on frequently queried columns (account_id, response_status, created_at)
- Pagination for large result sets (50 items per page)
- Cleanup old logs to prevent table bloat

**Why NOT log success responses in production:**
- 200 OK responses generate huge data volume
- Focus on errors (4xx, 5xx) for debugging
- Optionally enable success logging for specific accounts during debugging:
  ```php
  if ($accountId === 'debug-account-id' || $response->status() >= 400) {
      $this->logApiRequest(...);
  }
  ```

### Debugging Common Issues

**Issue: Logs not appearing**
```bash
# Check ApiLogService is injected in MoySkladService constructor
# Check setLogContext() is called before API requests
# Check database table exists: php artisan migrate
# Check errors in logs: tail -f storage/logs/laravel.log | grep ApiLogService
```

**Issue: "Unauthenticated" redirect loop**
```bash
# Check AdminAuth middleware is registered
# Check session driver is working (file/redis)
# Check admin user exists: php artisan tinker -> AdminUser::first()
```

**Issue: Large database size**
```bash
# Count logs
php artisan tinker
>>> \DB::table('moysklad_api_logs')->count();

# Manual cleanup
>>> \DB::table('moysklad_api_logs')->where('created_at', '<', now()->subDays(30))->delete();

# Automated cleanup (add to Kernel.php schedule)
$schedule->call(fn() => app(ApiLogService::class)->cleanup(30))->daily();
```

