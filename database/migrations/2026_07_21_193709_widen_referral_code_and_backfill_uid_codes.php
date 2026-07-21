<?php

use App\Services\ReferralService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widens referral_code (base64url of an id can run longer than the old random-6 scheme) and
     * repoints every existing account's code at the new deterministic base64url(id) scheme — no rows
     * are deleted or touched beyond this one column.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY referral_code VARCHAR(24) NULL');

        DB::table('users')->orderBy('id')->select('id')->chunkById(500, function ($users) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'referral_code' => ReferralService::encodeUid($user->id),
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users MODIFY referral_code VARCHAR(10) NULL');
    }
};
