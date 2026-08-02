# Database Setup and Seeding Guide

This guide explains how to use Laravel's database migrations and seeders for SolyxRPG, with a focus on making **idempotent, safe-to-rerun seeders** that won't reset manual changes.

## Table of Contents

- [Quick Start](#quick-start)
- [Understanding Migrations](#understanding-migrations)
- [Understanding Seeders](#understanding-seeders)
- [Idempotent Seeding Pattern](#idempotent-seeding-pattern)
- [Making Content/Economy Changes](#making-contenteconomy-changes)
- [Common Workflows](#common-workflows)
- [Troubleshooting](#troubleshooting)

---

## Quick Start

### Initial Setup (Fresh Database)

```bash
# 1. Copy environment file
cp .env.example .env

# 2. Configure database credentials in .env
# DB_DATABASE=solyxrpg
# DB_USERNAME=root
# DB_PASSWORD=your_password

# 3. Generate application key
php artisan key:generate

# 4. Run migrations to create tables
php artisan migrate

# 5. Seed the database with game content
php artisan db:seed
```

### Updating Existing Database

```bash
# Run any new migrations
php artisan migrate

# Re-run seeders (safe to run multiple times!)
php artisan db:seed
```

---

## Understanding Migrations

**Migrations** are like version control for your database schema. They define the structure of tables, columns, indexes, and relationships.

### Location

- **Path**: `database/migrations/`
- **Naming**: `YYYY_MM_DD_HHMMSS_description.php`
- **Examples**: 
  - `2026_07_18_000002_create_social_accounts_table.php`
  - `2026_08_02_000025_add_sync_budget_to_characters_table.php`

### Common Commands

```bash
# Run all pending migrations
php artisan migrate

# Rollback the last batch of migrations
php artisan migrate:rollback

# Reset all migrations (⚠️ DESTRUCTIVE - drops all tables)
php artisan migrate:reset

# Reset and re-run all migrations (⚠️ DESTRUCTIVE)
php artisan migrate:fresh

# Reset and re-run all migrations, then seed
php artisan migrate:fresh --seed

# Check migration status
php artisan migrate:status

# Create a new migration
php artisan make:migration create_new_table_name
```

### Creating a New Migration

```bash
# For creating a new table
php artisan make:migration create_table_name_table

# For modifying an existing table
php artisan make:migration add_column_to_table_name_table
```

Example migration file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('example_table', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->integer('value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('example_table');
    }
};
```

---

## Understanding Seeders

**Seeders** populate your database with initial or reference data. In SolyxRPG, seeders define game content like items, monsters, zones, quests, and economy settings.

### Location

- **Path**: `database/seeders/`
- **Main Seeder**: `DatabaseSeeder.php` (orchestrates all other seeders)
- **Content Seeders**:
  - `ItemSeeder.php` - Items, weapons, armor, consumables, materials
  - `MonsterSeeder.php` - Monsters and bosses
  - `ZoneSeeder.php` - Game zones/areas
  - `QuestSeeder.php` - Daily, weekly, monthly, and main quests
  - `GameConfigSeeder.php` - Economy settings (XP multipliers, drop rates, etc.)
  - `SkillSeeder.php` - Character skills
  - `PetSeeder.php` - Pet definitions
  - `RecipeSeeder.php` - Crafting recipes
  - `DungeonSeeder.php` - Dungeon definitions
  - `AchievementSeeder.php` - Achievement definitions
  - `CosmeticSeeder.php` - Cosmetic items
  - And more...

### Common Commands

```bash
# Run all seeders (defined in DatabaseSeeder)
php artisan db:seed

# Run a specific seeder
php artisan db:seed --class=ItemSeeder

# Refresh database and seed (⚠️ DESTRUCTIVE)
php artisan migrate:fresh --seed

# Create a new seeder
php artisan make:seeder ExampleSeeder
```

---

## Idempotent Seeding Pattern

**⚠️ CRITICAL**: All SolyxRPG seeders are designed to be **idempotent** — they can be run multiple times without causing errors or data loss. This is achieved using Laravel's `updateOrCreate()` or `firstOrCreate()` methods.

### Why This Matters

When you make content or economy changes:
1. You update the seeder file with new values
2. You run `php artisan db:seed` again
3. The seeder **updates** existing records instead of creating duplicates or failing
4. **Manual changes** in the database are **preserved** (unless they conflict with seeder keys)

### How It Works

#### ❌ BAD: Using `create()` (Not Idempotent)

```php
// DON'T DO THIS - Fails on second run!
Item::create(['key' => 'health_potion', 'name' => 'Health Potion', ...]);
```

This will throw a duplicate key error if the item already exists.

#### ✅ GOOD: Using `updateOrCreate()` (Idempotent)

```php
// DO THIS - Safe to run multiple times
Item::updateOrCreate(
    ['key' => 'health_potion'],  // Match condition (unique identifier)
    ['name' => 'Health Potion', 'price_gold' => 80, ...]  // Fields to create/update
);
```

This will:
- **Create** the item if it doesn't exist
- **Update** the item if it already exists (based on the `key`)
- **Preserve** any fields not mentioned (like created_at, custom columns added later)

#### ✅ ALSO GOOD: Using `firstOrCreate()` (Idempotent for No-Update Cases)

```php
// DO THIS - Creates only if doesn't exist, otherwise does nothing
User::firstOrCreate(
    ['email' => 'test@example.com'],
    ['name' => 'Test User', 'password' => Hash::make('password')]
);
```

This is used when you want to create a record once but never update it afterward.

### Real Example from ItemSeeder

```php
public function run(): void
{
    $items = [
        ['key' => 'health_potion', 'name' => 'Health Potion', 'type' => 'consumable', 
         'rarity' => 'common', 'glyph' => '🧪', 'description' => 'Restores 120 HP instantly in battle.', 
         'stat_json' => ['heal_hp_flat' => 120], 'price_gold' => 80, 'price_gems' => null],
        // ... more items
    ];

    foreach ($items as $item) {
        Item::updateOrCreate(['key' => $item['key']], $item);
    }
}
```

**Key Points**:
- `key` is the unique identifier that determines if an item exists
- Running this seeder multiple times updates items if their definitions change
- You can safely edit item stats, prices, descriptions, etc. in the seeder and re-run it

---

## Making Content/Economy Changes

When you need to change game content or economy settings, follow this workflow:

### Scenario 1: Updating Item Stats

**Example**: You want to increase Health Potion healing from 120 HP to 150 HP.

1. **Edit the Seeder**:
   ```php
   // database/seeders/ItemSeeder.php
   ['key' => 'health_potion', 'name' => 'Health Potion', 'type' => 'consumable', 
    'rarity' => 'common', 'glyph' => '🧪', 
    'description' => 'Restores 150 HP instantly in battle.',  // ← Updated description
    'stat_json' => ['heal_hp_flat' => 150],  // ← Changed from 120 to 150
    'price_gold' => 80, 'price_gems' => null],
   ```

2. **Run the Seeder**:
   ```bash
   php artisan db:seed --class=ItemSeeder
   # Or run all seeders:
   php artisan db:seed
   ```

3. **Verify**:
   - Check your database or game UI to confirm the change
   - The item is updated, not duplicated

### Scenario 2: Adding a New Item

1. **Edit the Seeder**:
   ```php
   // database/seeders/ItemSeeder.php
   // Add to the $items array:
   ['key' => 'mega_health_potion', 'name' => 'Mega Health Potion', 
    'type' => 'consumable', 'rarity' => 'epic', 'glyph' => '🧪', 
    'description' => 'Restores 500 HP instantly in battle.', 
    'stat_json' => ['heal_hp_flat' => 500], 'price_gold' => 500, 'price_gems' => null],
   ```

2. **Run the Seeder**:
   ```bash
   php artisan db:seed --class=ItemSeeder
   ```

3. **The new item is created; existing items remain unchanged**

### Scenario 3: Changing Economy Settings

**Example**: You want to increase the XP multiplier from 1.0 to 1.5.

1. **Edit the GameConfigSeeder**:
   ```php
   // database/seeders/GameConfigSeeder.php
   'xp_mult' => '1.5',  // ← Changed from '1'
   ```

2. **Run the Seeder**:
   ```bash
   php artisan db:seed --class=GameConfigSeeder
   ```

3. **The config value is updated** in the `game_configs` table

### Scenario 4: Structural Database Changes

If you need to add a **new column** or change the table structure:

1. **Create a Migration** (not a seeder!):
   ```bash
   php artisan make:migration add_durability_to_items_table
   ```

2. **Edit the Migration**:
   ```php
   public function up(): void
   {
       Schema::table('items', function (Blueprint $table) {
           $table->integer('durability')->default(100)->after('price_gems');
       });
   }

   public function down(): void
   {
       Schema::table('items', function (Blueprint $table) {
           $table->dropColumn('durability');
       });
   }
   ```

3. **Run the Migration**:
   ```bash
   php artisan migrate
   ```

4. **Update the Seeder** (optional, if you want to set default durability):
   ```php
   ['key' => 'health_potion', ..., 'durability' => 100],
   ```

5. **Run the Seeder**:
   ```bash
   php artisan db:seed --class=ItemSeeder
   ```

---

## Common Workflows

### Workflow 1: Setting Up a New Developer Environment

```bash
# 1. Clone the repository
git clone https://github.com/TheMaksoo/SolyxRPG.git
cd SolyxRPG

# 2. Install PHP dependencies
composer install

# 3. Install Node dependencies
npm install

# 4. Copy and configure environment
cp .env.example .env
# Edit .env with your database credentials

# 5. Generate application key
php artisan key:generate

# 6. Create database (if not exists)
# mysql -u root -p -e "CREATE DATABASE solyxrpg;"

# 7. Run migrations
php artisan migrate

# 8. Seed the database
php artisan db:seed

# 9. Build frontend assets
npm run dev
```

### Workflow 2: Pulling Updates from Git

```bash
# 1. Pull latest code
git pull origin main

# 2. Update PHP dependencies
composer install

# 3. Update Node dependencies
npm install

# 4. Run new migrations
php artisan migrate

# 5. Re-run seeders (updates content)
php artisan db:seed

# 6. Rebuild frontend
npm run build
```

### Workflow 3: Making and Testing Content Changes

```bash
# 1. Edit seeder files (e.g., ItemSeeder.php)
# Update item stats, add new items, etc.

# 2. Re-run seeders
php artisan db:seed --class=ItemSeeder

# 3. Test in the application
# Verify changes in the UI or database

# 4. Commit changes
git add database/seeders/ItemSeeder.php
git commit -m "feat: increase health potion healing to 150 HP"
git push
```

### Workflow 4: Resetting Database (Development Only)

```bash
# ⚠️ WARNING: This deletes ALL data!

# Option 1: Fresh migration + seed
php artisan migrate:fresh --seed

# Option 2: Drop all tables, migrate, and seed
php artisan db:wipe
php artisan migrate
php artisan db:seed
```

---

## Troubleshooting

### Issue: "Base table or view not found"

**Cause**: Tables don't exist yet.

**Solution**:
```bash
php artisan migrate
```

### Issue: "Duplicate entry for key"

**Cause**: Using `create()` instead of `updateOrCreate()` in a seeder.

**Solution**: Update the seeder to use `updateOrCreate()`:
```php
// Change this:
Item::create(['key' => 'health_potion', ...]);

// To this:
Item::updateOrCreate(['key' => 'health_potion'], ['key' => 'health_potion', ...]);
```

### Issue: "Class 'ItemSeeder' not found"

**Cause**: Seeder class doesn't exist or isn't autoloaded.

**Solution**:
```bash
# Regenerate autoload files
composer dump-autoload

# Create the seeder if it doesn't exist
php artisan make:seeder ItemSeeder
```

### Issue: "Nothing to migrate"

**Cause**: All migrations have already run.

**Solution**: This is normal! If you need to add new migrations:
```bash
php artisan make:migration your_new_migration_name
```

### Issue: Seeder Changes Not Reflecting in Database

**Cause**: Forgot to re-run the seeder after editing.

**Solution**:
```bash
# Re-run all seeders
php artisan db:seed

# Or run a specific seeder
php artisan db:seed --class=ItemSeeder
```

### Issue: "SQLSTATE[42S02]: Base table or view already exists"

**Cause**: Trying to run `migrate:fresh` or duplicate migration.

**Solution**:
```bash
# Check migration status
php artisan migrate:status

# If you want to start fresh (⚠️ deletes data):
php artisan migrate:fresh --seed
```

---

## Best Practices

### DO ✅

- **Always use `updateOrCreate()` or `firstOrCreate()` in seeders** to make them idempotent
- **Use migrations for schema changes** (adding columns, tables, indexes)
- **Use seeders for data/content changes** (items, monsters, config values)
- **Commit both the migration AND updated seeder** when making structural changes
- **Test seeders by running them twice** to ensure they don't fail or duplicate data
- **Use descriptive migration names**: `add_durability_to_items_table`
- **Document significant changes** in commit messages

### DON'T ❌

- **Don't use `create()` or `insert()` in seeders** unless you're certain it will only run once
- **Don't edit migrations after they've been committed** to version control
- **Don't put schema changes in seeders** (use migrations instead)
- **Don't manually edit data in production** without updating the corresponding seeder
- **Don't forget to run `php artisan migrate` after pulling** new code
- **Don't run `migrate:fresh` in production** (it deletes all data!)

---

## GM Console Integration

SolyxRPG includes a **Game Master Console** that provides a no-code UI for managing game content. When GMs are granted access, they can:

### Running Migrations and Seeders

The GM Console includes buttons to run migrations and seeders directly from the web interface:

1. Navigate to **Admin → GM Console → Commands** tab
2. Select "Run Migrations" or "Run Seeders"
3. Click "Execute" to run the command

This provides the same functionality as running `php artisan migrate` or `php artisan db:seed` from the command line, but accessible to GMs who may not have direct server access.

### Auto-Updating Seeders

**✨ NEW FEATURE**: When a GM edits items or monsters through the GM Console Content Editor, the corresponding seeder file is **automatically updated**!

**How it works:**

1. GM navigates to **GM Console → Content → Items**
2. GM edits an item (e.g., changes Health Potion from 120 HP to 150 HP)
3. GM saves the changes
4. **Behind the scenes**: The database is updated AND `ItemSeeder.php` is automatically modified
5. Next time anyone runs `php artisan db:seed`, the change persists!

**Supported resources:**
- ✅ **Items** → Auto-updates `ItemSeeder.php`
- ✅ **Monsters** → Auto-updates `MonsterSeeder.php`
- ❌ Other resources (zones, quests, etc.) → Edit seeders manually

**Benefits:**

- **No lost changes**: GM edits won't be reverted when seeders run
- **Version control**: Seeder files stay in sync with database
- **Team coordination**: Developers can see GM changes in Git commits

**Example workflow:**

```
1. GM edits "Shadow Dragon" HP from 5000 to 6000 in GM Console
2. System updates the database immediately
3. System also updates MonsterSeeder.php automatically
4. Developer pulls the code and sees the change in Git
5. Developer runs `php artisan db:seed` → Change is preserved!
```

---

## Key Files Reference

| File | Purpose | When to Edit |
|------|---------|--------------|
| `database/migrations/*.php` | Define database schema | Adding/changing table structure |
| `database/seeders/DatabaseSeeder.php` | Orchestrates all seeders | Adding new seeder classes |
| `database/seeders/ItemSeeder.php` | Define items, weapons, armor | Changing item stats or adding new items |
| `database/seeders/MonsterSeeder.php` | Define monsters and bosses | Changing monster stats or adding new monsters |
| `database/seeders/GameConfigSeeder.php` | Economy/gameplay settings | Changing XP rates, drop rates, etc. |
| `database/seeders/ZoneSeeder.php` | Define game zones | Adding/changing zones |
| `database/seeders/QuestSeeder.php` | Define quests | Adding/changing quests and rewards |

---

## Summary

- **Migrations** = Table structure (schema)
- **Seeders** = Data/content (items, monsters, settings)
- **Always use `updateOrCreate()`** to make seeders safe to re-run
- **When making content changes**: Edit seeder → Run `php artisan db:seed` → Commit
- **When adding columns/tables**: Create migration → Run `php artisan migrate` → Update seeder → Commit both

With this approach, you can safely run `php artisan db:seed` as many times as needed without fear of data loss or duplication! 🎉
