# Battle Pass Season Rollover Command

This document describes the command for managing battle pass seasons.

## Problem Statement

The battle pass didn't properly roll over at the end of the season:
1. Users kept their premium status from last season (without purchasing it again)
2. End-of-season rewards were never distributed to players
3. Progress and tiers weren't reset for the new season

## Solution

One comprehensive command that handles everything:

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

## Usage

### To fix the current situation:

```bash
# Run once to fix everything from last season
php artisan battlepass:season-rollover --force
```

### For future seasons:

```bash
# Run at the end of each season (monthly)
php artisan battlepass:season-rollover

# Then update BattlePassService::SEASON constant to the next season name in code
```

---

## Automated Scheduling (Recommended)

To automatically run the rollover at the start of each month, add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Run on the 1st of each month at 00:01 AM
    $schedule->command('battlepass:season-rollover --force')
        ->monthlyOn(1, '00:01');
}
```

**Don't forget**: You still need to manually update `BattlePassService::SEASON` constant to the new season name each month.

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
