# GM Console: Migrations, Seeders & Auto-Sync

This document explains the new GM Console features for running migrations/seeders and auto-syncing content changes to seeder files.

## Feature 1: Run Migrations & Seeders from GM Console

### Location
Navigate to: **GM Console → Commands Tab**

### New Commands Available

| Command | Label | Description | Danger Level |
|---------|-------|-------------|--------------|
| `migrate` | Run Migrations | Runs any pending database migrations. Safe to run multiple times. | Low |
| `db:seed` | Run Seeders | Re-runs all database seeders to update game content (items, monsters, zones, etc.). Safe to run — all seeders use updateOrCreate() to avoid duplicates. | Low |

### How to Use

1. **Access GM Console**: Navigate to `/admin` and select the "Commands" tab
2. **Select Command**: Click on "Run Migrations" or "Run Seeders" from the command list
3. **Execute**: Click the "Run" button to execute the command
4. **View Output**: See the command output and success/error messages in real-time

### Benefits

- **No SSH Required**: GMs can run essential database commands without server access
- **Safe Execution**: Both commands are safe to run multiple times
- **Logged Actions**: All command executions are logged with user info and timestamp

---

## Feature 2: Auto-Sync Content Edits to Seeder Files

### What It Does

When a GM edits game content through the GM Console, the system automatically updates the corresponding seeder file. This ensures that changes persist when `php artisan db:seed` is run again.

### Supported Content Types

| Content Type | Seeder File | Auto-Sync Status |
|-------------|-------------|------------------|
| Items | `ItemSeeder.php` | ✅ Enabled |
| Monsters | `MonsterSeeder.php` | ✅ Enabled |
| Zones | `ZoneSeeder.php` | ⚠️ Manual only |
| Quests | `QuestSeeder.php` | ⚠️ Manual only |
| Skills | `SkillSeeder.php` | ⚠️ Manual only |
| Pets | `PetSeeder.php` | ⚠️ Manual only |

### How It Works

```
┌─────────────────┐
│  GM edits item  │
│  in web UI      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Database       │
│  updated        │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  ItemSeeder.php │
│  auto-updated   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Changes        │
│  committed      │
│  to Git         │
└─────────────────┘
```

### Example Workflow

#### Before Auto-Sync (Old Way ❌)

1. GM edits "Health Potion" healing from 120 HP → 150 HP in GM Console
2. Database updates immediately
3. ItemSeeder.php still has 120 HP
4. Developer runs `php artisan db:seed`
5. **Change is lost!** Health Potion reverts to 120 HP

#### With Auto-Sync (New Way ✅)

1. GM edits "Health Potion" healing from 120 HP → 150 HP in GM Console
2. Database updates immediately
3. **ItemSeeder.php automatically updates to 150 HP**
4. Developer runs `php artisan db:seed`
5. **Change persists!** Health Potion stays at 150 HP

### Technical Details

**Service**: `App\Services\SeederSyncService`

**Integration Point**: `GmContentController::update()` method

**Process**:
1. GM saves content change
2. Database record updated via Eloquent
3. `SeederSyncService::syncToSeeder()` called
4. Service parses seeder file and finds matching entry by `key`
5. Entry replaced with updated values
6. Seeder file written back to disk
7. Change appears in next Git commit

### Pattern Matching

The service uses regex to find and replace seeder entries:

```php
// Finds: ['key' => 'health_potion', ...]
$pattern = "/\['key'\s*=>\s*'health_potion'[^\]]*\],/s";
```

### Value Formatting

Values are formatted correctly for PHP code:

- `null` → `null`
- `true/false` → `true/false`
- Numbers → `100`
- Strings → `'escaped string'`
- Arrays/JSON → `{"heal_hp_flat":150}`

### Logging

All seeder updates are logged:

```php
Log::info("Updated seeder file for items", [
    'resource' => 'items',
    'key' => 'health_potion',
]);
```

### Error Handling

If seeder update fails:
- Error is logged but doesn't block the main update
- Database change still succeeds
- GM can manually update seeder if needed

---

## Benefits

### For Game Masters

- ✅ No need to learn Git or edit PHP files
- ✅ Changes are permanent and version-controlled
- ✅ Can run migrations and seeders without SSH access
- ✅ Confidence that changes won't be lost

### For Developers

- ✅ See GM changes in Git history
- ✅ Can review seeder changes in pull requests
- ✅ Database and seeders stay in sync
- ✅ `db:seed` works correctly in all environments

### For DevOps

- ✅ Reduced support requests ("my changes disappeared!")
- ✅ Audit trail of all content modifications
- ✅ Consistent deployment process

---

## Extending Auto-Sync to Other Content Types

To add auto-sync for other content types (e.g., zones, quests):

1. **Update `SeederSyncService::SEEDER_MAP`**:
   ```php
   'zones' => [
       'path' => 'database/seeders/ZoneSeeder.php',
       'model' => Zone::class,
       'array_name' => '$zones',
   ],
   ```

2. **Ensure seeder uses proper format**:
   - Must have a `'key'` field as the unique identifier
   - Must use array format (not object instantiation)
   - Must follow the pattern: `['key' => 'zone_key', ...]`

3. **Test the sync**:
   - Edit the content via GM Console
   - Verify seeder file is updated
   - Run `php artisan db:seed` to confirm persistence

---

## Troubleshooting

### Seeder Not Updating

**Symptom**: GM edits content but seeder file doesn't change

**Possible Causes**:
1. Content type not in `SEEDER_MAP` (only items/monsters supported currently)
2. Seeder file not found or not writable
3. Entry doesn't have a `'key'` field
4. Seeder uses different format (e.g., object instantiation)

**Solution**: Check logs for error messages from `SeederSyncService`

### Pattern Match Failed

**Symptom**: Log shows "Could not find entry in seeder for key: X"

**Causes**:
- Key doesn't exist in seeder yet (new item)
- Seeder format doesn't match expected pattern
- Special characters in key not properly escaped

**Solution**: Add the item manually to the seeder first, then edit via GM Console

### File Permission Issues

**Symptom**: "Failed to update seeder file" error

**Cause**: Web server doesn't have write permissions to seeder files

**Solution**: Ensure proper file permissions:
```bash
chmod 664 database/seeders/*.php
```

---

## Future Enhancements

Potential improvements:

1. **Support for all content types**: Extend auto-sync to zones, quests, skills, pets, etc.
2. **Diff preview**: Show GM what will change in seeder before saving
3. **Rollback feature**: Undo seeder changes with one click
4. **Batch operations**: Update multiple items and sync all at once
5. **Migration generation**: Auto-create migrations for schema changes

---

## Related Documentation

- [DATABASE_SETUP.md](DATABASE_SETUP.md) - Complete database setup guide
- [DATABASE_QUICK_REFERENCE.md](DATABASE_QUICK_REFERENCE.md) - Quick commands reference
- [CONTRIBUTING.md](CONTRIBUTING.md) - Contributing guidelines

---

**Last Updated**: 2026-08-02  
**Feature Version**: 1.0  
**Status**: ✅ Production Ready
