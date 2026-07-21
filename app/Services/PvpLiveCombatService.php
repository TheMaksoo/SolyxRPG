<?php

namespace App\Services;

use App\Models\Character;
use App\Models\GemLedger;
use App\Models\Inventory;
use App\Models\PvpLiveMatch;
use App\Models\PvpMatch;
use App\Models\PvpRecord;
use App\Models\Skill;

/**
 * Turn resolution + post-match bookkeeping for real player-vs-player matches (see PvpController's
 * queue/live endpoints). The damage/heal math and reward logic below is lifted from the retired
 * AI-vs-AI instant-sim (PvpController::simulate()/buildFighterState()/takeTurn()/resolveMatch()) —
 * same formulas, just resolving one player-chosen action per call instead of a whole fight at once.
 */
class PvpLiveCombatService
{
    public function __construct(
        private SkillService $skills = new SkillService(),
        private AchievementService $achievements = new AchievementService(),
        private QuestService $quests = new QuestService(),
    ) {}

    /** Builds one combatant's live-match fighter state: a full HP/mana snapshot (real current HP/mana don't
     * carry into a ranked match, same as the old sim) plus every unlocked active (mp_cost > 0) skill with
     * a per-match cooldown tracker keyed by this fighter's own turn count — 'ready_at_turn' is compared
     * against THIS fighter's turns_taken, not a shared round counter, since the two sides act asynchronously
     * over however many HTTP requests it takes rather than in lockstep. */
    public function buildFighterState(Character $c): array
    {
        $stats = $c->effectiveStats();

        $skillRows = $c->skills()->with('skill')->get()
            ->filter(fn ($cs) => $cs->skill && $cs->level >= 1 && $cs->skill->mp_cost > 0)
            ->map(fn ($cs) => [
                'skill_id' => $cs->skill->id,
                'key' => $cs->skill->key,
                'name' => $cs->skill->name,
                'glyph' => $cs->skill->glyph,
                'mp_cost' => $cs->skill->mp_cost,
                'cooldown_seconds' => $cs->skill->cooldown_seconds,
                'effect_json' => $cs->skill->effect_json,
                'level' => $cs->level,
                'ready_at_turn' => 0,
            ])->values()->all();

        return [
            'character_id' => $c->id,
            'name' => $c->name,
            'hp' => $stats['eff_hp_max'],
            'hp_max' => $stats['eff_hp_max'],
            'mana' => $stats['eff_mp_max'],
            'mana_max' => $stats['eff_mp_max'],
            'mana_regen' => max(1, $c->manaRegenPerTick()),
            'stats' => $stats,
            'skills' => $skillRows,
            'undying_used' => false,
            'turns_taken' => 0,
        ];
    }

    /** Resolves ONE turn: the acting fighter's chosen action (attack, or a specific skill by id) against
     * the defender — the defender just takes it, there's no AI and no auto-retaliation in this call; that
     * happens on ITS turn, when that player submits their own action. Mutates $atk/$def in place (HP,
     * mana, cooldowns) and appends narrated log line(s), including an Undying save if it triggers. Aborts
     * with a 422 on an invalid choice (cooldown, insufficient mana, unknown skill) so the controller can
     * surface that back to the acting player without ever mutating match state on a rejected action. */
    public function resolveTurn(array &$atk, array &$def, string $type, ?int $skillId, array $log): array
    {
        $turnNumber = $atk['turns_taken'] + 1;

        if ($type === 'skill') {
            abort_if(! $skillId, 422, 'skill_id is required for a skill action.');

            $idx = null;
            foreach ($atk['skills'] as $i => $entry) {
                if ($entry['skill_id'] === $skillId) {
                    $idx = $i;
                    break;
                }
            }
            abort_if($idx === null, 422, 'That skill is not available to your fighter.');

            $entry = $atk['skills'][$idx];
            abort_if($entry['ready_at_turn'] > $turnNumber, 422, "{$entry['name']} is still on cooldown.");
            abort_if($entry['mp_cost'] > $atk['mana'], 422, 'Not enough mana.');

            $skill = new Skill([
                'mp_cost' => $entry['mp_cost'],
                'cooldown_seconds' => $entry['cooldown_seconds'],
                'effect_json' => $entry['effect_json'],
            ]);

            $atk['mana'] -= $entry['mp_cost'];
            $cooldownTurns = $entry['cooldown_seconds'] > 0 ? max(1, (int) ceil($entry['cooldown_seconds'] / 5)) : 1;
            $atk['skills'][$idx]['ready_at_turn'] = $turnNumber + $cooldownTurns;

            if ($this->skills->isHeal($skill)) {
                $healed = (int) round($atk['hp_max'] * $this->skills->healPct($skill, $entry['level']) / 100);
                $atk['hp'] = min($atk['hp_max'], $atk['hp'] + $healed);
                $log[] = "{$atk['name']} casts {$entry['name']} and heals for {$healed} HP!";
            } else {
                $hit = $this->rollDamage($atk['stats'], $def['stats'], $this->skills->damageMultiplier($skill, $entry['level']));
                $def['hp'] = max(0, $def['hp'] - $hit['amount']);
                $log[] = "{$atk['name']} casts {$entry['name']} on {$def['name']} for {$hit['amount']} damage!".($hit['crit'] ? ' (Critical!)' : '');
            }
        } elseif ($type === 'attack') {
            $hit = $this->rollDamage($atk['stats'], $def['stats']);
            $def['hp'] = max(0, $def['hp'] - $hit['amount']);
            $log[] = "{$atk['name']} hits {$def['name']} for {$hit['amount']}".($hit['crit'] ? ' (Critical!)' : '').'.';
        } else {
            abort(422, 'Unknown action.');
        }

        if ($def['hp'] <= 0 && ! empty($def['stats']['has_undying']) && ! $def['undying_used']) {
            $def['hp'] = 1;
            $def['undying_used'] = true;
            $log[] = "{$def['name']}'s Undying triggers! Survives with 1 HP!";
        }

        $atk['mana'] = min($atk['mana_max'], $atk['mana'] + $atk['mana_regen']);
        $atk['turns_taken'] = $turnNumber;

        return $log;
    }

    /** Every consumable in $character's real inventory usable mid-PvP-match (a straight HP/MP heal —
     * same fields PvE battles read, see CombatService's 'item' branch — buffs like Elixir of Power
     * aren't supported here yet). Queried live rather than snapshotted onto the match, since inventory
     * can change between polls (buy/craft another potion mid-match). */
    public function availablePotions(int $characterId): array
    {
        return Inventory::where('character_id', $characterId)
            ->where('qty', '>', 0)
            ->whereHas('item', fn ($q) => $q->where('type', 'consumable'))
            ->with('item')
            ->get()
            ->filter(fn (Inventory $row) => ($row->item->stat_json['heal_hp_pct'] ?? 0) > 0 || ($row->item->stat_json['heal_mp_pct'] ?? 0) > 0)
            ->map(fn (Inventory $row) => [
                'item_id' => $row->item_id,
                'name' => $row->item->name,
                'glyph' => $row->item->glyph,
                'qty' => $row->qty,
                'heal_hp_pct' => $row->item->stat_json['heal_hp_pct'] ?? 0,
                'heal_mp_pct' => $row->item->stat_json['heal_mp_pct'] ?? 0,
            ])
            ->values()
            ->all();
    }

    /** Drinks a potion mid-match — a free action (mirrors PvE's CombatService: it doesn't end your turn,
     * so healing up never hands the opponent a free hit). Mutates $atk's hp/mana in place and returns the
     * updated log. Aborts with a 422 on an invalid/unusable/out-of-stock item, same as resolveTurn(). */
    public function applyItem(array &$atk, int $characterId, int $itemId, array $log): array
    {
        $inventory = Inventory::where('character_id', $characterId)->where('item_id', $itemId)->with('item')->first();
        abort_if(! $inventory || $inventory->qty < 1, 422, 'Out of that item.');

        $healHpPct = $inventory->item->stat_json['heal_hp_pct'] ?? 0;
        $healMpPct = $inventory->item->stat_json['heal_mp_pct'] ?? 0;
        abort_if($healHpPct <= 0 && $healMpPct <= 0, 422, 'That item cannot be used in a PvP match.');

        $healed = (int) round($atk['hp_max'] * $healHpPct / 100);
        $healedMp = (int) round($atk['mana_max'] * $healMpPct / 100);
        $atk['hp'] = min($atk['hp_max'], $atk['hp'] + $healed);
        $atk['mana'] = min($atk['mana_max'], $atk['mana'] + $healedMp);

        $inventory->decrement('qty');
        if ($inventory->fresh()->qty <= 0) {
            $inventory->delete();
        }

        $healText = array_filter([$healed > 0 ? "{$healed} HP" : null, $healedMp > 0 ? "{$healedMp} MP" : null]);
        $log[] = "{$atk['name']} uses {$inventory->item->name}, restoring ".implode(' and ', $healText).'.';

        return $log;
    }

    /** Same rolled-damage math the old AI-vs-AI simulation used (variance/crit/mitigation), so live turns
     * feel identical to the retired instant-sim system rather than a different combat feel. */
    public function rollDamage(array $attacker, array $defender, float $mult = 1.0): array
    {
        $dmg = (int) round($attacker['eff_atk'] * $mult * (0.75 + mt_rand() / mt_getrandmax() * 0.3));
        $crit = (mt_rand() / mt_getrandmax() * 100) < ($attacker['crit_chance'] ?? 18);
        if ($crit) {
            $dmg = (int) round($dmg * ($attacker['crit_damage_mult'] ?? 1.8));
        }
        $dmg = max(5, $dmg - (int) round(($defender['eff_def'] ?? 0) * 0.55));

        return ['amount' => $dmg, 'crit' => $crit];
    }

    /**
     * Runs the same post-match bookkeeping the old instant-sim resolveMatch() did, adapted for two real
     * accounts: ELO-style rating delta, win/loss/streak counters, a PvpMatch history row for EACH side (the
     * old flow only ever wrote one, from the challenger's perspective, since the "opponent" wasn't really
     * playing — now both sides are real players and both get a history row), quest progress, and both the
     * daily-first-win and 10-wins-today rewards for the winner. Returns a small summary the caller stashes
     * on the match (state_json['reward']) so either player's client can render the banner whenever it next
     * polls, not only the client that happened to submit the finishing action/forfeit.
     */
    public function resolveMatchWin(PvpLiveMatch $match, int $winnerCharacterId, int $loserCharacterId, array $log): array
    {
        $winner = Character::find($winnerCharacterId);
        $loser = Character::find($loserCharacterId);
        if (! $winner || ! $loser) {
            return ['rating_delta' => 0, 'daily_reward_granted' => false, 'ten_win_reward_granted' => false];
        }

        $winRecord = $winner->pvpRecord()->firstOrCreate([], ['rating' => 1000]);
        $loseRecord = $loser->pvpRecord()->firstOrCreate([], ['rating' => 1000]);

        $expected = 1 / (1 + 10 ** (($loseRecord->rating - $winRecord->rating) / 400));
        $delta = (int) round(32 * (1 - $expected));

        $winRecord->update([
            'rating' => max(0, $winRecord->rating + $delta),
            'wins' => $winRecord->wins + 1,
            'win_streak' => $winRecord->win_streak + 1,
        ]);
        $loseRecord->update([
            'rating' => max(0, $loseRecord->rating - $delta),
            'losses' => $loseRecord->losses + 1,
            'win_streak' => 0,
        ]);

        PvpMatch::create([
            'character_id' => $winner->id,
            'opponent_id' => $loser->id,
            'result' => 'win',
            'rating_delta' => $delta,
            'log_json' => $log,
            'created_at' => now(),
        ]);
        PvpMatch::create([
            'character_id' => $loser->id,
            'opponent_id' => $winner->id,
            'result' => 'loss',
            'rating_delta' => -$delta,
            'log_json' => $log,
            'created_at' => now(),
        ]);

        $this->achievements->check($winner->fresh());
        $this->quests->progress($winner, 'pvp_wins');

        $dailyReward = $this->grantDailyPvpRewardIfDue($winner);
        $tenWinReward = $this->grantTenWinRewardIfDue($winner);

        return [
            'rating_delta' => $delta,
            'daily_reward_granted' => $dailyReward['granted'],
            'daily_reward_gold' => $dailyReward['gold'],
            'daily_reward_gems' => $dailyReward['gems'],
            'ten_win_reward_granted' => $tenWinReward['granted'],
            'ten_win_reward_gold' => $tenWinReward['gold'],
            'ten_win_reward_gems' => $tenWinReward['gems'],
            'pvp_wins_today' => $winner->pvp_wins_today,
        ];
    }

    /** A second, bigger reward on top of the first-win-of-the-day one — hits 10 wins in a calendar day and
     * gets a flat gold+gem bonus, once per day. Moved here verbatim from PvpController (this service is now
     * the sole caller now that the instant-sim path is retired). */
    private function grantTenWinRewardIfDue(Character $character): array
    {
        if (! $character->pvp_wins_today_date || ! $character->pvp_wins_today_date->isToday()) {
            $character->pvp_wins_today = 0;
            $character->pvp_wins_today_date = now();
        }

        $character->pvp_wins_today++;

        if ($character->pvp_wins_today < 10 || ($character->pvp_10_wins_reward_at && $character->pvp_10_wins_reward_at->isSameDay(now()))) {
            $character->save();

            return ['granted' => false, 'gold' => 0, 'gems' => 0];
        }

        $gold = 1500;
        $gems = 40;

        $character->pvp_10_wins_reward_at = now();
        $character->gold += $gold;
        $character->gems += $gems;
        $character->save();
        GemLedger::log($character, $gems, 'pvp_10_wins_reward');

        return ['granted' => true, 'gold' => $gold, 'gems' => $gems];
    }

    /** Grants a once-per-calendar-day, tier-scaled gold/gem reward on a player's first PvP win of the day.
     * Moved here verbatim from PvpController — see grantTenWinRewardIfDue's note above. */
    private function grantDailyPvpRewardIfDue(Character $character): array
    {
        if ($character->last_daily_reward_at && $character->last_daily_reward_at->isSameDay(now())) {
            return ['granted' => false, 'gold' => 0, 'gems' => 0];
        }

        $record = $character->pvpRecord()->firstOrCreate([], ['rating' => 1000]);
        $tier = PvpRecord::tierFor($record->rating);
        $tierIndex = array_search($tier, PvpRecord::PVP_TIERS) + 1;

        $gold = 200 * $tierIndex;
        $gems = 5 * $tierIndex;

        $character->last_daily_reward_at = now();
        $character->gold += $gold;
        $character->gems += $gems;
        $character->save();
        GemLedger::log($character, $gems, 'pvp_daily_win_reward');

        return ['granted' => true, 'gold' => $gold, 'gems' => $gems];
    }
}
