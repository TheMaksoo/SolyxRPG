<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One-time lookup of accounts from the original Discord-bot version of Solyx, recovered from a
 * 2023-07-08 mongodump (the live cluster no longer exists). A current player whose linked Discord ID
 * matches a row here gets the "Legend of Solyx" title automatically — see grantLegendTitleIfMatched(),
 * called from SocialiteController (on linking Discord) and CharacterController (on creating a
 * character, in case Discord was linked before any character existed to grant the title to). */
class LegacyDiscordUser extends Model
{
    public $timestamps = false;

    protected $fillable = ['discord_id', 'name'];

    private const LEGEND_TITLE_KEY = 'title_legend';

    /** Grants the Legend of Solyx title to every one of $user's characters if their linked Discord
     * account matches a legacy row — an account-wide recognition, not tied to whichever character
     * happens to be active. Safe to call repeatedly (CharacterCosmetic::firstOrCreate is idempotent). */
    public static function grantLegendTitleIfMatched(User $user): void
    {
        $discordId = $user->socialAccounts()->where('provider', 'discord')->value('provider_user_id');
        if (! $discordId || ! self::where('discord_id', $discordId)->exists()) {
            return;
        }

        $titleCosmeticId = Cosmetic::where('key', self::LEGEND_TITLE_KEY)->value('id');
        if (! $titleCosmeticId) {
            return;
        }

        foreach ($user->characters()->pluck('id') as $characterId) {
            CharacterCosmetic::firstOrCreate(
                ['character_id' => $characterId, 'cosmetic_id' => $titleCosmeticId],
                ['unlocked_at' => now()]
            );
        }
    }
}
