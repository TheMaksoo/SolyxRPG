# Quick Reference: Season Management

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

Both systems run automatically every month:

```
1st of month 00:05 → PvP Season Reset
1st of month 00:15 → Battle Pass Season Rollover ✅
Daily at     00:20 → Leaderboard Season Rollover (checks if season ended) ✅
```

**No manual intervention needed for future seasons!**

## Manual Commands (For Testing or Emergency)

```bash
# Battle pass rollover
php artisan battlepass:season-rollover

# Leaderboard rewards (specific season)
php artisan leaderboard:distribute-rewards 1

# Leaderboard rewards (most recent season)
php artisan leaderboard:distribute-rewards
```

## Monthly Admin Checklist

1. ✅ Commands run automatically on the 1st at 00:15 and 00:20
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

**If automatic rollover fails:**
- Check Laravel scheduler is running: `php artisan schedule:work`
- Check logs: `storage/logs/laravel.log`
- Run manual commands with `--force` flag
- Verify database connectivity

**If rewards seem wrong:**
- Leaderboard rewards based on rankings at time of command execution
- Battle pass rewards based on tier reached + premium status
- Commands are idempotent - safe to run multiple times (checks for duplicates)
