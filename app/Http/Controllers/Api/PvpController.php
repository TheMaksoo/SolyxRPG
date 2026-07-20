<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\FeatureFlag;
use App\Models\GemLedger;
use App\Models\PvpMatch;
use App\Models\PvpRecord;
use App\Services\AchievementService;
use App\Services\SkillService;
use Illuminate\Http\Request;

class PvpController extends Controller
{
    public function __construct(
        private AchievementService $achievements = new AchievementService(),
    ) {
    }

    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('pvp', $request->user()), 403, 'PvP Arena is not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $record = $character->pvpRecord()->firstOrCreate([], ['rating' => 1000]);

        $opponents = Character::where('id', '!=', $character->id)
            ->with('pvpRecord')
            ->limit(20)
            ->get()
            ->map(fn (Character $c) => [
                'character' => $c,
                'rating' => $c->pvpRecord->rating ?? 1000,
            ]);

        $history = PvpMatch::where('character_id', $character->id)
            ->with('opponent')
            ->latest('created_at')
            ->limit(10)
            ->get();

        $allRatings = PvpRecord::pluck('rating')->all();
        // Hybrid rank merges the old fixed-tier ladder and the percentile bracket into one label: your
        // rank name is whichever tier your live percentile against the current ladder lands in, and the
        // outright #1 player gets a distinct crown on top of it. See PvpRecord::hybridRank() for cutoffs.
        $hybridRank = PvpRecord::hybridRank($record->rating, $allRatings);
        $maxAttempts = 10 + $request->user()->vipPvpBonusAttempts();
        $attemptsUsed = ($character->pvp_attempts_reset_at && $character->pvp_attempts_reset_at->isFuture())
            ? $character->pvp_attempts_used
            : 0;

        return response()->json([
            'record' => $record,
            'rank' => $hybridRank,
            'rank_progress' => PvpRecord::hybridProgress($hybridRank['percentile']),
            'rank_ladder' => array_map(fn ($t) => [
                'name' => $t['name'],
                'color' => $t['color'],
                'is_current' => $t['name'] === $hybridRank['base_name'],
            ], PvpRecord::PVP_TIERS),
            'pvp_attempts_used' => $attemptsUsed,
            'pvp_attempts_max' => $maxAttempts,
            'opponents' => $opponents->map(fn ($row) => [
                ...$row,
                'rank' => PvpRecord::hybridRank($row['rating'], $allRatings),
                'difficulty' => match (true) {
                    $row['rating'] > $record->rating + 75 => 'Hard',
                    $row['rating'] < $record->rating - 75 => 'Easy',
                    default => 'Medium',
                },
            ]),
            'history' => $history,
        ]);
    }

    public function findMatch(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $this->consumePvpAttempt($character);

        $opponent = Character::where('id', '!=', $character->id)->inRandomOrder()->first();
        abort_if(! $opponent, 422, 'No opponents available yet.');

        return $this->resolveMatch($character, $opponent);
    }

    public function challenge(Request $request, Character $opponent)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($character->id === $opponent->id, 422, 'Cannot challenge yourself.');

        $this->consumePvpAttempt($character);

        return $this->resolveMatch($character, $opponent);
    }

    /** Resets the daily attempt counter if the reset window has lapsed, enforces the VIP-scaled daily cap,
     * then consumes one attempt. Aborts with a 422 if the player is out of attempts for today. */
    private function consumePvpAttempt(Character $character): void
    {
        if (! $character->pvp_attempts_reset_at || $character->pvp_attempts_reset_at->isPast()) {
            $character->pvp_attempts_used = 0;
            $character->pvp_attempts_reset_at = now()->endOfDay();
        }

        $max = 10 + $character->user->vipPvpBonusAttempts();
        abort_if($character->pvp_attempts_used >= $max, 422, 'No PvP attempts remaining today. Resets at midnight.');

        $character->pvp_attempts_used++;
        $character->save();
    }

    private function resolveMatch(Character $character, Character $opponent): \Illuminate\Http\JsonResponse
    {
        $myRecord = $character->pvpRecord()->firstOrCreate([], ['rating' => 1000]);
        $oppRecord = $opponent->pvpRecord()->firstOrCreate([], ['rating' => 1000]);

        $sim = $this->simulate($character, $opponent);
        $won = $sim['winner'] === 'a';

        $expected = 1 / (1 + 10 ** (($oppRecord->rating - $myRecord->rating) / 400));
        $delta = (int) round(32 * (($won ? 1 : 0) - $expected));

        $myRecord->update([
            'rating' => max(0, $myRecord->rating + $delta),
            'wins' => $myRecord->wins + ($won ? 1 : 0),
            'losses' => $myRecord->losses + ($won ? 0 : 1),
            'win_streak' => $won ? $myRecord->win_streak + 1 : 0,
        ]);
        $oppRecord->update([
            'rating' => max(0, $oppRecord->rating - $delta),
            'wins' => $oppRecord->wins + ($won ? 0 : 1),
            'losses' => $oppRecord->losses + ($won ? 1 : 0),
        ]);

        PvpMatch::create([
            'character_id' => $character->id,
            'opponent_id' => $opponent->id,
            'result' => $won ? 'win' : 'loss',
            'rating_delta' => $delta,
            'log_json' => $sim['log'],
            'created_at' => now(),
        ]);

        $this->achievements->check($character->fresh());

        // Daily reward gold/gems land on the character row here, but nothing below this point re-fetched
        // $character — the JSON response used to omit the character entirely, so PvpPage.vue had nothing
        // to hand the global character store, and the top bar's gold/gem pills (fed by that store, see
        // GameLayout.vue) kept showing the pre-reward balance until the player happened to navigate to a
        // page that re-fetches the character. That's the "missing" daily reward: it *was* granted and even
        // shown in the in-page callout, just invisible everywhere else until an unrelated refresh. Returning
        // the fresh character lets the frontend sync the store immediately, same pattern BattlePage.vue uses.
        $dailyReward = $won ? $this->grantDailyPvpRewardIfDue($character) : ['granted' => false, 'gold' => 0, 'gems' => 0];

        return response()->json([
            'result' => $won ? 'win' : 'loss',
            'rating_delta' => $delta,
            'log' => $sim['log'],
            'record' => $myRecord->fresh(),
            'character' => $character->fresh(),
            'opponent' => $opponent->only(['id', 'name', 'base_class', 'level']),
            'daily_reward_granted' => $dailyReward['granted'],
            'daily_reward_gold' => $dailyReward['gold'],
            'daily_reward_gems' => $dailyReward['gems'],
        ]);
    }

    /** Grants a once-per-calendar-day, tier-scaled gold/gem reward on a player's first PvP win of the day.
     * Gold = 200 * tier index (Bronze=1..Master=6), gems = 5 * tier index. */
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

    /**
     * Simulates a real multi-round fight using both sides' effective combat stats AND their actual
     * learned skill loadouts, instead of a flat back-and-forth of plain attacks. Each combatant's
     * passive skills (atk_pct/def_pct/Undying) are already baked into effectiveStats() below, same as
     * everywhere else in the game — the new part here is the ACTIVE (mp_cost > 0) skills: every round,
     * a fighter uses their best available one (see chooseSkill()) instead of a plain attack whenever one
     * is off cooldown and affordable, falling back to a plain attack otherwise. This is a self-contained
     * simulation (in-memory HP/mana/cooldowns for this fight only) — it deliberately does NOT read or
     * write CharacterSkill::cooldown_expires_at, so a simulated PvP match never puts a player's real
     * skill cooldowns on hold for PvE, and never collides with cooldown/mana-check work happening
     * elsewhere in CombatService/SkillController.
     */
    private function simulate(Character $a, Character $b): array
    {
        $skillService = new SkillService();
        $fighterA = $this->buildFighterState($a, $skillService);
        $fighterB = $this->buildFighterState($b, $skillService);
        $log = [];
        $round = 0;
        $maxRounds = 30;

        while ($fighterA['hp'] > 0 && $fighterB['hp'] > 0 && $round < $maxRounds) {
            $round++;

            $log = $this->takeTurn($a->name, $fighterA, $b->name, $fighterB, $round, $skillService, $log);
            if ($fighterB['hp'] <= 0) {
                break;
            }

            $log = $this->takeTurn($b->name, $fighterB, $a->name, $fighterA, $round, $skillService, $log);
        }

        $pctA = $fighterA['hp'] / max(1, $fighterA['hp_max']);
        $pctB = $fighterB['hp'] / max(1, $fighterB['hp_max']);
        $winner = match (true) {
            $fighterB['hp'] <= 0 && $fighterA['hp'] > 0 => 'a',
            $fighterA['hp'] <= 0 && $fighterB['hp'] > 0 => 'b',
            $pctA === $pctB => $fighterA['stats']['power'] >= $fighterB['stats']['power'] ? 'a' : 'b',
            default => $pctA > $pctB ? 'a' : 'b',
        };

        $log[] = $winner === 'a' ? "{$a->name} wins!" : "{$b->name} wins!";

        return ['winner' => $winner, 'log' => $log];
    }

    /** Builds one combatant's in-memory fight state: full HP/mana (like the old sim, real current HP/mana
     * don't carry into a ranked match), plus every unlocked active (mp_cost > 0) skill with a per-fight
     * cooldown tracker. Passive skills need no separate handling — effectiveStats() already folds their
     * atk_pct/def_pct/Undying bonuses into the numbers below, same as PvE. */
    private function buildFighterState(Character $c, SkillService $skillService): array
    {
        $stats = $c->effectiveStats();

        $skills = $c->skills()->with('skill')->get()
            ->filter(fn ($cs) => $cs->skill && $cs->level >= 1 && $cs->skill->mp_cost > 0)
            ->map(fn ($cs) => ['skill' => $cs->skill, 'level' => $cs->level, 'ready_round' => 0])
            ->values()->all();

        return [
            'hp' => $stats['eff_hp_max'],
            'hp_max' => $stats['eff_hp_max'],
            'mana' => $stats['eff_mp_max'],
            'mana_max' => $stats['eff_mp_max'],
            'mana_regen' => max(1, $c->manaRegenPerTick()),
            'stats' => $stats,
            'skills' => $skills,
            'undying_used' => false,
        ];
    }

    /** Resolves one combatant's action for the round: their best usable skill (damage or heal) if one is
     * off cooldown and affordable, else a plain attack. Mutates both fighter states in place (damage/heal/
     * mana/cooldowns) and appends the narrated line(s) to $log, including an Undying save if it triggers. */
    private function takeTurn(string $attackerName, array &$atk, string $defenderName, array &$def, int $round, SkillService $skillService, array $log): array
    {
        $choice = $this->chooseSkill($atk, $skillService, $round);

        if ($choice !== null) {
            [$idx, $skill, $level] = $choice;
            $atk['mana'] -= $skill->mp_cost;
            $cooldownRounds = $skill->cooldown_seconds > 0 ? max(1, (int) ceil($skill->cooldown_seconds / 5)) : 1;
            $atk['skills'][$idx]['ready_round'] = $round + $cooldownRounds;

            if ($skillService->isHeal($skill)) {
                $healed = (int) round($atk['hp_max'] * $skillService->healPct($skill, $level) / 100);
                $atk['hp'] = min($atk['hp_max'], $atk['hp'] + $healed);
                $log[] = "{$attackerName} casts {$skill->name} and heals for {$healed} HP!";
            } else {
                $hit = $this->rollDamage($atk['stats'], $def['stats'], $skillService->damageMultiplier($skill, $level));
                $def['hp'] = max(0, $def['hp'] - $hit['amount']);
                $log[] = "{$attackerName} casts {$skill->name} on {$defenderName} for {$hit['amount']} damage!".($hit['crit'] ? ' (Critical!)' : '');
            }
        } else {
            $hit = $this->rollDamage($atk['stats'], $def['stats']);
            $def['hp'] = max(0, $def['hp'] - $hit['amount']);
            $log[] = "{$attackerName} hits {$defenderName} for {$hit['amount']}".($hit['crit'] ? ' (Critical!)' : '').'.';
        }

        if ($def['hp'] <= 0 && ! empty($def['stats']['has_undying']) && ! $def['undying_used']) {
            $def['hp'] = 1;
            $def['undying_used'] = true;
            $log[] = "{$defenderName}'s Undying triggers! Survives with 1 HP!";
        }

        $atk['mana'] = min($atk['mana_max'], $atk['mana'] + $atk['mana_regen']);

        return $log;
    }

    /** Skill-selection priority: below 40% HP, prefer the strongest usable heal (survival first). Otherwise
     * prefer the strongest usable damage skill — "strongest" approximated as highest mana cost, since
     * pricier skills are consistently the harder-hitting ones in this game's skill design. If nothing
     * matches either of those, fall through to any still-usable heal (better than nothing even above the
     * emergency threshold) before finally giving up and letting the caller fall back to a plain attack.
     * Returns [skillsArrayIndex, Skill, level] or null. */
    private function chooseSkill(array $atk, SkillService $skillService, int $round): ?array
    {
        $usable = [];
        foreach ($atk['skills'] as $idx => $entry) {
            if ($entry['ready_round'] > $round || $entry['skill']->mp_cost > $atk['mana']) {
                continue;
            }
            $usable[$idx] = $entry;
        }
        if (! $usable) {
            return null;
        }

        $hpPct = $atk['hp'] / max(1, $atk['hp_max']);
        $heals = array_filter($usable, fn ($e) => $skillService->isHeal($e['skill']));
        $damage = array_filter($usable, fn ($e) => ! $skillService->isHeal($e['skill']));

        if ($hpPct < 0.4 && $heals) {
            $idx = $this->highestMpCostIndex($heals);

            return [$idx, $heals[$idx]['skill'], $heals[$idx]['level']];
        }

        if ($damage) {
            $idx = $this->highestMpCostIndex($damage);

            return [$idx, $damage[$idx]['skill'], $damage[$idx]['level']];
        }

        if ($heals && $hpPct < 0.85) {
            $idx = $this->highestMpCostIndex($heals);

            return [$idx, $heals[$idx]['skill'], $heals[$idx]['level']];
        }

        return null;
    }

    /** Index (within the given usable-skills subset) of the entry with the highest mp_cost. */
    private function highestMpCostIndex(array $entries): int|string
    {
        $bestIdx = array_key_first($entries);
        $bestCost = -1;
        foreach ($entries as $idx => $entry) {
            if ($entry['skill']->mp_cost > $bestCost) {
                $bestCost = $entry['skill']->mp_cost;
                $bestIdx = $idx;
            }
        }

        return $bestIdx;
    }

    /** Rolls one hit's damage: attacker's eff_atk scaled by $mult (1.0 for a plain attack, a skill's
     * damageMultiplier() otherwise), with the same variance/crit/mitigation math the old flat attack loop
     * used, so skill hits and plain attacks feel like the same combat system rather than two different ones. */
    private function rollDamage(array $attacker, array $defender, float $mult = 1.0): array
    {
        $dmg = (int) round($attacker['eff_atk'] * $mult * (0.75 + mt_rand() / mt_getrandmax() * 0.3));
        $crit = (mt_rand() / mt_getrandmax() * 100) < ($attacker['crit_chance'] ?? 18);
        if ($crit) {
            $dmg = (int) round($dmg * ($attacker['crit_damage_mult'] ?? 1.8));
        }
        $dmg = max(5, $dmg - (int) round(($defender['eff_def'] ?? 0) * 0.55));

        return ['amount' => $dmg, 'crit' => $crit];
    }
}
