<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\FeatureFlag;
use App\Models\Guild;
use App\Services\AchievementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GuildController extends Controller
{
    public function __construct(
        private AchievementService $achievements = new AchievementService(),
    ) {
    }

    private const ROLE_RANK = ['member' => 0, 'officer' => 1, 'master' => 2];
    /** Returns the character's own guild (roster + recent chat), or a browse list if not in one. */
    public function index(Request $request)
    {
        abort_unless(FeatureFlag::gate('guilds', $request->user()), 403, 'Guilds are not currently available.');

        $character = $request->user()->character;
        abort_unless($character, 404);

        $membership = $character->guildMembership;

        if (! $membership) {
            return response()->json(['guild' => null, 'browse' => Guild::withCount('members')->get()]);
        }

        $guild = $membership->guild()->with([
            'members.character.activeColor',
            'messages' => fn ($q) => $q->latest('created_at')->limit(50),
            'messages.character.activeColor',
        ])->first();

        $power = $guild->members->sum(fn ($m) => $m->character?->effectiveStats()['power'] ?? 0);
        $guildRank = Guild::orderByDesc('level')->orderByDesc('xp')->pluck('id')->search($guild->id);
        $guildRank = $guildRank === false ? null : $guildRank + 1;
        $displayWarStatus = ($guild->last_activity_at && $guild->last_activity_at->gt(now()->subHours(48))) ? 'active' : 'quiet';

        $upgrades = [];
        foreach (Guild::UPGRADE_TRACKS as $track => $definition) {
            $tier = $guild->{$definition['column']};
            $nextTier = min($tier + 1, Guild::MAX_UPGRADE_TIER);
            $upgrades[$track] = [
                'tier' => $tier,
                'bonus_pct' => $guild->upgradeBonusPct($track),
                'max_tier' => Guild::MAX_UPGRADE_TIER,
                'next_cost' => $tier >= Guild::MAX_UPGRADE_TIER ? null : $guild->upgradeCost($track, $nextTier),
            ];
        }

        return response()->json([
            'guild' => $guild,
            'my_role' => $membership->role,
            'power' => $power,
            'guild_rank' => $guildRank,
            'war_status' => $displayWarStatus,
            'upgrades' => $upgrades,
        ]);
    }

    public function store(Request $request)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($character->guildMembership, 422, 'Already in a guild.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'tag' => ['required', 'string', 'max:5'],
        ]);

        $guild = Guild::create([...$data, 'owner_id' => $character->id]);
        $guild->members()->create(['character_id' => $character->id, 'role' => 'master']);
        $this->achievements->check($character->fresh());

        return response()->json(['guild' => $guild->fresh('members')], 201);
    }

    public function join(Request $request, Guild $guild)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_if($character->guildMembership, 422, 'Already in a guild.');

        if ($guild->members()->count() >= $guild->member_cap) {
            return response()->json(['message' => 'Guild is full.'], 422);
        }

        $guild->members()->create(['character_id' => $character->id, 'role' => 'member']);
        $guild->update(['last_activity_at' => now(), 'war_status' => 'active']);
        $this->achievements->check($character->fresh());

        return response()->json(['guild' => $guild->fresh('members.character')]);
    }

    public function message(Request $request, Guild $guild)
    {
        $character = $request->user()->character;
        abort_unless($character, 404);
        abort_unless($guild->members()->where('character_id', $character->id)->exists(), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);

        $message = $guild->messages()->create([
            'character_id' => $character->id,
            'body' => $data['body'],
            'created_at' => now(),
        ]);
        $guild->update(['last_activity_at' => now(), 'war_status' => 'active']);

        return response()->json(['message_sent' => $message->load('character.activeColor')]);
    }

    public function deposit(Request $request, Guild $guild)
    {
        $character = $request->user()->character;
        $this->requireMembership($character, $guild);

        $data = $request->validate([
            'currency' => ['required', Rule::in(['gold', 'gems'])],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        if ($character->{$data['currency']} < $data['amount']) {
            return response()->json(['message' => 'Not enough '.$data['currency'].'.'], 422);
        }

        $character->decrement($data['currency'], $data['amount']);
        $guild->increment('bank_'.$data['currency'], $data['amount']);
        $guild->update(['last_activity_at' => now(), 'war_status' => 'active']);

        return response()->json(['guild' => $guild->fresh(), 'character' => $character->fresh()]);
    }

    public function withdraw(Request $request, Guild $guild)
    {
        $character = $request->user()->character;
        $membership = $this->requireMembership($character, $guild);
        abort_if(self::ROLE_RANK[$membership->role] < self::ROLE_RANK['officer'], 403, 'Only officers and the guild master can withdraw.');

        $data = $request->validate([
            'currency' => ['required', Rule::in(['gold', 'gems'])],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        if ($guild->{'bank_'.$data['currency']} < $data['amount']) {
            return response()->json(['message' => 'Guild bank does not have that much.'], 422);
        }

        $guild->decrement('bank_'.$data['currency'], $data['amount']);
        $character->increment($data['currency'], $data['amount']);

        return response()->json(['guild' => $guild->fresh(), 'character' => $character->fresh()]);
    }

    public function purchaseUpgrade(Request $request, Guild $guild)
    {
        $character = $request->user()->character;
        $membership = $this->requireMembership($character, $guild);
        abort_if(self::ROLE_RANK[$membership->role] < self::ROLE_RANK['officer'], 403, 'Only officers and the guild master can purchase upgrades.');

        $data = $request->validate([
            'track' => ['required', Rule::in(array_keys(Guild::UPGRADE_TRACKS))],
        ]);

        $track = $data['track'];
        $column = Guild::UPGRADE_TRACKS[$track]['column'];
        $currentTier = $guild->{$column};

        if ($currentTier >= Guild::MAX_UPGRADE_TIER) {
            return response()->json(['message' => 'This upgrade is already at its maximum tier.'], 422);
        }

        $nextTier = $currentTier + 1;
        $cost = $guild->upgradeCost($track, $nextTier);

        if ($guild->bank_gold < $cost) {
            return response()->json(['message' => 'Guild bank does not have enough gold for this upgrade.'], 422);
        }

        $guild->decrement('bank_gold', $cost);
        $guild->increment($column);
        $guild->update(['last_activity_at' => now(), 'war_status' => 'active']);

        return response()->json(['guild' => $guild->fresh()]);
    }

    public function promote(Request $request, Guild $guild, Character $target)
    {
        $character = $request->user()->character;
        $membership = $this->requireMembership($character, $guild);
        abort_unless($membership->role === 'master', 403, 'Only the guild master can change roles.');

        $targetMembership = $guild->members()->where('character_id', $target->id)->firstOrFail();
        abort_if($target->id === $character->id, 422, 'Use another action to change your own role.');

        $data = $request->validate(['role' => ['required', Rule::in(['member', 'officer', 'master'])]]);

        if ($data['role'] === 'master') {
            $membership->update(['role' => 'officer']);
            $targetMembership->update(['role' => 'master']);
            $guild->update(['owner_id' => $target->id]);
        } else {
            $targetMembership->update(['role' => $data['role']]);
        }

        return response()->json(['guild' => $guild->fresh('members.character')]);
    }

    public function kick(Request $request, Guild $guild, Character $target)
    {
        $character = $request->user()->character;
        $membership = $this->requireMembership($character, $guild);
        abort_if(self::ROLE_RANK[$membership->role] < self::ROLE_RANK['officer'], 403, 'Only officers and the guild master can kick members.');
        abort_if($target->id === $character->id, 422, 'Use leave instead of kicking yourself.');

        $targetMembership = $guild->members()->where('character_id', $target->id)->firstOrFail();
        abort_if(self::ROLE_RANK[$targetMembership->role] >= self::ROLE_RANK[$membership->role], 403, 'Cannot kick a member of equal or higher rank.');

        $targetMembership->delete();

        return response()->json(['guild' => $guild->fresh('members.character')]);
    }

    public function leave(Request $request, Guild $guild)
    {
        $character = $request->user()->character;
        $membership = $this->requireMembership($character, $guild);

        if ($membership->role === 'master') {
            $successor = $guild->members()->where('character_id', '!=', $character->id)
                ->orderByRaw("field(role, 'officer', 'member')")
                ->first();

            if (! $successor) {
                $membership->delete();
                $guild->delete();

                return response()->json(['guild' => null, 'disbanded' => true]);
            }

            $successor->update(['role' => 'master']);
            $guild->update(['owner_id' => $successor->character_id]);
        }

        $membership->delete();

        return response()->json(['guild' => null]);
    }

    private function requireMembership(?Character $character, Guild $guild)
    {
        abort_unless($character, 404);
        $membership = $guild->members()->where('character_id', $character->id)->first();
        abort_unless($membership, 403, 'Not a member of this guild.');

        return $membership;
    }
}
