<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // True once the player has cancelled — VIP perks and vip_expires_at stay exactly as they
            // are (no early cutoff), but Stripe won't bill or renew the subscription again. See
            // VipController::cancel()/resume() and StoreController::webhook()'s subscription.deleted case.
            $table->boolean('vip_cancel_at_period_end')->default(false)->after('stripe_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('vip_cancel_at_period_end');
        });
    }
};
