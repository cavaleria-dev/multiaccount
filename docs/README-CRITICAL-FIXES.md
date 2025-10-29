# ⚠️ CRITICAL FIXES REQUIRED IMMEDIATELY

**Status:** Existing webhook code has CRITICAL issues that MUST be fixed BEFORE starting implementation

**Severity:** 🔴 CRITICAL - System will fail in production without these fixes

**Time Required:** 30-40 minutes

---

## 🚨 WHY THIS IS URGENT

Code review revealed **4 critical problems** in existing webhook implementation:

1. **Cycle Prevention Header MISSING** → Infinite webhook loops in production
2. **WebhookController parses payload INCORRECTLY** → All webhooks return error 400
3. **Synchronous processing** → Blocks response, causes timeouts
4. **No idempotency** → Duplicate webhooks create duplicate tasks

**Without fixing these issues, the webhook system will:**
- Create infinite loops (Main ↔ Child webhook cycles)
- Fail to process ANY webhooks correctly
- Cause API overload and system crashes
- Corrupt data with duplicate sync operations

---

## ✅ QUICK CHECKLIST (30 minutes)

**Complete ALL 3 tasks before starting webhook implementation:**

- [ ] **Task 1:** Add cycle prevention header (5 min) 🔴 CRITICAL
- [ ] **Task 2:** Disable broken webhooks (10 min) 🔴 CRITICAL
- [ ] **Task 3:** Backup database (15 min) 🔴 MANDATORY

---

## 📝 TASK 1: Add Cycle Prevention Header (5 min)

**File:** `app/Services/MoySkladService.php` (line ~170)

**What to do:**

```bash
# SSH to server (no local PHP!)
ssh your-server
cd /var/www/multiaccount
nano app/Services/MoySkladService.php
```

**Find (line ~170):**
```php
$headers = [
    'Authorization' => 'Bearer ' . $this->accessToken,
    'Accept-Encoding' => 'gzip',
    'Content-Type' => 'application/json',
];
```

**Add one line:**
```php
$headers = [
    'Authorization' => 'Bearer ' . $this->accessToken,
    'Accept-Encoding' => 'gzip',
    'Content-Type' => 'application/json',
    'X-Lognex-WebHook-DisableByPrefix' => config('app.url'), // ← ADD THIS LINE
];
```

**Save:** `Ctrl+O`, `Enter`, `Ctrl+X`

**Verify:**
```bash
grep "X-Lognex-WebHook-DisableByPrefix" app/Services/MoySkladService.php
# Should output the header line
```

**Commit:**
```bash
git add app/Services/MoySkladService.php
git commit -m "fix: Add X-Lognex-WebHook-DisableByPrefix header to prevent webhook cycles"
```

**⏱️ Time:** 5 minutes

---

## 🗑️ TASK 2: Disable Broken Webhooks (10 min)

**Why:** Current controller parses webhooks INCORRECTLY → creates bad data

**What to do:**

**Option A: Via МойСклад UI** (if few accounts)
1. Login to МойСклад
2. Настройки → Приложения → Вебхуки
3. Delete ALL webhooks for "app.cavaleria.ru"
4. Repeat for all accounts

**Option B: Via API** (faster, recommended)
```bash
ssh your-server
cd /var/www/multiaccount
php artisan tinker

# Copy-paste this:
$service = app(\App\Services\WebhookService::class);
$accounts = \App\Models\Account::all();
foreach ($accounts as $account) {
    try {
        $service->cleanupOldWebhooks($account->account_id);
        echo "✓ Cleaned: {$account->account_id}\n";
    } catch (\Exception $e) {
        echo "✗ Failed: {$account->account_id} - {$e->getMessage()}\n";
    }
}
exit
```

**Verify:**
```bash
php artisan tinker --execute="echo \App\Models\WebhookHealth::count();"
# Should output: 0
```

**⏱️ Time:** 10 minutes

---

## 💾 TASK 3: Backup Database (15 min)

**What to do:**

```bash
ssh your-server
cd /var/www/multiaccount

# Create backup
sudo -u postgres pg_dump multiaccount > backup_pre_webhook_$(date +%Y%m%d_%H%M%S).sql

# Verify backup
ls -lh backup_pre_webhook_*.sql
# Size should be > 0 bytes (typically 1MB-500MB)
```

**⏱️ Time:** 15 minutes

---

## ✅ VALIDATION (2 minutes)

**Before proceeding, run these commands to verify:**

```bash
# 1. Check header exists
grep "X-Lognex-WebHook-DisableByPrefix" app/Services/MoySkladService.php

# 2. Check no webhooks active
php artisan tinker --execute="echo \App\Models\WebhookHealth::count();"
# Should output: 0

# 3. Check backup exists
ls -lh backup_pre_webhook_*.sql
# Should show file with size > 0

# 4. Check git status
git status
# Should show modified: app/Services/MoySkladService.php (committed)
```

**If ANY command fails → DO NOT PROCEED!**

---

## 🎯 WHAT'S NEXT?

**After completing all 3 tasks:**

1. ✅ **You're ready to start webhook implementation!**
2. 📖 **Read detailed roadmap:** [19-webhook-roadmap.md](19-webhook-roadmap.md)
3. 📋 **Follow day-by-day plan:** [19-webhook-tasks.md](19-webhook-tasks.md)
4. 🔧 **Start with Day 1:** Database migrations

---

## 📚 DETAILED DOCUMENTATION

**For more information about these critical issues:**

- **Full explanation:** [19-webhook-roadmap.md](19-webhook-roadmap.md) section "🚨 КРИТИЧЕСКИЕ НАХОДКИ В КОДЕ"
- **Step-by-step Day 0:** [19-webhook-tasks.md](19-webhook-tasks.md) Day 0
- **Migration context:** [19-webhook-migration.md](19-webhook-migration.md) section "⚠️ Pre-Migration Critical Fixes"

---

## 🆘 NEED HELP?

**If stuck:**
1. Check detailed instructions in links above
2. Verify SSH access to server works
3. Confirm you're on the server (NOT local machine - no local PHP!)
4. Ask Claude Code for assistance with specific errors

---

## ⏱️ TOTAL TIME: 30-40 MINUTES

**Do NOT skip these tasks!**

The webhook system will fail in production without these critical fixes.
