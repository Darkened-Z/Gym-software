# Migration to Single Name Field

## Overview
This document describes the migration from separate `first_name` and `last_name` fields to a single `name` field throughout the gym management system.

## Changes Made

### 1. Database Migration Script
- **File**: `database/migrate_to_single_name_field.sql`
- This script will:
  - Add a new `name` column to both `members_men` and `members_women` tables
  - Migrate existing data by combining `first_name` and `last_name` into `name`
  - Remove the old `first_name` and `last_name` columns

### 2. Backend Changes

#### Models Updated:
- **app/models/Member.php**: Updated all queries and methods to use `name` instead of `first_name`/`last_name`
- **app/models/Attendance.php**: Updated to select `name` from member table
- **app/models/Payment.php**: Updated to select `name` from member table

#### API Endpoints Updated:
- **api/members.php**: Updated create/update operations to use `name` field
- **api/member-profile.php**: Updated search queries to use `name` field
- **api/payments.php**: Updated to select `name` from member table
- **api/get-due-fees.php**: Updated search queries to use `name` field

#### Import Controller Updated:
- **api/controllers/ImportController.php**: Updated to map Excel `ac_name` column directly to `name` field (no longer splits into first/last)

### 3. Frontend Changes

#### JavaScript Files Updated:
- **assets/js/admin-dashboard.js**: 
  - Changed form to use single "Name" field instead of "First Name" and "Last Name"
  - Updated all display references from `${m.first_name} ${m.last_name}` to `${m.name}`
  - Updated form data submission to use `name` field
  
- **assets/js/member-profile.js**: 
  - Updated profile display to show single `name` field
  - Updated profile placeholder initials to use first character of `name`

## Migration Steps

### IMPORTANT: Backup Your Database First!

1. **Backup your database** before running the migration script

2. **Run the migration script**:
   - Open phpMyAdmin or your MySQL client
   - Select your database
   - Run the SQL script: `database/migrate_to_single_name_field.sql`
   - This will:
     - Add the `name` column
     - Migrate existing data (combines first_name + last_name)
     - Remove the old columns

3. **Verify the migration**:
   - Check that all members have their names in the `name` column
   - Verify that `first_name` and `last_name` columns are removed

4. **Test the application**:
   - Test creating a new member with the single name field
   - Test editing an existing member
   - Test importing members from Excel
   - Test searching for members
   - Test viewing member profiles

## Excel Import Format

When importing members from Excel, the system now expects:
- **Ac_Name** column: Full name (single field) - will be mapped directly to `name` field
- No need to split names into separate columns

## Notes

- Existing data is preserved: All existing `first_name` and `last_name` values are combined into the new `name` field
- The migration script uses `TRIM(CONCAT(...))` to properly combine names with a space
- If a member had only a first name, it will be preserved as-is in the `name` field
- The `name` field is set to VARCHAR(200) to accommodate full names

## Rollback (if needed)

If you need to rollback this change, you would need to:
1. Restore from your database backup
2. Revert the code changes using git (if using version control)

## Support

If you encounter any issues during migration, check:
1. Database connection settings
2. Table permissions
3. That all code files have been updated (no cached versions)

