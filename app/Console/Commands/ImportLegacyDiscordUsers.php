<?php

namespace App\Console\Commands;

use App\Models\LegacyDiscordUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** One-time import of the recovered original-Discord-bot user list (see LegacyDiscordUser) from a
 * tab-separated "discord_id\tname" file into legacy_discord_users. Safe to re-run — upserts on
 * discord_id, so nothing duplicates. */
class ImportLegacyDiscordUsers extends Command
{
    protected $signature = 'app:import-legacy-discord-users {path}';

    protected $description = 'Imports a discord_id<TAB>name file into legacy_discord_users (upserts by discord_id).';

    private const CHUNK = 1000;

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_readable($path)) {
            $this->error("Can't read {$path}.");

            return self::FAILURE;
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
                $this->output->write('.');
            }
        }
        fclose($handle);

        if ($rows) {
            LegacyDiscordUser::upsert($rows, ['discord_id'], ['name']);
            $imported += count($rows);
        }

        $this->newLine();
        $this->info("Imported/updated {$imported} legacy Discord user(s). Table now has ".DB::table('legacy_discord_users')->count().' row(s).');

        return self::SUCCESS;
    }
}
