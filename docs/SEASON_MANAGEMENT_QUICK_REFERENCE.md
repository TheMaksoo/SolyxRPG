# Quick Reference: Season Management

## Important: Initial Setup Required

**Before seasons can work, you MUST run the database seeders:**

```bash
php artisan db:seed --force
```

This initializes:
- Season 1 in the leaderboard_seasons table
- PvP season number in game_config table
- All other required game data

## Fix Current Season Issues (Run Once)

```bash
# Step 1: Distribute leaderboard rewards from last season
php artisan leaderboard:distribute-rewards --force

# Step 2: Reset battle pass and distribute unclaimed rewards
php artisan battlepass:season-rollover --force
```

## What Gets Distributed

### Leaderboard Rewards
- **Gold & Gems**: Top 100 in PvP Trophies (rank #1 gets 20k gold + 500 gems)
- **Titles**: Champion/Top 10 titles for all 17 categories (Power, Level, Trophies, etc.)
- **Banners**: Season-specific banners for top 100 in each category
- **Hall of Fame**: Top 10 Trophies players archived

### Battle Pass Rewards
- **All unclaimed rewards**: Gold, gems, items, cosmetics from reached tiers
- **Reset**: Tier → 0, XP → 0, Premium → false
- **Season mail**: Summary notification to all participants

## Automatic Scheduling (Already Configured)

All season commands run automatically on the 1st of each month at midnight (Europe/Amsterdam timezone):

```
1st of month 00:00 → PvP Season Reset ✅
1st of month 00:00 → Battle Pass Season Rollover ✅
Daily at     00:00 → Leaderboard Season Rollover (checks if season ended) ✅
```

**⚠️ IMPORTANT**: The `leaderboard:season-rollover` command checks if the current season's `ends_at` date has passed. If the season is still active, it does nothing. This is by design - it runs daily but only acts when a season has ended.

**No manual intervention needed for future seasons!**

## Manual Commands (For Testing or Emergency)

```bash
# Battle pass rollover (with confirmation prompt)
php artisan battlepass:season-rollover

# Battle pass rollover (skip confirmation)
php artisan battlepass:season-rollover --force

# Leaderboard rewards (specific season)
php artisan leaderboard:distribute-rewards 1

# Leaderboard rewards (most recent season)
php artisan leaderboard:distribute-rewards

# PvP season reset (grants titles and soft-resets ratings)
php artisan pvp:season-reset
```

## Testing Seasons Manually

If you want to test season rollover without waiting for the end of the month:

```bash
# 1. Manually update the current season's end date to the past
# Using Tinker:
php artisan tinker
>>> $season = App\Models\LeaderboardSeason::where('is_active', true)->first();
>>> $season->ends_at = now()->subDay();
>>> $season->save();
>>> exit

# 2. Now run the rollover command
php artisan leaderboard:season-rollover

# 3. This will create Season 2 and distribute all rewards
```

## Monthly Admin Checklist

1. ✅ Commands run automatically on the 1st at 00:00
2. ⚠️  **Manual step**: Update `BattlePassService::SEASON` constant to new season name
3. ✅ Players receive mail notifications automatically
4. ✅ Hall of Fame updates automatically

## Data Preservation

All season data is preserved in the database:
- Battle passes table keeps all season records
- Leaderboard seasons table tracks all seasons
- Hall of Fame archives top players from each season
- Can query any past season for analytics

## Troubleshooting

**"Nothing happened when I ran the commands":**
- Did you run `php artisan db:seed --force` first? The seeders must run to initialize Season 1
- For leaderboard:season-rollover, is the current season's `ends_at` date in the past? If not, the command exits early by design
- Check if Season 1 exists: `php artisan tinker` → `App\Models\LeaderboardSeason::all()`

**If automatic rollover fails:**
- Check Laravel scheduler is running: `php artisan schedule:work`
- Check logs: `storage/logs/laravel.log`
- Run manual commands with `--force` flag
- Verify database connectivity

**If rewards seem wrong:**
- Leaderboard rewards based on rankings at time of command execution
- Battle pass rewards based on tier reached + premium status
- Commands are idempotent - safe to run multiple times (checks for duplicates)

**"Everything is still Season 1":**
- The leaderboard:season-rollover command only acts when the current season has ended (ends_at is in the past)
- If you want to force a rollover, update the season's ends_at date manually (see "Testing Seasons Manually" above)
- Alternatively, use `leaderboard:distribute-rewards` to grant rewards without rolling over the season
