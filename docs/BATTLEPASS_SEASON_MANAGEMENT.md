# Season Management Commands

This document describes the commands for managing battle pass and leaderboard seasons.

## Problem Statement

Season rollovers didn't work properly:

**Battle Pass:**
1. Users kept their premium status from last season (without purchasing it again)
2. End-of-season rewards were never distributed to players
3. Progress and tiers weren't reset for the new season

**Leaderboards:**
1. Gold, gems, titles, and banners weren't granted based on final rankings
2. Hall of Fame wasn't updated with top 10 players
3. Season rewards didn't go out to players

## Solution

Two comprehensive commands that handle everything:

---

## 1. Battle Pass Season Rollover

### Battle Pass Season Rollover

```bash
php artisan battlepass:season-rollover [--force]
```

**Purpose**: Handles the complete end-of-season process - distributes all unclaimed rewards, resets progress, and clears premium status for the next season.

**What it does** (in a database transaction):
1. ✅ Distributes all unclaimed rewards to players (gold, gems, items, cosmetics)
2. ✅ Sends season-end summary mail to all participants
3. ✅ Resets all battle pass progress:
   - tier → 0
   - xp → 0
   - premium → false (everyone needs to buy again)
   - claimed_free_tiers → null
   - claimed_premium_tiers → null
4. ✅ Reminds admin to update the season constant

**When to use**: 
- **NOW**: Run once to fix the current season issue (distribute last season's rewards + reset for new season)
- **FUTURE**: Run at the end of each season (monthly) before the new month starts

**Example output**:
```
Current battle pass season: 'ashfall'
This will:
  1. Distribute all unclaimed rewards to players
  2. Reset all battle pass progress (tier, XP, claimed tiers)
  3. Reset premium status to false for all players
  4. Send summary mail notifications
Proceed with season rollover? (yes/no) [no]:
> yes
Step 1/4: Distributing unclaimed rewards...
  ✓ Distributed rewards to 45 character(s)
Step 2/4: Sending season-end summaries...
  ✓ Sent summaries to 120 character(s)
Step 3/4: Resetting battle pass progress...
  ✓ Reset 120 battle pass(es)
Step 4/4: Season rollover complete!
⚠️  IMPORTANT: Update BattlePassService::SEASON constant to the next season name.
✅ Season rollover completed successfully!
```

---

## 2. Leaderboard Rewards Distribution

```bash
php artisan leaderboard:distribute-rewards [season_number] [--force]
```

**Purpose**: Retroactively distributes leaderboard season-end rewards (gold, gems, titles, banners) for a specific season when the automatic rollover failed.

**Parameters**:
- `season_number` (optional): The season to process. If omitted, uses the most recent completed season

**What it does**:
1. ✅ Grants gold and gems based on PvP Trophies ranking (top 100)
2. ✅ Grants Champion/Top 10 titles for every leaderboard category
3. ✅ Grants season banners for all categories (top 100 in each)
4. ✅ Archives top 10 to Hall of Fame
5. ✅ Sends mail notifications to all rewarded players

**Reward tiers (Trophies leaderboard)**:
- Rank #1: 20,000 gold, 500 gems, Season Champion title
- Ranks #2-3: 8,000 gold, 200 gems
- Ranks #4-10: 4,000 gold, 100 gems, Top 10 banner
- Ranks #11-100: 1,200 gold, 25 gems

**When to use**: 
- **NOW**: Run once to distribute rewards from the last season that didn't run
- **FUTURE**: The leaderboard rollover runs automatically daily, but this command can be used if it fails

**Example output**:
```
Processing season 1 rewards...
Season: Season 1
Dates: 2026-07-01 to 2026-07-31

Reward distribution plan:
  • Trophies rewards (gold/gems): Top 42
  • Category titles: Top 10 in each of 17 categories
  • Season banners: Top 100 in each category
  
Proceed with reward distribution? (yes/no) [no]:
> yes
Step 1/3: Granting Trophies rewards (gold, gems, cosmetics)...
  ✓ Granted rewards to 42 character(s)
Step 2/3: Granting category titles...
  ✓ Granted 153 category title(s)
Step 3/3: Granting season banners...
  ✓ Granted 1,247 season banner(s)

✅ Successfully distributed season 1 rewards!
```

---

## Usage

### To fix the current situation:

```bash
# Step 1: Distribute leaderboard rewards from last season
php artisan leaderboard:distribute-rewards --force

# Step 2: Run battle pass season rollover
php artisan battlepass:season-rollover --force
```

### For future seasons:

**✅ Already automated!** Both commands run automatically via Laravel's scheduler:
- **Battle Pass rollover**: 1st of each month at 00:15 AM
- **Leaderboard rollover**: Daily at 00:20 AM (checks if season ended)

**Don't forget**: You still need to manually update `BattlePassService::SEASON` constant to the new season name each month after the rollover completes.

---

## Automated Scheduling (Already Configured)

Both rollovers are already scheduled to run automatically:

```php
// In routes/console.php
Schedule::command('pvp:season-reset')->monthlyOn(1, '00:05');
Schedule::command('battlepass:season-rollover --force')->monthlyOn(1, '00:15');
Schedule::command('leaderboard:season-rollover')->dailyAt('00:20');
```

**Execution order**:
1. **00:05**: PvP season reset (soft-resets ratings)
2. **00:15**: Battle Pass rollover (distributes rewards, resets progress)
3. **00:20**: Leaderboard rollover (distributes rewards if season ended, starts new season)

**Manual run**: If you need to trigger manually (e.g., for testing or if automatic run failed):
```bash
php artisan battlepass:season-rollover
php artisan leaderboard:distribute-rewards
```

---

## Data Preservation

**Q: Do we still have last season's stats and positions?**

**A: Yes!** All battle pass data is preserved in the database:
- The `battle_passes` table stores all seasons with a `season` column
- Each season's final stats (tier reached, xp earned, premium status) are preserved
- You can query any past season: `BattlePass::where('season', 'previous_season_name')->get()`
- The rollover command resets the values but doesn't delete the records

To see what seasons exist:
```bash
php artisan tinker --execute="echo App\Models\BattlePass::distinct('season')->pluck('season')->implode(', ');"
```

---

## Safety Features

The command includes:
- **Confirmation prompt** (unless `--force` is used)
- **Detailed summary** of what will happen before execution
- **Database transaction** - all changes are rolled back if anything fails
- **Progress indicators** for long operations
- **In-game mail notifications** to all affected players
- **Safe to run multiple times** - won't double-distribute rewards already claimed

---

## What Happens to Premium Users

When the rollover runs:
- ✅ They receive all their unclaimed premium rewards from the season
- ✅ Their premium status is set to `false`
- ✅ They will need to purchase premium again for the new season
- ✅ This is intentional - premium is per-season, not permanent
