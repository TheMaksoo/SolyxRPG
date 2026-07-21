<?php

namespace Database\Seeders;

use App\Models\LegacyDiscordUser;
use Illuminate\Database\Seeder;

/**
 * Seeds legacy_discord_users from data/legacy_discord_users.tsv — 206,945 accounts recovered from a
 * 2023-07-08 mongodump of the original Discord-bot version of Solyx (that cluster no longer exists).
 * Used purely so a current player who links Discord and matches one of these old accounts gets the
 * "Legend of Solyx" title automatically (see LegacyDiscordUser::grantLegendTitleIfMatched(), called
 * from SocialiteController and CharacterController).
 *
 * Safe to re-run anywhere (dev, staging, live) — upserts by discord_id, so running it twice never
 * duplicates rows, and it's the same data file everywhere rather than a one-off local import.
 */
class LegacyDiscordUserSeeder extends Seeder
{
    private const CHUNK = 1000;

    public function run(): void
    {
        $path = __DIR__.'/data/legacy_discord_users.tsv';
        if (! is_readable($path)) {
            $this->command?->error("Missing {$path} — nothing to seed.");

            return;
        }

        $rows = [];
        $imported = 0;
        $handle = fopen($path, 'r');

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }

            [$discordId, $name] = array_pad(explode("\t", $line, 2), 2, null);
            $rows[] = ['discord_id' => $discordId, 'name' => $name];

            if (count($rows) >= self::CHUNK) {
                LegacyDiscordUser::upsert($rows, ['discord_id'], ['name']);
                $imported += count($rows);
                $rows = [];
            }
        }
        fclose($handle);

        if ($rows) {
            LegacyDiscordUser::upsert($rows, ['discord_id'], ['name']);
            $imported += count($rows);
        }

        $this->command?->info("Seeded {$imported} legacy Discord user(s).");
    }
}
