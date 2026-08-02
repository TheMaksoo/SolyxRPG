# Contributing to SolyxRPG

Thank you for your interest in contributing to SolyxRPG! This guide will help you understand our development workflow and best practices.

## Getting Started

1. **Fork and clone** the repository
2. **Follow the setup guide** in [README.md](../README.md)
3. **Read the database guide** in [DATABASE_SETUP.md](DATABASE_SETUP.md)

## Development Workflow

### Making Content/Economy Changes

When you want to change game content (items, monsters, quests, economy settings), follow this workflow:

#### ✅ CORRECT Workflow

1. **Edit the appropriate seeder file** in `database/seeders/`
2. **Run the seeder** to apply changes: `php artisan db:seed --class=YourSeeder`
3. **Test the changes** in the application
4. **Commit BOTH the seeder file and any related migration** if schema changed
5. **Create a pull request**

#### ❌ INCORRECT Workflow

- ❌ Don't manually edit data in the database without updating the seeder
- ❌ Don't create a migration for data changes (use seeders instead)
- ❌ Don't use SQL files or manual SQL scripts for content updates

### Example: Changing Item Stats

**Scenario**: You want to increase Health Potion healing from 120 HP to 150 HP.

```php
// database/seeders/ItemSeeder.php

// Before:
['key' => 'health_potion', 'name' => 'Health Potion', 'type' => 'consumable', 
 'description' => 'Restores 120 HP instantly in battle.',
 'stat_json' => ['heal_hp_flat' => 120], 'price_gold' => 80],

// After:
['key' => 'health_potion', 'name' => 'Health Potion', 'type' => 'consumable', 
 'description' => 'Restores 150 HP instantly in battle.',
 'stat_json' => ['heal_hp_flat' => 150], 'price_gold' => 80],
```

Then run:
```bash
php artisan db:seed --class=ItemSeeder
```

**Git commit message**:
```
feat: increase health potion healing to 150 HP

Updates ItemSeeder to improve early-game healing balance.
```

### Example: Adding a New Monster

```php
// database/seeders/MonsterSeeder.php

// Add to the $monsters array:
['key' => 'shadow_beast', 'name' => 'Shadow Beast', 'glyph' => '🌑', 
 'hp' => 250, 'atk' => 35, 'gold' => 85, 'xp' => 120, 'gems' => 0, 
 'is_boss' => false, 'is_elite' => false, 
 'zone_id' => $zoneId('dark_forest'), 'min_level' => 12, 'enabled' => true, 
 'skills_json' => self::normalKit('Shadow Strike', 1.4)],
```

Run:
```bash
php artisan db:seed --class=MonsterSeeder
```

**Git commit message**:
```
feat: add Shadow Beast to Dark Forest zone

Adds mid-level monster to fill level 12 gap in Dark Forest.
```

### Making Schema Changes

If you need to add a new column or change table structure:

1. **Create a migration**:
   ```bash
   php artisan make:migration add_durability_to_items_table
   ```

2. **Edit the migration** (`database/migrations/YYYY_MM_DD_HHMMSS_add_durability_to_items_table.php`):
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

3. **Run the migration**:
   ```bash
   php artisan migrate
   ```

4. **Update the seeder** if needed (to populate the new column):
   ```php
   // database/seeders/ItemSeeder.php
   ['key' => 'health_potion', ..., 'durability' => 100],
   ```

5. **Run the seeder**:
   ```bash
   php artisan db:seed --class=ItemSeeder
   ```

6. **Commit both files**:
   ```bash
   git add database/migrations/*_add_durability_to_items_table.php
   git add database/seeders/ItemSeeder.php
   git commit -m "feat: add durability system to items"
   ```

## Critical Rules

### 🚨 MUST DO

1. **Always use `updateOrCreate()` in seeders**, never `create()` or `insert()`
2. **Always commit both migration AND seeder** when making structural changes
3. **Always run seeders after editing** to test they work correctly
4. **Always test by running the seeder twice** to ensure it's idempotent (no errors on second run)

### 🚨 NEVER DO

1. **Never use `create()` or `insert()` in seeders** (they're not idempotent!)
2. **Never manually edit production data** without updating the corresponding seeder
3. **Never edit migrations after they've been committed** (create a new migration instead)
4. **Never put data changes in migrations** (use seeders for data, migrations for schema)

## Seeder Best Practices

### Pattern: Idempotent Seeding

All seeders must be idempotent (safe to run multiple times). Use this pattern:

```php
public function run(): void
{
    $items = [
        ['key' => 'unique_key_1', 'name' => 'Item 1', ...],
        ['key' => 'unique_key_2', 'name' => 'Item 2', ...],
    ];

    foreach ($items as $item) {
        // ✅ This creates OR updates based on the key
        Model::updateOrCreate(
            ['key' => $item['key']],  // Match condition
            $item                      // Data to create/update
        );
    }
}
```

### Seeder File Reference

| Seeder | Purpose | Common Changes |
|--------|---------|----------------|
| `ItemSeeder.php` | Items, weapons, armor, consumables | Item stats, prices, new items |
| `MonsterSeeder.php` | Monsters and bosses | Monster stats, HP, damage |
| `GameConfigSeeder.php` | Economy settings | XP multipliers, drop rates |
| `ZoneSeeder.php` | Game zones | Zone requirements, new areas |
| `QuestSeeder.php` | Quests | Quest rewards, requirements |
| `SkillSeeder.php` | Character skills | Skill damage, cooldowns |
| `PetSeeder.php` | Pet definitions | Pet stats, abilities |
| `RecipeSeeder.php` | Crafting recipes | Recipe costs, outputs |
| `DungeonSeeder.php` | Dungeon definitions | Dungeon requirements, rewards |
| `AchievementSeeder.php` | Achievements | Achievement requirements, rewards |

## Commit Message Format

Follow conventional commits:

```
<type>(<scope>): <short summary>

<optional body>
```

**Types**:
- `feat`: New feature or content
- `fix`: Bug fix
- `docs`: Documentation changes
- `refactor`: Code refactoring
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

**Examples**:
```
feat(items): add legendary sword with lifesteal
fix(monsters): correct Shadow Dragon HP calculation
docs: update database seeding guide
refactor(quests): simplify daily quest logic
```

## Pull Request Guidelines

1. **Title**: Use conventional commit format
2. **Description**: Explain what changed and why
3. **Testing**: Describe how you tested the changes
4. **Screenshots**: Include for UI changes

**PR Template**:
```markdown
## What Changed
- Added new legendary weapon "Shadowbane"
- Increased Health Potion healing from 120 to 150 HP

## Why
- Players requested more powerful weapons at level 35
- Early game healing was too weak

## How to Test
1. Run migrations: `php artisan migrate`
2. Run seeders: `php artisan db:seed`
3. Check the shop for new weapon
4. Test Health Potion healing in combat

## Seeder Changes
- Updated `ItemSeeder.php` with new weapon and potion stats
- No migration needed (using existing item schema)
```

## Testing Your Changes

### Manual Testing Checklist

- [ ] Run `php artisan migrate` (for schema changes)
- [ ] Run `php artisan db:seed` (for content changes)
- [ ] **Run `php artisan db:seed` a second time** to verify idempotency
- [ ] Test the feature in the application
- [ ] Check for console errors
- [ ] Verify no duplicate records were created

### Automated Testing

Run existing tests before submitting:
```bash
php artisan test
```

## Common Mistakes to Avoid

### Mistake #1: Using `create()` Instead of `updateOrCreate()`

❌ **Wrong**:
```php
Item::create(['key' => 'health_potion', ...]);
```

✅ **Correct**:
```php
Item::updateOrCreate(['key' => 'health_potion'], ['key' => 'health_potion', ...]);
```

### Mistake #2: Forgetting to Re-run Seeder After Editing

❌ **Wrong**:
1. Edit `ItemSeeder.php`
2. Commit changes
3. Don't test!

✅ **Correct**:
1. Edit `ItemSeeder.php`
2. Run `php artisan db:seed --class=ItemSeeder`
3. Test the changes
4. Commit

### Mistake #3: Using Migrations for Data Changes

❌ **Wrong**:
```php
// In a migration file:
public function up() {
    DB::table('items')->insert([
        ['key' => 'new_item', 'name' => 'New Item']
    ]);
}
```

✅ **Correct**:
```php
// In ItemSeeder.php:
['key' => 'new_item', 'name' => 'New Item', ...],
```

## Questions?

- Read the [Database Setup Guide](DATABASE_SETUP.md)
- Check existing seeders for examples
- Open an issue for clarification

## Code of Conduct

- Be respectful and constructive
- Help others learn
- Focus on improving the game
- Test your changes thoroughly

Thank you for contributing! 🎉
