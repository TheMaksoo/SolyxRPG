<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Battle;
use App\Models\CraftingJob;
use App\Models\DungeonRun;
use App\Models\ErrorLog;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Mail;
use App\Models\PartyInvite;
use App\Models\SupportTicket;
use Illuminate\Console\Command;

/**
 * Purges rows that exist only as transient/temp state or short-lived logs and have no gameplay reason
 * to stick around forever. Retention windows (reasoning per table):
 *
 * - battles (finished: won/lost/fled): 30 days. Only the *active* battle for a character is ever read
 *   again (BattleController/CombatService key off character_id + status=active); a finished battle's
 *   log_json is display-only history nobody re-opens after the fact.
 * - dungeon_runs (completed/abandoned): 30 days. Same story as battles — only the active run per
 *   character/dungeon is ever queried.
 * - crafting_jobs (collected_at set): 30 days. Once collected, the job row only exists for the
 *   collect() response's ->load('resultItem','recipe') the instant it happened; nothing re-reads it.
 * - party_invites: 7 days. No expiry field exists on the model, but an un-accepted/un-declined invite
 *   older than a week is dead weight — the inviting party has almost certainly moved on.
 * - mail (dismissed_at set): 60 days. Dismissed mail is deliberately hidden from InboxController's
 *   `whereNull('dismissed_at')` already; keeping it 60 days covers any "wait, what was that gem
 *   transaction mail again" support lookback without keeping it forever.
 * - support_tickets (resolved/closed): 180 days. Longer window since these are a support/audit trail a
 *   GM might need to revisit; support_ticket_messages cascade-delete with the parent ticket (FK
 *   ->cascadeOnDelete() in the migration), so no separate cleanup needed there.
 * - audit_logs: 180 days. GM action audit trail — kept much longer than gameplay logs for
 *   accountability, but still shouldn't grow forever on a table nothing archives.
 * - error_logs: 30 days. Crash reports are only useful while actively debugging a recent regression;
 *   nothing queries them past that window.
 *
 * Every delete is chunked (chunkById) rather than a single unbounded ->delete() so a cleanup run
 * against a large table doesn't itself hold a long-running lock or blow up the query log — see the
 * corresponding indexes added in 2026_08_02_000018_add_perf_indexes.php.
 */
class CleanupStaleData extends Command
{
    protected $signature = 'cleanup:stale-data';

    protected $description = 'Purges finished battles/dungeon runs/crafting jobs, stale party invites, old dismissed mail, and resolved support tickets/audit logs past their retention window.';

    private const CHUNK = 500;

    public function handle(): int
    {
        $deleted = [
            'battles' => $this->purge(
                Battle::whereIn('status', ['won', 'lost', 'fled'])->where('updated_at', '<', now()->subDays(30))
            ),
            'dungeon_runs' => $this->purge(
                DungeonRun::whereIn('status', ['completed', 'abandoned'])->where('updated_at', '<', now()->subDays(30))
            ),
            'crafting_jobs' => $this->purge(
                CraftingJob::whereNotNull('collected_at')->where('collected_at', '<', now()->subDays(30))
            ),
            'party_invites' => $this->purge(
                PartyInvite::where('created_at', '<', now()->subDays(7))
            ),
            'mail' => $this->purge(
                Mail::whereNotNull('dismissed_at')->where('dismissed_at', '<', now()->subDays(60))
            ),
            'support_tickets' => $this->purge(
                SupportTicket::whereIn('status', ['resolved', 'closed'])->where('updated_at', '<', now()->subDays(180))
            ),
            'audit_logs' => $this->purge(
                AuditLog::where('created_at', '<', now()->subDays(180))
            ),
            'crafted_item_variants' => $this->purgeOrphanedCraftedVariants(),
            'error_logs' => $this->purge(
                ErrorLog::where('created_at', '<', now()->subDays(30))
            ),
        ];

        foreach ($deleted as $table => $count) {
            $this->info("{$table}: purged {$count} row(s).");
        }

        return self::SUCCESS;
    }

    /** Every crafted-variant Item row (rolled-stat gear, key "{baseKey}_crafted_{random}") backs exactly
     * one Inventory row and is deleted the moment that row is scrapped (see InventoryController::scrap()).
     * This is the backstop for variants that went orphaned before that fix existed, or via any other path
     * that removes the Inventory row without going through scrap() — without it, `items` grows by one row
     * per craft forever. */
    private function purgeOrphanedCraftedVariants(): int
    {
        $count = 0;
        Item::where('key', 'like', '%\_crafted\_%')
            ->whereNotIn('id', Inventory::select('item_id')->distinct())
            ->chunkById(self::CHUNK, function ($rows) use (&$count) {
                $ids = $rows->pluck('id');
                // A collected CraftingJob row keeps pointing at result_item_id purely for historical
                // display and has an FK on it — once the crafted item itself is orphaned (no Inventory
                // row left), that history has nothing left to point at either, so it goes too.
                CraftingJob::whereIn('result_item_id', $ids)->delete();
                $count += $rows->count();
                Item::whereIn('id', $ids)->delete();
            });

        return $count;
    }

    /** Chunked delete so purging a large table doesn't take one long-running lock/query. */
    private function purge($query): int
    {
        $count = 0;
        $query->chunkById(self::CHUNK, function ($rows) use (&$count) {
            $count += $rows->count();
            $rows->toQuery()->delete();
        });

        return $count;
    }
}
