# Webhook System - Production Ready Checklist

**Status:** **85-90% Complete** ✅ - Ready for production after 1 critical fix
**Created:** 2025-11-09
**Priority:** P0 - CRITICAL

---

## 🎉 Executive Summary

### Good News: System is Almost Production Ready!

**Investigation revealed (2025-11-09):**
- ✅ **85-90% implementation complete** (not 20% as documented!)
- ✅ All backend components exist and working
- ✅ Advanced features implemented (partial sync, idempotency, monitoring)
- 🔴 **1 CRITICAL ISSUE**: Missing cycle prevention header
- ⚠️ **Deployment time**: 5 minutes (critical fix) + 24-48h (staging validation)

**What this means:**
- System is production-ready infrastructure
- One critical security fix needed (5 minutes)
- Can deploy to production THIS WEEK

---

## 🔴 CRITICAL ISSUE: Cycle Prevention Header

### The Problem

**Missing**: `X-Lognex-WebHook-DisableByPrefix` header in МойСклад API requests

**Location**: `app/Services/MoySkladService.php` (line ~170)

**Impact**: **INFINITE WEBHOOK LOOPS** possible:

```
1. Main account updates product
   ↓
2. МойСклад sends webhook to Main app
   ↓
3. Main app syncs product to Child accounts
   ↓
4. Child account product updated
   ↓
5. МойСклад sends webhook to Child app (SHOULD BE DISABLED!)
   ↓
6. Child app sees "product updated"
   ↓
7. INFINITE LOOP ♾️
   ↓
8. API overload → Rate limiting → System crash
```

**Without this header:**
- Each sync triggers new webhooks
- Cascading webhooks between Main ↔ Child
- API quota exhausted in minutes
- System becomes unstable
- Data corruption possible

### The Fix (5 Minutes)

**Step 1: Locate the code**

File: `app/Services/MoySkladService.php`

Find this section (around line 170):

```php
$headers = [
    'Authorization' => 'Bearer ' . $this->accessToken,
    'Accept-Encoding' => 'gzip',
    'Content-Type' => 'application/json',
];
```

**Step 2: Add the header**

Replace with:

```php
$headers = [
    'Authorization' => 'Bearer ' . $this->accessToken,
    'Accept-Encoding' => 'gzip',
    'Content-Type' => 'application/json',
    'X-Lognex-WebHook-DisableByPrefix' => config('app.url'), // ⚠️ CRITICAL: Prevents webhook loops
];
```

**Step 3: Verify the fix**

```bash
# Search for the header in the file
grep -n "X-Lognex-WebHook-DisableByPrefix" app/Services/MoySkladService.php

# Should output line number with the header
# Example: 173:    'X-Lognex-WebHook-DisableByPrefix' => config('app.url'),
```

**Step 4: Commit the change**

```bash
git add app/Services/MoySkladService.php
git commit -m "fix: Add X-Lognex-WebHook-DisableByPrefix header to prevent infinite webhook loops

CRITICAL: This header tells МойСклад to disable webhooks for changes
made by our application, preventing infinite webhook loops.

Without this header:
- Main updates product → webhook → Child syncs → webhook → LOOP
- System overload and instability

With this header:
- Main updates product → webhook disabled → Child syncs → no webhook
- System stable
"
```

**Step 5: Deploy**

```bash
# Deploy to staging first
./deploy.sh

# Restart queue worker (IMPORTANT!)
./restart-queue.sh
```

### How It Works

**МойСклад Webhook Behavior:**

When МойСклад receives a request with `X-Lognex-WebHook-DisableByPrefix: https://app.cavaleria.ru`:
- It checks if webhook URL starts with this prefix
- If yes: **webhook is NOT triggered** for this change
- If no: webhook is triggered normally

**Our Flow WITH Header (Correct):**
```
1. Main app updates Child product via API
   Request includes: X-Lognex-WebHook-DisableByPrefix: https://app.cavaleria.ru

2. МойСклад updates Child product

3. МойСклад checks: Should I send webhook?
   - Webhook URL: https://app.cavaleria.ru/api/webhooks/moysklad
   - DisableByPrefix: https://app.cavaleria.ru
   - URL starts with prefix? YES
   - Decision: DON'T send webhook ✅

4. No webhook sent → No loop → System stable ✅
```

**Our Flow WITHOUT Header (Wrong - Current State):**
```
1. Main app updates Child product via API
   Request MISSING: X-Lognex-WebHook-DisableByPrefix

2. МойСклад updates Child product

3. МойСклад checks: Should I send webhook?
   - No DisableByPrefix header provided
   - Decision: Send webhook ❌

4. Webhook sent → Child app processes → Updates Main → Webhook → LOOP ♾️
```

---

## ✅ Pre-Deployment Checklist

### Before Deploying to Staging

- [ ] **Critical Fix Applied**
  - [ ] `X-Lognex-WebHook-DisableByPrefix` header added to MoySkladService
  - [ ] Code committed to git
  - [ ] Change reviewed (verify config('app.url') returns correct domain)

- [ ] **Database Backup**
  - [ ] Production database backup created
  - [ ] Backup verified (file size > 0, can be opened)
  - [ ] Backup stored securely

- [ ] **Environment Check**
  - [ ] Staging environment available
  - [ ] Test МойСклад accounts available (1 main + 2-3 children)
  - [ ] Queue worker running on staging
  - [ ] Redis running on staging

- [ ] **Documentation Read**
  - [ ] Read [18-webhook-system.md](18-webhook-system.md) (webhook architecture)
  - [ ] Read this file completely
  - [ ] Understand rollback procedure

### Before Deploying to Production

- [ ] **Staging Validation Complete**
  - [ ] Staging deployed successfully
  - [ ] Critical fix verified working
  - [ ] No infinite loops observed (48h monitoring)
  - [ ] Webhook processing success rate >95%

- [ ] **Production Preparation**
  - [ ] Production database backup created (fresh, within 1 hour)
  - [ ] Team available for monitoring
  - [ ] Rollback plan understood
  - [ ] Off-peak deployment time scheduled

- [ ] **Communication**
  - [ ] Users notified of maintenance window (if needed)
  - [ ] Team informed of deployment schedule

---

## 📋 Step-by-Step Deployment Guide

### Phase 1: Staging Deployment (Day 1)

**1.1 Deploy Code to Staging**

```bash
# On your local machine
git push origin main

# SSH to staging server
ssh your-staging-server
cd /var/www/multiaccount

# Pull latest code
git pull origin main

# Install dependencies (if needed)
composer install --no-dev --optimize-autoloader

# Run migrations (if any new)
php artisan migrate --force

# Clear caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart queue worker
./restart-queue.sh
```

**1.2 Verify Deployment**

```bash
# Check queue worker is running
ps aux | grep "queue:work"

# Check logs for errors
tail -100 storage/logs/laravel.log

# Test webhook endpoint is accessible
curl -I https://staging.app.cavaleria.ru/api/webhooks/moysklad
# Should return: 200 OK or 405 Method Not Allowed (GET not allowed, POST is)
```

**1.3 Setup Test Webhooks**

```bash
# Setup webhooks on test account
php artisan webhook:setup --account=test-main-account-id

# Verify webhooks created
php artisan webhook:stats
```

**1.4 Trigger Test Webhook**

```
1. Login to МойСклад test main account
2. Open any product
3. Change price: 100 → 101
4. Save
5. Wait 30 seconds
6. Check webhook_logs table
```

```bash
# Check webhook was received
php artisan tinker --execute="echo \App\Models\WebhookLog::latest()->first();"

# Check no errors in logs
tail -50 storage/logs/laravel.log | grep -i error
```

**1.5 Monitor for Loops**

```bash
# Monitor webhook_logs for 10 minutes
watch -n 10 'psql -c "SELECT COUNT(*), entity_type, action FROM webhook_logs WHERE created_at > NOW() - INTERVAL '\''10 minutes'\'' GROUP BY entity_type, action;"'

# Check for same entity_id appearing multiple times (loop indicator)
psql -c "SELECT entity_id, COUNT(*) as cnt FROM webhook_logs WHERE created_at > NOW() - INTERVAL '1 hour' GROUP BY entity_id HAVING COUNT(*) > 2 ORDER BY cnt DESC LIMIT 10;"

# If cnt > 2 for same entity_id → possible loop → INVESTIGATE
```

### Phase 2: Staging Validation (Days 1-2, 24-48 hours)

**2.1 Continuous Monitoring**

```bash
# Setup monitoring script
cat > monitor-webhooks.sh << 'EOF'
#!/bin/bash
echo "=== Webhook Monitoring (Last 1 Hour) ==="
date
echo ""
echo "Total webhooks:"
psql multiaccount -c "SELECT COUNT(*) FROM webhook_logs WHERE created_at > NOW() - INTERVAL '1 hour';"
echo ""
echo "By status:"
psql multiaccount -c "SELECT status, COUNT(*) FROM webhook_logs WHERE created_at > NOW() - INTERVAL '1 hour' GROUP BY status;"
echo ""
echo "By entity type:"
psql multiaccount -c "SELECT entity_type, COUNT(*) FROM webhook_logs WHERE created_at > NOW() - INTERVAL '1 hour' GROUP BY entity_type;"
echo ""
echo "Potential loops (same entity >2 times):"
psql multiaccount -c "SELECT entity_id, entity_type, COUNT(*) as cnt FROM webhook_logs WHERE created_at > NOW() - INTERVAL '1 hour' GROUP BY entity_id, entity_type HAVING COUNT(*) > 2 ORDER BY cnt DESC LIMIT 5;"
EOF

chmod +x monitor-webhooks.sh

# Run every hour
watch -n 3600 ./monitor-webhooks.sh
```

**2.2 Test Scenarios**

**Scenario 1: Product Price Update (Main → Child)**
```
1. Login to Main МойСклад account
2. Update product price: 100 → 150
3. Save
4. Expected result:
   - 1 webhook received in webhook_logs (action=UPDATE, entity_type=product)
   - 1 sync task created per child account in sync_queue
   - Child product prices updated within 5 minutes
   - NO additional webhooks for this product (no loop)
```

**Scenario 2: New Product Created (Main → Child)**
```
1. Create new product in Main
2. Expected result:
   - 1 webhook received (action=CREATE)
   - Sync tasks created for filtered children
   - Product appears in children within 5 minutes
   - Product folders synced if create_product_folders=true
```

**Scenario 3: Product Deleted in Child (Auto-Recreate)**
```
1. Delete product in Child МойСклад
2. Expected result:
   - 1 webhook received (action=DELETE, child account)
   - Mapping deleted
   - Sync task created to recreate from Main
   - Product restored in Child within 5 minutes
```

**Scenario 4: Order Created in Child (Child → Main)**
```
1. Create customer order in Child МойСклад
2. Expected result:
   - 1 webhook received (action=CREATE, entity_type=customerorder)
   - Order synced to Main IMMEDIATELY (no queue)
   - Order appears in Main within 30 seconds
```

**2.3 Success Criteria**

Staging is ready for production if:
- ✅ All 4 test scenarios pass
- ✅ No infinite loops observed (48 hours monitoring)
- ✅ Webhook processing success rate >95%
- ✅ Average webhook processing time <5 seconds
- ✅ No errors in logs related to webhooks
- ✅ Queue worker stable (no crashes)

### Phase 3: Production Deployment (Day 3)

**3.1 Pre-Deployment**

```bash
# Create fresh production backup
ssh production-server
cd /var/www/multiaccount
sudo -u postgres pg_dump multiaccount > backup_webhook_deploy_$(date +%Y%m%d_%H%M%S).sql
ls -lh backup_webhook_deploy_*.sql
# Verify size is reasonable (>1MB)
```

**3.2 Deploy to Production**

```bash
# On local machine
git push origin main

# On production server
ssh production-server
cd /var/www/multiaccount

# Deploy
./deploy.sh

# Restart queue worker (CRITICAL!)
./restart-queue.sh

# Verify deployment
php artisan --version
git log -1 --oneline
```

**3.3 Gradual Rollout**

**DO NOT enable webhooks for all accounts at once!**

**Step 1: Enable for 1 test account (Day 3, first 2 hours)**
```bash
php artisan webhook:setup --account=test-account-id

# Monitor closely for 2 hours
./monitor-webhooks.sh
```

**Step 2: Enable for 5 accounts (Day 3, next 6 hours)**
```bash
# Choose 5 small accounts with low activity
php artisan webhook:setup --account=account-1-id
php artisan webhook:setup --account=account-2-id
# ... etc

# Monitor for 6 hours
```

**Step 3: Enable for 20 accounts (Day 4, 24 hours)**
```bash
# Enable for 20 medium accounts
# Monitor for 24 hours
```

**Step 4: Enable for all accounts (Day 5+)**
```bash
# Enable for all remaining accounts
php artisan webhook:setup --all

# Monitor for 1 week
```

### Phase 4: Post-Deployment Validation

**4.1 Immediate Checks (First 10 minutes)**

```bash
# Check no errors in logs
tail -100 storage/logs/laravel.log | grep -i error

# Check webhooks being received
psql -c "SELECT COUNT(*) FROM webhook_logs WHERE created_at > NOW() - INTERVAL '10 minutes';"

# Check queue worker processing
psql -c "SELECT COUNT(*) FROM sync_queue WHERE status = 'processing';"

# Check for loops
psql -c "SELECT entity_id, COUNT(*) FROM webhook_logs WHERE created_at > NOW() - INTERVAL '10 minutes' GROUP BY entity_id HAVING COUNT(*) > 2;"
# Should return 0 rows
```

**4.2 First Hour Checks**

```bash
# Run monitoring script every 10 minutes
watch -n 600 ./monitor-webhooks.sh

# Check webhook health
php artisan webhook:health-check

# Check processing success rate
php artisan webhook:stats
# Success rate should be >95%
```

**4.3 First 24 Hours Checks**

```bash
# Daily webhook report
php artisan webhook:stats --since="24 hours ago"

# Check for any failed webhooks
psql -c "SELECT * FROM webhook_logs WHERE status = 'failed' AND created_at > NOW() - INTERVAL '24 hours' LIMIT 10;"

# Investigate failed webhooks
php artisan tinker --execute="
\$failed = \App\Models\WebhookLog::where('status', 'failed')->latest()->first();
if (\$failed) {
    echo 'Error: ' . \$failed->error_message . \"\n\";
    echo 'Payload: ' . json_encode(\$failed->payload, JSON_PRETTY_PRINT);
}
"
```

**4.4 First Week Checks**

```bash
# Weekly report
php artisan webhook:stats --since="7 days ago"

# Check system stability
# - No infinite loops
# - Success rate >95%
# - No memory leaks (queue worker memory usage stable)
# - No API rate limiting from МойСклад
```

---

## 🔍 Monitoring & Alerts

### Key Metrics to Monitor

**1. Webhook Processing Metrics**
```bash
# Success rate (should be >95%)
SELECT
  status,
  COUNT(*) as cnt,
  ROUND(100.0 * COUNT(*) / SUM(COUNT(*)) OVER (), 2) as percentage
FROM webhook_logs
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY status;

# Average processing time (should be <5 seconds)
SELECT
  AVG(EXTRACT(EPOCH FROM (updated_at - created_at))) as avg_seconds
FROM webhook_logs
WHERE status = 'completed'
AND created_at > NOW() - INTERVAL '1 hour';

# Webhook volume (per hour)
SELECT
  DATE_TRUNC('hour', created_at) as hour,
  COUNT(*) as webhooks_count
FROM webhook_logs
WHERE created_at > NOW() - INTERVAL '24 hours'
GROUP BY hour
ORDER BY hour;
```

**2. Loop Detection**
```bash
# Check for entities appearing multiple times (loop indicator)
SELECT
  entity_id,
  entity_type,
  action,
  COUNT(*) as occurrences
FROM webhook_logs
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY entity_id, entity_type, action
HAVING COUNT(*) > 2
ORDER BY occurrences DESC;

# If ANY row appears → INVESTIGATE IMMEDIATELY
```

**3. Queue Health**
```bash
# Stuck tasks (processing >10 minutes)
SELECT
  id,
  entity_type,
  operation,
  status,
  NOW() - updated_at as stuck_duration
FROM sync_queue
WHERE status = 'processing'
AND updated_at < NOW() - INTERVAL '10 minutes';

# Failed tasks (need retry)
SELECT
  entity_type,
  operation,
  COUNT(*) as failed_count
FROM sync_queue
WHERE status = 'failed'
GROUP BY entity_type, operation;
```

### Alert Thresholds

**CRITICAL Alerts (Immediate Action Required):**
- 🔴 Same entity appearing >2 times in webhook_logs within 10 minutes (LOOP!)
- 🔴 Webhook success rate <90% for 10 minutes
- 🔴 Queue worker not running
- 🔴 More than 100 failed webhook_logs in 1 hour

**WARNING Alerts (Investigate Soon):**
- ⚠️ Webhook success rate <95% for 1 hour
- ⚠️ Average processing time >10 seconds for 1 hour
- ⚠️ More than 1000 pending sync_queue tasks
- ⚠️ More than 20 failed webhook_logs in 1 hour

**INFO Alerts (Monitor):**
- ℹ️ Webhook volume spike (>2x normal)
- ℹ️ New entity types in webhooks (unexpected)

---

## 🚨 Rollback Procedure

### Emergency Rollback (Critical Issues - Infinite Loops, System Down)

**If you detect infinite loops or system instability:**

```bash
# 1. IMMEDIATELY disable all webhooks (30 seconds)
ssh production-server
cd /var/www/multiaccount
php artisan webhook:delete-all --force --confirm

# 2. Stop queue worker to prevent further processing (10 seconds)
sudo supervisorctl stop laravel-worker:*

# 3. Clear webhook queue (10 seconds)
php artisan queue:clear webhooks

# 4. Verify webhooks stopped (30 seconds)
psql -c "SELECT COUNT(*) FROM webhook_logs WHERE created_at > NOW() - INTERVAL '5 minutes';"
# Count should drop to 0 within 5 minutes

# 5. Restart queue worker with default queue only (10 seconds)
sudo supervisorctl start laravel-worker:*

# 6. System should stabilize
# Monitor logs: tail -f storage/logs/laravel.log

# Total rollback time: ~2 minutes
```

### Partial Rollback (Issues with Specific Accounts)

**If webhooks work for most accounts but fail for specific ones:**

```bash
# Disable webhooks for problematic account only
php artisan webhook:delete-all --account=problematic-account-id

# System continues working for other accounts
# Investigate issue with specific account
```

### Code Rollback (If Critical Fix Caused New Issues)

**If the cycle prevention fix itself caused issues:**

```bash
# 1. Revert code
git revert HEAD  # Reverts the cycle prevention commit
git push origin main

# 2. Deploy revert
./deploy.sh
./restart-queue.sh

# 3. Disable all webhooks (system back to manual sync)
php artisan webhook:delete-all --force --confirm

# 4. Investigate issue
# 5. Plan new fix
```

---

## ✅ Success Criteria

### Deployment is Successful If:

**Technical Metrics:**
- ✅ Webhook success rate >95% (48 hours)
- ✅ Zero infinite loops detected (48 hours)
- ✅ Average webhook processing time <5 seconds
- ✅ Queue worker stable (no crashes for 48 hours)
- ✅ No API rate limiting from МойСклад

**Business Metrics:**
- ✅ Products sync to child accounts within 5 minutes of Main update
- ✅ Orders sync from child to Main within 30 seconds
- ✅ No data loss or corruption
- ✅ No user-reported issues

**Operational Metrics:**
- ✅ Zero emergency rollbacks needed
- ✅ Team confident in system stability
- ✅ Monitoring and alerts working
- ✅ Documentation accurate and complete

### When to Declare Production Ready:

- ✅ Staging validation complete (48 hours)
- ✅ Production deployed successfully
- ✅ Gradual rollout complete (1 → 5 → 20 → all accounts)
- ✅ 1 week of stable production operation
- ✅ All success criteria met

**Timeline:**
- Day 1-2: Staging validation
- Day 3: Production deploy + first 5 accounts
- Day 4: 20 accounts
- Day 5-11: All accounts + monitoring
- Day 12: Declare production ready ✅

---

## 📚 Additional Resources

### Documentation

- [18-webhook-system.md](18-webhook-system.md) - Complete webhook architecture
- [19-webhook-roadmap.md](19-webhook-roadmap.md) - Implementation overview
- [19-webhook-tasks.md](19-webhook-tasks.md) - Task breakdown (completed)
- [19-webhook-migration.md](19-webhook-migration.md) - Migration guide (mostly not needed)

### МойСклад Resources

- [МойСклад Webhook Documentation](https://dev.moysklad.ru/doc/api/remap/1.2/#mojsklad-json-api-obschie-swedeniq-vebhuki-api)
- [X-Lognex-WebHook-DisableByPrefix Documentation](https://dev.moysklad.ru/doc/api/remap/1.2/#mojsklad-json-api-obschie-swedeniq-vebhuki-api-vnutri-zaprosow-ot-webhookow)
- [JSON API 1.2](https://dev.moysklad.ru/doc/api/remap/1.2/)

### Support Contacts

If issues arise:
1. Check logs: `storage/logs/laravel.log`
2. Check monitoring: `./monitor-webhooks.sh`
3. Check documentation: docs/18-webhook-system.md
4. Rollback if critical: Follow rollback procedure above

---

## ✍️ Deployment Sign-Off

**Before deploying to production, confirm:**

- [ ] I have read this entire document
- [ ] I understand the critical fix and why it's needed
- [ ] I understand the rollback procedure
- [ ] I have staging validation results (48 hours, success rate >95%, zero loops)
- [ ] I have production database backup (fresh, verified)
- [ ] I have team available for monitoring
- [ ] I have scheduled deployment during off-peak hours
- [ ] I commit to monitoring the system for 1 week post-deployment

**Deployed by:** _________________
**Date:** _________________
**Deployment start time:** _________________
**Deployment complete time:** _________________

**48-Hour Validation:**
- Webhook success rate: ________%
- Infinite loops detected: ________
- Issues encountered: ______________________________

**Sign-off:** _________________
**Date:** _________________

---

**Good luck with the deployment! The system is ready - just needs that one critical fix! 🚀**
