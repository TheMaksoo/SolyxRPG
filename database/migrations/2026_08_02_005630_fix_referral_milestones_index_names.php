<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * ⚠️ PRODUCTION SAFETY: This migration is 100% safe for production.
     * It ONLY modifies indexes (not data). NO rows are deleted or modified.
     * NO tables are dropped. All existing data remains intact.
     * 
     * This migration fixes the index naming issue where the original referral_milestones
     * migration created auto-generated index names that were too long for MySQL.
     * It ensures the indexes have explicit short names.
     */
    public function up(): void
    {
        // Only proceed if the table exists
        if (!Schema::hasTable('referral_milestones')) {
            return;
        }

        $connection = DB::connection();
        $driver = $connection->getDriverName();

        // Get list of existing indexes
        $existingIndexes = $this->getExistingIndexes($driver);

        // Check if we already have the correctly named indexes
        $hasCorrectUniqueIndex = in_array('ref_milestone_unique', $existingIndexes);
        $hasCorrectRegularIndex = in_array('ref_level_index', $existingIndexes);

        // If both indexes are correct, nothing to do
        if ($hasCorrectUniqueIndex && $hasCorrectRegularIndex) {
            return;
        }

        Schema::table('referral_milestones', function (Blueprint $table) use ($hasCorrectUniqueIndex, $hasCorrectRegularIndex, $driver) {
            // Drop and recreate the unique index if needed
            if (!$hasCorrectUniqueIndex) {
                // Try to drop by column names (Laravel will use the auto-generated name)
                try {
                    $table->dropUnique(['referrer_id', 'referee_id', 'level_milestone']);
                } catch (\Exception $e) {
                    // Index might not exist or have a different name, that's ok
                }

                // Create with explicit name
                $table->unique(['referrer_id', 'referee_id', 'level_milestone'], 'ref_milestone_unique');
            }

            // Drop and recreate the regular index if needed
            if (!$hasCorrectRegularIndex) {
                // Try to drop by column names
                try {
                    $table->dropIndex(['referrer_id', 'level_milestone']);
                } catch (\Exception $e) {
                    // Index might not exist or have a different name, that's ok
                }

                // Create with explicit name
                $table->index(['referrer_id', 'level_milestone'], 'ref_level_index');
            }
        });
    }

    /**
     * Get existing indexes for the referral_milestones table
     */
    private function getExistingIndexes(string $driver): array
    {
        try {
            if ($driver === 'mysql') {
                $indexes = DB::select("SHOW INDEXES FROM referral_milestones");
                return array_unique(array_column($indexes, 'Key_name'));
            } elseif ($driver === 'pgsql') {
                $indexes = DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'referral_milestones'");
                return array_column($indexes, 'indexname');
            } elseif ($driver === 'sqlite') {
                $indexes = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='referral_milestones'");
                return array_column($indexes, 'name');
            }
        } catch (\Exception $e) {
            // If we can't get indexes, return empty array and let the migration try anyway
        }

        return [];
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is a fix migration, so down() should be a no-op
        // We don't want to revert to the problematic state
    }
};
