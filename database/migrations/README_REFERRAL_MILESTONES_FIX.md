# Referral Milestones Migration Fix

## ⚠️ PRODUCTION SAFETY - NO DATA LOSS

**THIS FIX IS 100% SAFE FOR PRODUCTION - NO DATA WILL BE LOST**

- ✅ This migration ONLY modifies INDEXES (not table structure or data)
- ✅ NO rows are deleted or modified
- ✅ NO tables are dropped or truncated
- ✅ NO fresh install required
- ✅ All existing referral milestone records remain intact
- ✅ Safe to run on live production database

Indexes are just performance structures that point to your data. Dropping and recreating an index **does not affect the actual data** in the table.

## Issue

The `referral_milestones` table migration had an index naming issue:

1. **Original migration** (commit d55fd7c9): Created the table without explicit index names
   - Laravel auto-generated index names like `referral_milestones_referrer_id_referee_id_level_milestone_unique`
   - These names could be too long for MySQL (max 64 characters)

2. **Fixed migration** (commit 063a632): Updated to use explicit short names
   - `ref_milestone_unique` for the unique index
   - `ref_level_index` for the regular index

3. **The Problem**: If the original migration ran in production before the fix, the table exists with different index names than the updated migration expects.

## Solution

Migration file: `2026_08_02_005630_fix_referral_milestones_index_names.php`

This migration:
1. Checks if the `referral_milestones` table exists
2. Checks if the indexes already have the correct names
3. If not, drops the old auto-generated indexes and creates new ones with explicit short names
4. Handles multiple database drivers (MySQL, PostgreSQL, SQLite)
5. Is idempotent - safe to run multiple times

## How to Apply

**SAFE FOR PRODUCTION** - Run migrations normally:

```bash
php artisan migrate
```

### What Happens:
1. ✅ Checks if table exists (skips if not - no error)
2. ✅ Checks current index names
3. ✅ If indexes are already correct → **does nothing** (idempotent)
4. ✅ If indexes need fixing → drops old index, creates new one
5. ✅ **All data remains intact** - only index metadata changes

### Downtime:
- Minimal (milliseconds) - just index recreation time
- No application restart needed
- No data migration required

The fix migration will:
- Skip if the table doesn't exist (table will be created by the main migration)
- Skip if indexes already have correct names
- Fix the indexes if they have auto-generated names

## Testing

To verify the fix worked:

```sql
-- MySQL
SHOW INDEXES FROM referral_milestones;

-- PostgreSQL  
SELECT indexname FROM pg_indexes WHERE tablename = 'referral_milestones';

-- SQLite
SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='referral_milestones';
```

You should see:
- `ref_milestone_unique` - unique index on (referrer_id, referee_id, level_milestone)
- `ref_level_index` - index on (referrer_id, level_milestone)

## Migration Files Involved

1. `2026_08_01_205050_create_referral_milestones_table.php` - Creates the table (now with explicit index names)
2. `2026_08_02_005630_fix_referral_milestones_index_names.php` - Fixes existing tables with wrong index names
