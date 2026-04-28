# Sync Issues Fixed

## Problems Identified

1. **Fee Due Data (`total_due_amount`) Not Syncing**
   - The `total_due_amount` field exists in the database but was missing from the Member model's `create()` and `update()` methods
   - This caused fee due amounts to not be synced to the online database

2. **Profile Images Not Syncing**
   - Only the image path was being synced to the database
   - The actual image files were not being uploaded to the online server
   - This caused broken image links on the online server

## Solutions Implemented

### 1. Fixed `total_due_amount` Sync

**File:** `app/models/Member.php`

**Changes:**
- Added `total_due_amount` field to the `create()` method INSERT query
- Added `total_due_amount` field to the `update()` method UPDATE query
- Added binding for `total_due_amount` parameter in both methods

**Result:** Fee due amounts will now sync correctly to the online database.

---

### 2. Fixed Profile Image Sync

**Files Created/Modified:**
- `api/sync-image.php` (NEW) - Endpoint to receive image uploads on online server
- `api/sync-local.php` (MODIFIED) - Added image sync functionality

**How It Works:**

1. **Image Sync Endpoint (`api/sync-image.php`):**
   - Receives image files from local server during sync
   - Validates file type and size
   - Saves images to the online server's `uploads/profiles/` directory
   - Uses the same API key authentication as other sync endpoints

2. **Image Sync Function (`syncProfileImages()` in `sync-local.php`):**
   - Scans all members (men and women) for profile images
   - For each unique image path:
     - Checks if the image file exists locally
     - Uploads the image file to the online server using cURL
     - Handles errors gracefully
   - Runs BEFORE member data sync to ensure images are available

**Result:** Profile images will now be uploaded to the online server during sync, and image links will work correctly.

---

## Testing

To test the fixes:

1. **Test Fee Due Sync:**
   - Update a member's fee due amount in local database
   - Run sync
   - Check online database - `total_due_amount` should be synced

2. **Test Image Sync:**
   - Add/update a member with a profile image in local database
   - Run sync
   - Check online server - image file should exist in `uploads/profiles/`
   - Check online database - image path should be correct
   - Verify image displays correctly on online server

---

## Important Notes

1. **Image Sync Runs First:**
   - Images are synced before member data to ensure files are available when member records are created/updated

2. **Image File Requirements:**
   - Images must exist in the local `uploads/profiles/` directory
   - Maximum file size: 5MB
   - Supported formats: JPEG, PNG, GIF, WebP

3. **Error Handling:**
   - If an image fails to upload, the error is logged but sync continues
   - Member data will still sync even if image upload fails (image path will be in database, but file may be missing)

4. **Performance:**
   - Image sync may take longer if there are many images
   - Each image is uploaded individually (consider batch upload for future optimization)

---

## Files Modified

1. ✅ `app/models/Member.php` - Added `total_due_amount` field
2. ✅ `api/sync-local.php` - Added `syncProfileImages()` function
3. ✅ `api/sync-image.php` - NEW file for image upload endpoint

---

## Next Steps

1. Test the sync functionality with both fixes
2. Monitor sync logs for any errors
3. If needed, optimize image sync for better performance with many images

