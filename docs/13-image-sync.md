# 13. Image Synchronization

This document describes the implementation of image synchronization between МойСклад accounts.

## Overview

The image synchronization system automatically copies product, bundle, and variant images from the main account to child accounts during entity synchronization. It uses a queue-based approach with batch upload optimization to minimize API calls and improve performance.

## Key Features

- **Batch upload**: Multiple images uploaded in a single API request (МойСклад limit: 20MB per request)
- **Replace strategy**: Old images are deleted before uploading new ones (МойСклад default behavior)
- **Configurable limits**: Sync disabled, only first image, or all images (max 10)
- **Queue-based processing**: Images sync asynchronously with medium priority (50)
- **File size validation**: 10MB limit per image with HEAD request check before download
- **Automatic cleanup**: Old temporary files removed daily via scheduled command
- **Error handling**: Individual image failures don't block batch upload

## Architecture

### Flow Diagram

```
Main Account (МойСклад API)
         ↓
    [Get Entity with images]
         ↓
    Batch Sync (Product/Bundle)
         ↓
    [Store _original_images in entity data]
         ↓
    Batch POST to Child Account
         ↓
    [Queue image_sync task with ALL images]
         ↓
    ProcessSyncQueueJob
         ↓
    ImageSyncService::syncImagesForEntity
         ↓
    [1. Delete all existing images]
         ↓
    [2. Download all images (60s timeout, 10MB limit)]
         ↓
    [3. Convert to base64]
         ↓
    [4. Split into batches (20MB limit)]
         ↓
    [5. Batch POST to МойСклад]
         ↓
    [6. Cleanup temp files]
```

## Components

### 1. ImageSyncService

**Location**: `app/Services/ImageSyncService.php`

**Key Methods**:

#### `syncImagesForEntity()`

Main method for batch image synchronization.

```php
public function syncImagesForEntity(
    string $mainAccountId,
    string $childAccountId,
    string $entityType,      // 'product', 'bundle', or 'variant'
    string $parentEntityId,  // Entity ID in main account
    string $childEntityId,   // Entity ID in child account
    array $images            // Array of images from МойСклад API
): bool
```

**Process**:
1. Get settings and image limit
2. Delete all existing images from child entity (Replace strategy)
3. Download images from main account with validation
4. Convert images to base64
5. Split into batches (20MB limit per request)
6. Upload batches to child account
7. Cleanup temporary files

#### `downloadImage()`

Downloads image from МойСклад with validation.

**Features**:
- 60-second timeout (increased from 30s)
- HEAD request to check file size before download (10MB limit)
- Size validation after download
- Temporary storage in `storage/app/temp_images/`

#### `batchUploadImages()`

Uploads multiple images in a single request.

**Features**:
- Automatic batch splitting if total size > 20MB
- Base64 encoding
- Error handling per batch

#### `getImageLimit()`

Returns image limit based on settings.

**Options**:
- `0`: Sync disabled
- `1`: Only first image
- `10`: All images (max 10)

### 2. MoySkladService

**New Methods**:

#### `batchUploadImages()`

```php
public function batchUploadImages(
    string $entityType,  // 'product', 'bundle', or 'variant'
    string $entityId,
    array $images        // Array of base64-encoded images
): array
```

Sends POST request to `entity/{entityType}/{entityId}/images`.

#### `deleteImage()`

```php
public function deleteImage(
    string $entityType,
    string $entityId,
    string $imageId
): array
```

Sends DELETE request to `entity/{entityType}/{entityId}/images/{imageId}`.

### 3. Sync Services Integration

**Modified Files**:
- `app/Services/ProductSyncService.php`
- `app/Services/BundleSyncService.php`
- `app/Services/VariantSyncService.php`

**Changes**:

#### Individual Sync (Legacy)

For individual entity sync (non-batch), images are queued using `queueImageSync()`:

```php
// Example from ProductSyncService::syncProduct()
if ($result && $settings->sync_images && isset($product['images']['rows']) && !empty($product['images']['rows'])) {
    $this->queueImageSync(
        $mainAccountId,
        $childAccountId,
        'product',
        $productId,
        $result['id'],
        $product['images']['rows'],
        $settings
    );
}
```

**Priority**: 50 (medium) - changed from 80 (low) for better responsiveness.

#### Batch Sync (New)

For batch sync, images are stored in entity data and queued after batch POST:

```php
// In prepareProductForBatch() / prepareBundleForBatch()
$productData['_original_images'] = $product['images']['rows'] ?? [];

// In ProcessSyncQueueJob::processBatchProductSync()
if ($settings->sync_images && !empty($preparedProduct['_original_images'])) {
    $this->queueImageSyncForEntity(
        $mainAccountId,
        $childAccountId,
        'product',
        $preparedProduct['_original_id'],
        $created['id'],
        $preparedProduct['_original_images'],
        $settings
    );
}
```

### 4. ProcessSyncQueueJob

**Location**: `app/Jobs/ProcessSyncQueueJob.php`

**Key Methods**:

#### `processImageSync()`

Handles `image_sync` queue tasks. Supports two formats:

**Format 1: Legacy (single image)**
```json
{
  "main_account_id": "...",
  "child_account_id": "...",
  "parent_entity_type": "product",
  "parent_entity_id": "...",
  "child_entity_id": "...",
  "image_url": "https://...",
  "filename": "image.jpg"
}
```

**Format 2: Batch (multiple images)**
```json
{
  "main_account_id": "...",
  "child_account_id": "...",
  "parent_entity_type": "product",
  "parent_entity_id": "...",
  "child_entity_id": "...",
  "images": [
    {
      "meta": {
        "downloadHref": "https://..."
      },
      "filename": "image1.jpg"
    },
    // ... more images
  ]
}
```

#### `queueImageSyncForEntity()`

Helper method to create ONE queue task with ALL images for an entity.

**Priority**: 50 (medium priority)

```php
protected function queueImageSyncForEntity(
    string $mainAccountId,
    string $childAccountId,
    string $entityType,
    string $parentEntityId,
    string $childEntityId,
    array $images,
    \App\Models\SyncSetting $settings
): bool
```

**Creates ONE task** with array of all images (not one task per image).

### 5. CleanupTempImages Command

**Location**: `app/Console/Commands/CleanupTempImages.php`

**Purpose**: Remove old temporary image files to prevent disk space issues.

**Schedule**: Daily at 3:00 AM (configured in `routes/console.php`)

**Usage**:

```bash
# Manual cleanup (default: files older than 24 hours)
php artisan sync:cleanup-temp-images

# Custom age threshold
php artisan sync:cleanup-temp-images --hours=48

# Dry run (show what would be deleted)
php artisan sync:cleanup-temp-images --dry-run
```

**Features**:
- Configurable age threshold (default: 24 hours)
- Dry-run mode for testing
- Progress bar for large cleanups
- Detailed logging
- Statistics (files deleted, space freed)

## Settings

Image sync is controlled by settings in the `sync_settings` table:

### `sync_images`

Boolean flag to enable synchronization of **first image only**.

**Default**: `false` (disabled)

**Type**: `boolean`

### `sync_images_all`

Boolean flag to enable synchronization of **all images** (up to 10 per entity - МойСклад limit).

**Default**: `false` (disabled)

**Type**: `boolean`

**Note**: These settings are **mutually exclusive** in the UI:
- When you enable "Только первое изображение" (`sync_images`), "Все изображения" (`sync_images_all`) is automatically disabled
- When you enable "Все изображения" (`sync_images_all`), "Только первое изображение" (`sync_images`) is automatically disabled

**Example**:

```php
// Enable sync of first image only
$settings->sync_images = true;
$settings->sync_images_all = false;
$settings->save();

// Enable sync of all images
$settings->sync_images = false;
$settings->sync_images_all = true;
$settings->save();

// Disable image sync completely
$settings->sync_images = false;
$settings->sync_images_all = false;
$settings->save();
```

## Database Schema

### sync_queue Table

Image sync tasks are stored with:

```
entity_type: 'image_sync'
priority: 50 (medium)
payload: {
  main_account_id: "...",
  child_account_id: "...",
  parent_entity_type: "product|bundle|variant",
  parent_entity_id: "...",
  child_entity_id: "...",
  images: [...]  // Array of images (batch format)
}
max_attempts: 3
```

## Performance Optimization

### Batch Upload vs Individual Upload

**Before (Individual Upload)**:
- 1 task per image
- 1 API request per image
- For 10 images: 10 queue tasks, 10 DELETE + 10 POST requests = **20 API calls**

**After (Batch Upload)**:
- 1 task per entity (with all images)
- 1 DELETE request per existing image + 1 POST request for all new images
- For 10 images: 1 queue task, 10 DELETE + 1 POST request = **11 API calls**

**Savings**: ~45% fewer API calls for image sync

### 20MB Batch Split

If total images size > 20MB, they are automatically split into multiple batches:

```php
// Example: 3 images (15MB, 8MB, 12MB)
// Batch 1: 15MB (image 1)
// Batch 2: 8MB + 12MB (images 2-3)
// Total: 2 API requests instead of 3
```

### File Size Validation

Before downloading, a HEAD request checks file size:

```php
// HEAD request to get Content-Length
if ($fileSize > 10MB) {
    // Skip image, log warning
}
```

**Benefits**:
- Prevents downloading huge files
- Saves bandwidth
- Prevents memory issues

## Error Handling

### Individual Image Failures

If one image fails during batch upload, others continue:

```php
// Example: 5 images, image #3 fails
// Result: Images 1,2,4,5 uploaded successfully
// Log: Warning about image #3 failure
// Task status: Completed with warnings
```

### Retry Logic

Failed tasks are retried up to 3 times with exponential backoff:

- Attempt 1: Immediate
- Attempt 2: After 1 minute
- Attempt 3: After 5 minutes

After 3 failed attempts, task is marked as `failed`.

### Common Errors

#### "Image file too large"

**Cause**: Image > 10MB
**Solution**: Reduce image size in main account or increase limit in code

#### "Download timeout"

**Cause**: Slow network or large file
**Solution**: Timeout increased to 60s (configurable in code)

#### "Failed to delete existing image"

**Cause**: Image already deleted or doesn't exist
**Solution**: Non-critical, sync continues

#### "Batch size exceeds 20MB"

**Cause**: Single image > 20MB
**Solution**: This shouldn't happen (10MB limit per image)

## Testing

### Manual Testing

1. **Enable image sync in settings**:

```sql
UPDATE sync_settings
SET sync_images = true, sync_images_mode = 'all'
WHERE account_id = 'child-account-id';
```

2. **Add images to product in main account**:
   - Open product in МойСклад
   - Upload 2-3 images
   - Save

3. **Trigger sync**:

```bash
# Via admin panel
# Click "Sync All Products" for child account

# Or via artisan (batch sync)
php artisan queue:process-sync --account=child-account-id
```

4. **Monitor queue**:

```bash
# Check queue status
./monitor-queue.sh

# Watch logs
tail -f storage/logs/laravel.log | grep -E "image|Image"
```

5. **Verify in child account**:
   - Open product in child МойСклад account
   - Check that images appear

### Cleanup Testing

```bash
# Test cleanup (dry-run)
php artisan sync:cleanup-temp-images --dry-run

# Actual cleanup
php artisan sync:cleanup-temp-images

# Custom threshold (delete files older than 1 hour)
php artisan sync:cleanup-temp-images --hours=1
```

## Monitoring

### Logs

**Location**: `storage/logs/laravel.log`

**Key log entries**:

```
[INFO] Batch image sync started
[INFO] Deleted N existing images from child entity
[INFO] Downloaded image: filename.jpg (size: X KB)
[WARNING] Image file too large, skipping: filename.jpg (size: 12 MB)
[INFO] Batch upload successful (X images uploaded)
[INFO] Batch image sync completed
[ERROR] Failed to sync images for entity
```

### Queue Monitoring

```bash
# Count pending image sync tasks
SELECT COUNT(*) FROM sync_queue
WHERE entity_type = 'image_sync' AND status = 'pending';

# Check failed image sync tasks
SELECT * FROM sync_queue
WHERE entity_type = 'image_sync' AND status = 'failed'
ORDER BY created_at DESC
LIMIT 10;
```

### Disk Space Monitoring

```bash
# Check temp_images directory size
du -sh storage/app/temp_images/

# Count files
ls -1 storage/app/temp_images/ | wc -l
```

## Best Practices

1. **Start with "first_only" mode**: Test with 1 image before enabling all images

2. **Monitor disk space**: Temporary files are cleaned daily, but monitor during high-volume sync

3. **Check logs after batch sync**: Look for warnings about skipped images

4. **Optimize images in main account**: Keep images < 5MB for best performance

5. **Use batch sync for initial setup**: Individual sync for ongoing updates

6. **Schedule cleanup during low traffic**: Default 3:00 AM is good

## Troubleshooting

### Images not syncing

**Check**:
1. Settings: Either `sync_images = true` (first image only) OR `sync_images_all = true` (all images)
2. Queue: Look for pending `image_sync` tasks in sync_queue table
3. Worker: Ensure queue worker is running (Supervisor)
4. Logs: Check for errors in `storage/logs/laravel.log`

**Common error**: "Image sync is disabled"
- This appears in logs when both `sync_images` and `sync_images_all` are false
- Check the sync settings for the child account in the UI
- Make sure at least one image sync option is enabled

### Temp files not cleaning up

**Check**:
1. Scheduler: `php artisan schedule:list` (should show cleanup command)
2. Cron: Ensure Laravel scheduler is running (cron job)
3. Manual run: `php artisan sync:cleanup-temp-images --dry-run`

### Out of disk space

**Emergency cleanup**:

```bash
# Immediate cleanup (all temp files)
php artisan sync:cleanup-temp-images --hours=0

# Or manually
rm -rf storage/app/temp_images/*
```

### Slow image sync

**Possible causes**:
- Large images (> 5MB)
- Slow network to МойСклад API
- Too many images per product

**Solutions**:
- Reduce image sizes in main account
- Sync fewer images (`first_only` mode)
- Increase worker count in Supervisor

## API Reference

### МойСклад Image API

**Get images** (included in entity expand):

```
GET /api/remap/1.2/entity/product/{id}?expand=images
```

**Upload images** (batch):

```
POST /api/remap/1.2/entity/product/{id}/images
Content-Type: application/json

[
  {
    "filename": "image1.jpg",
    "content": "base64_encoded_image_data"
  },
  {
    "filename": "image2.jpg",
    "content": "base64_encoded_image_data"
  }
]
```

**Delete image**:

```
DELETE /api/remap/1.2/entity/product/{id}/images/{imageId}
```

**Download image**:

```
GET {downloadHref from entity.images.rows[].meta.downloadHref}
```

## Future Improvements

1. **Parallel download**: Download multiple images concurrently
2. **Image compression**: Automatically compress large images before upload
3. **CDN caching**: Cache images to reduce МойСклад API load
4. **Incremental sync**: Only sync changed images (requires image hash comparison)
5. **Progress tracking**: UI to show image sync progress
6. **Retry failed images**: Separate queue for failed image retries

## Related Documentation

- [Batch Synchronization](04-batch-sync.md) - Batch optimization architecture
- [Service Layer](05-services.md) - Service responsibilities
- [Queue & Supervisor](02-queue-supervisor.md) - Queue system architecture
- [Common Patterns](10-common-patterns.md) - Best practices and troubleshooting

## Changelog

### 2025-10-29
- **BUGFIX**: Fixed ImageSyncHandler after modular refactoring (commit f3bada3)
  - **Problem**: Handler expected `entity_type` in payload, but all sync services send `parent_entity_type`
  - **Cause**: During modular refactoring (ProcessSyncQueueJob → handlers), ImageSyncHandler was created based on old batch logic that expected `entity_type`, but after refactoring ALL tasks are created via sync services which use `parent_entity_type`
  - **Solution**:
    - Changed to read `parent_entity_type` (with fallback to `entity_type` for backwards compatibility)
    - Split handler into two methods: `handleBatchImageSync()` and `handleLegacyImageSync()`
    - Added support for both payload formats:
      - Batch format: `{parent_entity_type, parent_entity_id, child_entity_id, images: [...]}`
      - Legacy format: `{parent_entity_type, parent_entity_id, child_entity_id, image_url, filename}`
  - **Affected file**: `app/Services/Sync/Handlers/ImageSyncHandler.php`
  - **Error fixed**: "Invalid payload: missing entity_type for image sync"

### 2025-10-22
- **BUGFIX**: Fixed image sync settings check to support `sync_images_all` option
  - Previously only checked `sync_images` flag, causing "Image sync is disabled" error when using "All images" option
  - Now correctly checks both `sync_images` OR `sync_images_all` (matches other sync services)
  - Affected methods: `syncImagesForEntity()` and `syncImages()` in ImageSyncService

### 2025-01-22
- Initial implementation with batch upload support
- Added Replace strategy (delete old, upload new)
- Added file size validation (10MB limit)
- Increased timeout from 30s to 60s
- Changed priority from 80 to 50 for better responsiveness
- Added CleanupTempImages command
- Added daily scheduled cleanup at 3:00 AM
- Created comprehensive documentation
