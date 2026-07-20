<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\FeatureFlag;
use App\Models\Guild;
use App\Models\GuildWarBattle;
use Illuminate\Http\Request;

class GuildWarController extends Controller
{
    private const ROLE_RANK = ['member' => 0, 'officer' => 1, 'master' => 2];

    private const WIN_POINTS = 50;

    private const LOSS_POINTS = 10;

    private const VARIANCE = 0.10;

    /** Returns guild war status: whether it's active this weekend, the guild's current battle, and points/leaderboard. */
    public function status(Request $request)
    {
        abort_unless(FeatureFlag::gate('guilds', $request->user()), 403, 'Guilds are not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $membership = $character->guildMembership;

        if (! $membership) {
            return response()->json(['guild' => null, 'is_weekend' => now()->isWeekend()]);
        }

        $guild = $membership->guild;
        $this->resetPointsIfNeeded($guild);

        $battle = $this->currentBattleFor($guild);
        if ($battle) {
            $battle = $this->resolveIfDue($battle);
        }

        return response()->json([
            'is_weekend' => now()->isWeekend(),
            'my_role' => $membership->role,
            'guild_war_points' => $guild->guild_war_points,
            'leaderboard_rank' => $this->leaderboardRank($guild),
            'battle' => $this->formatBattle($battle, $guild),
        ]);
    }

    /** Enters the guild into this weekend's guild war matchmaking. Officer+ only, weekends only. */
    public function enter(Request $request, Guild $guild)
    {
        $character = $request->user()->character;
        $membership = $this->requireMembership($character, $guild);
        abort_if(self::ROLE_RANK[$membership->role] < self::ROLE_RANK['officer'], 403, 'Only officers and the guild master can enter guild wars.');
        abort_unless(now()->isWeekend(), 422, 'Guild wars are only active on weekends.');

        $this->resetPointsIfNeeded($guild);

        $existing = $this->currentBattleFor($guild);
        if ($existing) {
            $existing = $this->resolveIfDue($existing);

            return response()->json([
                'battle' => $this->formatBattle($existing, $guild),
                'guild_war_points' => $guild->fresh()->guild_war_points,
            ]);
        }

        $today = now()->toDateString();

        // Find another guild that also has no battle scheduled for today and isn't this one.
        $scheduledGuildIds = GuildWarBattle::whereDate('scheduled_for', $today)
            ->get(['guild_a_id', 'guild_b_id'])
            ->flatMap(fn ($b) => [$b->guild_a_id, $b->guild_b_id])
            ->push($guild->id)
            ->unique();

        $opponent = Guild::whereNotIn('id', $scheduledGuildIds)
            ->where('id', '!=', $guild->id)
            ->inRandomOrder()
            ->first();

        if (! $opponent) {
            return response()->json([
                'battle' => null,
                'message' => 'No opponent available yet. Waiting for another guild to enter.',
                'guild_war_points' => $guild->guild_war_points,
            ]);
        }

        $guildPower = $this->guildPower($guild);
        $opponentPower = $this->guildPower($opponent);

        $battle = GuildWarBattle::create([
            'guild_a_id' => $guild->id,
            'guild_b_id' => $opponent->id,
            'guild_a_power' => $guildPower,
            'guild_b_power' => $opponentPower,
            'scheduled_for' => $today,
        ]);

        return response()->json([
            'battle' => $this->formatBattle($battle, $guild),
            'guild_war_points' => $guild->guild_war_points,
        ]);
    }

    /** Manually resolve a battle (officer+ gated). Battles otherwise auto-resolve lazily on status()/enter(). */
    public function resolve(Request $request, GuildWarBattle $battle)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);

        $membership = $character->guildMembership;
        abort_unless($membership && in_array($membership->guild_id, [$battle->guild_a_id, $battle->guild_b_id]), 403);
        abort_if(self::ROLE_RANK[$membership->role] < self::ROLE_RANK['officer'], 403, 'Only officers and the guild master can resolve guild wars.');

        $battle = $this->resolveIfDue($battle, force: true);

        return response()->json(['battle' => $this->formatBattle($battle, $membership->guild)]);
    }

    /** The guild's battle for the current weekend, if any (most recent one scheduled today or the prior weekend day). */
    private function currentBattleFor(Guild $guild): ?GuildWarBattle
    {
        return GuildWarBattle::where(function ($q) use ($guild) {
            $q->where('guild_a_id', $guild->id)->orWhere('guild_b_id', $guild->id);
        })
            ->whereBetween('scheduled_for', [now()->startOfWeek()->addDays(5)->toDateString(), now()->endOfWeek()->toDateString()])
            ->latest('scheduled_for')
            ->first();
    }

    /** Resolves the battle if it isn't already resolved. `force` allows resolving before the weekend ends. */
    private function resolveIfDue(GuildWarBattle $battle, bool $force = false): GuildWarBattle
    {
        if ($battle->resolved_at) {
            return $battle;
        }

        if (! $force && ! now()->isWeekend()) {
            return $battle;
        }

        $guildA = Guild::find($battle->guild_a_id);
        $guildB = Guild::find($battle->guild_b_id);

        $rollA = $battle->guild_a_power * (1 + $this->randomVariance());
        $rollB = $battle->guild_b_power * (1 + $this->randomVariance());

        $winnerGuild = $rollA >= $rollB ? $guildA : $guildB;
        $loserGuild = $rollA >= $rollB ? $guildB : $guildA;

        $battle->update([
            'winner_guild_id' => $winnerGuild?->id,
            'resolved_at' => now(),
        ]);

        if ($winnerGuild) {
            $this->resetPointsIfNeeded($winnerGuild);
            $winnerGuild->increment('guild_war_points', self::WIN_POINTS);
        }

        if ($loserGuild) {
            $this->resetPointsIfNeeded($loserGuild);
            $loserGuild->increment('guild_war_points', self::LOSS_POINTS);
        }

        return $battle->fresh();
    }

    private function randomVariance(): float
    {
        return (mt_rand(-1000, 1000) / 1000) * self::VARIANCE;
    }

    /** Resets guild_war_points to 0 if the last reset was before this week's Monday. */
    private function resetPointsIfNeeded(Guild $guild): void
    {
        $mondayThisWeek = now()->startOfWeek();

        if (! $guild->guild_war_points_reset_at || $guild->guild_war_points_reset_at->lt($mondayThisWeek)) {
            $guild->update(['guild_war_points' => 0, 'guild_war_points_reset_at' => now()]);
        }
    }

    private function guildPower(Guild $guild): int
    {
        return $guild->members->sum(fn ($m) => $m->character?->effectiveStats()['power'] ?? 0);
    }

    private function leaderboardRank(Guild $guild): ?int
    {
        $rank = Guild::orderByDesc('guild_war_points')->pluck('id')->search($guild->id);

        return $rank === false ? null : $rank + 1;
    }

    private function formatBattle(?GuildWarBattle $battle, Guild $guild): ?array
    {
        if (! $battle) {
            return null;
        }

        $isGuildA = $battle->guild_a_id === $guild->id;
        $opponentId = $isGuildA ? $battle->guild_b_id : $battle->guild_a_id;
        $opponent = Guild::find($opponentId);

        $status = 'pending';
        if ($battle->resolved_at) {
            $status = $battle->winner_guild_id === $guild->id ? 'won' : 'lost';
        }

        return [
            'id' => $battle->id,
            'opponent' => $opponent ? ['id' => $opponent->id, 'name' => $opponent->name, 'tag' => $opponent->tag] : null,
            'my_power' => $isGuildA ? $battle->guild_a_power : $battle->guild_b_power,
            'opponent_power' => $isGuildA ? $battle->guild_b_power : $battle->guild_a_power,
            'scheduled_for' => $battle->scheduled_for->toDateString(),
            'resolved_at' => $battle->resolved_at,
            'status' => $status,
        ];
    }

    private function requireMembership(?Character $character, Guild $guild)
    {
        abort_unless($character, 404);
        $membership = $guild->members()->where('character_id', $character->id)->first();
        abort_unless($membership, 403, 'Not a member of this guild.');

        return $membership;
    }
}
