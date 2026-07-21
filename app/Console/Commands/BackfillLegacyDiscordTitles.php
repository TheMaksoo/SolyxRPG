<?php

namespace App\Console\Commands;

use App\Models\Cosmetic;
use App\Models\LegacyDiscordUser;
use App\Models\SocialAccount;
use Illuminate\Console\Command;

/** One-time (but safe to re-run) sweep granting the "Legend of Solyx" title to every CURRENTLY
 * Discord-linked account that matches the recovered legacy user list — covers anyone who linked
 * Discord before this feature existed. New links are caught live going forward (see
 * SocialiteController / CharacterController). */
class BackfillLegacyDiscordTitles extends Command
{
    protected $signature = 'app:backfill-legacy-discord-titles';

    protected $description = 'Grants the Legend of Solyx title to every already-Discord-linked account that matches the recovered legacy user list.';

    public function handle(): int
    {
        $titleCosmeticId = Cosmetic::where('key', 'title_legend')->value('id');
        abort_unless($titleCosmeticId, 500, 'title_legend cosmetic not found.');

        $accounts = SocialAccount::where('provider', 'discord')->with('user.characters')->get();
        $this->info("Checking {$accounts->count()} Discord-linked account(s) against ".LegacyDiscordUser::count().' legacy user(s)...');

        $granted = 0;
        foreach ($accounts as $account) {
            $before = $account->user->characters->flatMap->cosmetics->where('cosmetic_id', $titleCosmeticId)->count();

            LegacyDiscordUser::grantLegendTitleIfMatched($account->user);

            $after = $account->user->characters->flatMap(fn ($c) => $c->cosmetics()->where('cosmetic_id', $titleCosmeticId)->get())->count();
            if ($after > $before) {
                $granted++;
                $this->line("  Granted to user #{$account->user_id} ({$account->user->name}).");
            }
        }

        $this->info("Done. Granted to {$granted} account(s).");

        return self::SUCCESS;
    }
}
