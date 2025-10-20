# Database Structure
### Database Structure

**Critical Tables:**

`accounts` - Installed apps (account_id UUID PK, access_token, account_type: main/child, status: activated/suspended/uninstalled)

`child_accounts` - Parent-child links (parent_account_id, child_account_id, invitation_code, status)

`sync_settings` - Per-account sync config (35+ fields: sync_enabled, sync_products, sync_services, sync_orders, sync_images, product_match_field, create_product_folders, price_mappings JSON, attribute_sync_list JSON, counterparty IDs, priorities, delays)

`sync_queue` - Task queue (entity_type, entity_id, operation: create/update/delete, priority, scheduled_at, status: pending/processing/completed/failed, attempts, error_message)

`entity_mappings` - Cross-account entity mapping (parent_account_id, child_account_id, parent_entity_id UUID, child_entity_id UUID, entity_type: product/variant/bundle/service/productfolder/customerorder, sync_direction: main_to_child/child_to_main, match_field, match_value)

`webhook_health` - Webhook monitoring (account_id, webhook_id, entity_type, is_active, last_check_at, check_attempts, error_message)

`sync_statistics` - Daily stats (parent_account_id, child_account_id, date, products_synced, products_failed, orders_synced, orders_failed, sync_duration_avg, api_calls_count, last_sync_at) - unique per (parent, child, date)

**Mapping Tables:**
- `attribute_mappings` - Attribute (additional fields) mapping
- `characteristic_mappings` - Variant characteristics mapping
- `price_type_mappings` - Price type mapping
- `custom_entity_mappings` - Custom entity metadata mapping
- `custom_entity_element_mappings` - Custom entity elements mapping
- `standard_entity_mappings` - Standard МойСклад references mapping (uom, currency, country, vat) by code/isoCode

### Standard Entity Mapping

**Problem:** МойСклад standard references (uom/currency/country/vat) have **different UUIDs in each account**, but same **code/isoCode** values.

**Example:**
```
Main account:  uom "шт" (pieces) = UUID: 19f1edc0-fc42-4001-94cb-c9ec9c62ec10, code: "796"
Child account: uom "шт" (pieces) = UUID: 8f2a3d50-bc21-5002-85dc-d0fd0d73fd21, code: "796"
                                    ↑ DIFFERENT UUID!           ↑ SAME code!
```

**Solution:** `standard_entity_mappings` table maps by code/isoCode instead of UUID.

**Table structure:**
```sql
standard_entity_mappings:
- parent_account_id (UUID) - Main account
- child_account_id (UUID) - Child account
- entity_type (string) - 'uom', 'currency', 'country', 'vat'
- parent_entity_id (string) - UUID in main account
- child_entity_id (string) - UUID in child account
- code (string) - Matching code (e.g., "796" for uom, "RUB" for currency)
- name (string) - Human-readable name for debugging
- metadata (json) - Additional data (rate for vat, symbol for currency)
- UNIQUE(parent_account_id, child_account_id, entity_type, code)
```

**Mapping strategies by entity type:**

1. **uom (единицы измерения)** - by `code`:
   - Standard: "796" (шт), "166" (г), "163" (кг), "112" (л), etc.
   - Custom: User-created units also have codes
   - If not found in child → create custom uom

2. **currency (валюты)** - by `isoCode`:
   - "RUB" (Российский рубль)
   - "USD" (US Dollar)
   - "EUR" (Euro)
   - Always exist in all accounts (can't create custom)

3. **country (страны)** - by `code`:
   - "643" (Россия)
   - "840" (США)
   - "276" (Германия)
   - Always exist in all accounts (can't create custom)

4. **vat (ставки НДС)** - by `rate`:
   - 20 (20%)
   - 10 (10%)
   - 0 (0%)
   - null (Без НДС)
   - Stored as integer in metadata

**Why this is critical:**
- Without mapping → API error: "Entity with UUID xxx not found"
- Can't copy UUID from main → child (different UUIDs!)
- Must find corresponding entity by code/isoCode

### JWT Generation for МойСклад Vendor API

**CRITICAL:** Must use `JSON_UNESCAPED_SLASHES` flag when encoding!

```php
$header = ['alg' => 'HS256', 'typ' => 'JWT'];
$payload = [
    'sub' => $appUid,
    'iat' => time(),
    'exp' => time() + 60,
    'jti' => bin2hex(random_bytes(12))
];

$headerEncoded = base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
$payloadEncoded = base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
$signature = base64UrlEncode(hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secretKey, true));
$jwt = "$headerEncoded.$payloadEncoded.$signature";
```

### Webhook Flow

Webhooks handled in `WebhookController`:

1. МойСклад sends POST to `/api/webhooks/moysklad`
2. Parse `auditContext` to get accountId, entityType, action (CREATE/UPDATE/DELETE)
3. Route to appropriate service based on entity type
4. For products: Queue task in `sync_queue` with priority
5. For orders: Immediate sync without queue
6. For purchaseorder: Only sync if `applicable=true` (проведенные)

**Important:** TariffChanged event does NOT include access_token - must fetch from DB.

