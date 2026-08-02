# Database Quick Reference

Quick reference for the most common database operations in SolyxRPG.

## 🚀 Quick Commands

```bash
# Initial setup
php artisan migrate          # Create database tables
php artisan db:seed          # Populate with game content

# After code changes
php artisan migrate          # Run new migrations
php artisan db:seed          # Update game content

# Validate seeders
php validate-seeders.php     # Check all seeders are idempotent
```

## 📝 Common Tasks

### Changing Item Stats

1. Edit `database/seeders/ItemSeeder.php`
2. Update the item in the `$items` array
3. Run: `php artisan db:seed --class=ItemSeeder`

### Adding a New Monster

1. Edit `database/seeders/MonsterSeeder.php`
2. Add monster to the `$monsters` array
3. Run: `php artisan db:seed --class=MonsterSeeder`

### Changing Economy Settings

1. Edit `database/seeders/GameConfigSeeder.php`
2. Update values in `$config` array
3. Run: `php artisan db:seed --class=GameConfigSeeder`

### Adding a Database Column

1. Create migration: `php artisan make:migration add_column_to_table`
2. Edit migration file in `database/migrations/`
3. Run: `php artisan migrate`
4. Update related seeder if needed
5. Run: `php artisan db:seed --class=YourSeeder`

## 🎯 Key Principles

✅ **DO**
- Use `updateOrCreate()` in seeders
- Run `php artisan db:seed` after editing seeders
- Commit both migration AND seeder files
- Test by running seeder twice

❌ **DON'T**
- Use `create()` or `insert()` in seeders
- Edit migrations after committing
- Put data changes in migrations
- Manually edit production data

## 📚 Full Documentation

- [DATABASE_SETUP.md](DATABASE_SETUP.md) - Complete database guide
- [CONTRIBUTING.md](CONTRIBUTING.md) - Contributing guidelines
- [README.md](../README.md) - Project setup

## 🛠️ Seeder Files

| File | Content |
|------|---------|
| `ItemSeeder.php` | Items, weapons, armor, consumables |
| `MonsterSeeder.php` | Monsters and bosses |
| `GameConfigSeeder.php` | Economy settings |
| `ZoneSeeder.php` | Game zones |
| `QuestSeeder.php` | Quests and rewards |
| `SkillSeeder.php` | Character skills |
| `PetSeeder.php` | Pet definitions |
| `RecipeSeeder.php` | Crafting recipes |

## 🔍 Troubleshooting

**"Base table or view not found"**
→ Run `php artisan migrate`

**"Duplicate entry"**
→ Seeder uses `create()` instead of `updateOrCreate()`

**Changes not showing**
→ Did you run `php artisan db:seed`?

**"Nothing to migrate"**
→ Normal! All migrations are already run
